<?php

declare(strict_types=1);

namespace App\Modules\Connector\Http;

use App\Core\Exceptions\HubException;
use App\Core\Exceptions\RateLimitedException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Enums\RemoteRequestOutcome;
use App\Modules\Connector\Services\CircuitBreaker;
use App\Modules\Connector\Services\RateLimitManager;
use App\Modules\Connector\Services\RemoteRequestLogger;
use App\Modules\Connector\Support\ConnectorContext;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Erzeugt einen vorkonfigurierten Http-PendingRequest für eine Connection: Timeouts, kein Retry
 * (Retry macht die Sync Engine), Auth aus dem Speicherkontext, User-Agent ImmowareHub/<version>,
 * keine Redirects auf fremde Hosts, Methoden-Guard, Hooks für CircuitBreaker, RateLimitManager und
 * RemoteRequestLogger. Alle Aufrufe laufen über die Http-Facade, damit Http::fake() greift.
 */
final class HttpClientFactory
{
    public const string USER_AGENT_PREFIX = 'ImmowareHub/';

    /** Guzzle-Option: 401 ist erwartet (unauthentifizierte Auth-Challenge der Probe) und öffnet den Breaker nicht. */
    public const string OPTION_EXPECT_UNAUTHORIZED = 'hub_expect_unauthorized';

    /**
     * Gegenüber Immoware24 hart gesperrte Methoden. Fest verdrahtet, keine Konfigurationsoption
     * (05-write-capabilities.md 2.3, 01-architecture-decision.md Abschnitt 6; Änderungsvermerk 12.09.2026).
     */
    public const array BLOCKED_METHODS = ['DELETE', 'MOVE', 'COPY', 'PROPPATCH', 'LOCK', 'UNLOCK', 'MKCOL', 'POST', 'PATCH'];

    /** CardDAV und CalDAV sind ausschließlich lesend: nur diese Methoden passieren den Guard. */
    public const array DAV_READ_METHODS = ['OPTIONS', 'PROPFIND', 'REPORT', 'GET', 'HEAD'];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RateLimitManager $rateLimiter,
        private readonly CircuitBreaker $breaker,
        private readonly RemoteRequestLogger $logger,
    ) {}

    public function userAgent(): string
    {
        return self::USER_AGENT_PREFIX.(string) $this->config->get('hub.connector.version', '0.1.0');
    }

    /**
     * @param  string  $channel  read oder write; bestimmt Rate-Limit- und Breaker-Schlüssel
     * @param  bool  $withAuth  false für die unauthentifizierte Auth-Challenge der Probe
     */
    public function for(ConnectorContext $context, string $channel = 'read', bool $withAuth = true, bool $download = false): PendingRequest
    {
        $rateKey = RateLimitManager::keyFor($context->connectionId, $channel);
        $breakerKey = CircuitBreaker::keyFor($context->connectionId, $channel);
        $this->rateLimiter->configure($rateKey, $context->rateLimitRps, $context->maxConcurrency);

        $timeout = (int) $this->config->get($download ? 'hub.connector.http.download_timeout_seconds' : 'hub.connector.http.timeout_seconds', 60);

        $request = Http::withUserAgent($this->userAgent())
            ->connectTimeout((int) $this->config->get('hub.connector.http.connect_timeout_seconds', 10))
            ->timeout($timeout)
            ->withOptions(['allow_redirects' => false, 'http_errors' => false, self::OPTION_EXPECT_UNAUTHORIZED => ! $withAuth])
            ->withMiddleware($this->observationMiddleware($context, $channel, $rateKey, $breakerKey))
            ->beforeSending(function (Request $request) use ($context, $rateKey, $breakerKey): void {
                $this->guardMethod($request, $context);
                $this->breaker->assertAvailable($breakerKey);

                try {
                    $this->rateLimiter->acquire($rateKey);
                } catch (RateLimitedException $e) {
                    // Kein Request gesendet: ein im half_open reservierter Testrequest wird wieder freigegeben.
                    $this->breaker->abortTrial($breakerKey);

                    throw $e;
                }
            });

        if ($context->baseUrl !== null) {
            $request = $request->baseUrl(rtrim($context->baseUrl, '/'));
        }

        if ($withAuth && ! $context->credentials->isEmpty()) {
            $request = $context->authScheme === 'digest'
                ? $request->withDigestAuth($context->credentials->username, $context->credentials->password())
                : $request->withBasicAuth($context->credentials->username, $context->credentials->password());
        }

        return $request;
    }

    /**
     * Methoden-Guard, unabhängig von jeder Konfiguration (05-write-capabilities.md 2.3):
     * 1. BLOCKED_METHODS werden immer abgebrochen.
     * 2. CardDAV- und CalDAV-Kontexte lassen nur lesende Methoden passieren.
     * 3. PUT nur mit If-None-Match: *, nur aus einer Connection mit purpose write und nur auf Pfade unterhalb
     *    des Schreibpräfixes der Connection (allowed_write_prefix).
     *
     * @throws WriteBlockedException
     */
    public function guardMethod(Request $request, ?ConnectorContext $context = null): void
    {
        $method = strtoupper($request->method());

        if (in_array($method, self::BLOCKED_METHODS, true)) {
            throw new WriteBlockedException(sprintf('HTTP-Methode %s ist gegenüber Immoware24 hart gesperrt.', $method), $method);
        }

        if ($context !== null && in_array($context->type, [ConnectorType::CardDav, ConnectorType::CalDav], true) && ! in_array($method, self::DAV_READ_METHODS, true)) {
            throw new WriteBlockedException(sprintf('HTTP-Methode %s ist für %s hart gesperrt (nur lesend).', $method, $context->type->label()), $method);
        }

        if ($method !== 'PUT') {
            return;
        }

        if (trim((string) ($request->header('If-None-Match')[0] ?? '')) !== '*') {
            throw new WriteBlockedException('PUT ohne If-None-Match: * ist gesperrt (nur create-only).', 'PUT');
        }

        if ($context === null) {
            return;
        }

        if ($context->purpose !== 'write') {
            throw new WriteBlockedException('PUT ist nur über eine Connection mit purpose write zulässig.', 'PUT');
        }

        $prefix = $context->allowedWritePrefix !== null ? '/'.trim($context->allowedWritePrefix, '/').'/' : null;

        if ($prefix === null || $prefix === '//') {
            throw new WriteBlockedException('PUT ohne Schreibpräfix der Connection (allowed_write_prefix) ist gesperrt.', 'PUT');
        }

        $path = parse_url($request->url(), PHP_URL_PATH);
        $path = rawurldecode(is_string($path) ? $path : '/');
        $basePath = $context->basePath();

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $path = '/'.ltrim($path, '/');

        if (str_contains($path, '/../') || str_ends_with($path, '/..') || ! str_starts_with($path, $prefix) || $path === $prefix) {
            throw new WriteBlockedException(sprintf('PUT außerhalb des Schreibpräfixes %s ist gesperrt.', $prefix), 'PUT');
        }
    }

    /**
     * Guzzle-Middleware: misst Dauer, gibt den Concurrency-Slot frei, meldet Status an Breaker und
     * Rate Limiter und protokolliert den Request in remote_requests. Läuft auch bei Http::fake().
     *
     * @return callable(callable): callable
     */
    private function observationMiddleware(ConnectorContext $context, string $channel, string $rateKey, string $breakerKey): callable
    {
        return function (callable $handler) use ($context, $channel, $rateKey, $breakerKey): callable {
            return function (RequestInterface $request, array $options) use ($handler, $context, $channel, $rateKey, $breakerKey): PromiseInterface {
                $start = hrtime(true);
                $requestBytes = $request->getBody()->getSize();

                $expectUnauthorized = (bool) ($options[self::OPTION_EXPECT_UNAUTHORIZED] ?? false);

                $finish = function (?ResponseInterface $response, ?Throwable $error) use ($request, $start, $requestBytes, $context, $channel, $rateKey, $breakerKey, $expectUnauthorized): void {
                    $durationMs = (int) ((hrtime(true) - $start) / 1_000_000);
                    $status = $response?->getStatusCode();
                    $timedOut = $error !== null && str_contains(strtolower($error->getMessage()), 'time');
                    $body = null;

                    if ($response !== null && $response->getBody()->isSeekable()) {
                        $body = (string) $response->getBody();
                        $response->getBody()->rewind();
                    }

                    $this->rateLimiter->release($rateKey);
                    $this->rateLimiter->reportResponse($rateKey, $status, $durationMs, $timedOut);
                    if (! ($expectUnauthorized && $status === 401)) {
                        $this->breaker->recordStatus($breakerKey, $status, $timedOut, $response !== null && $response->hasHeader('Retry-After'));
                    }

                    $this->logger->record(
                        connectionId: $context->connectionId,
                        connectorName: $context->type->value.':'.$channel,
                        method: $request->getMethod(),
                        url: (string) $request->getUri(),
                        status: $status,
                        durationMs: $durationMs,
                        requestHeaders: $request->getHeaders(),
                        responseHeaders: $response?->getHeaders() ?? [],
                        requestBytes: $requestBytes,
                        responseBytes: $body !== null ? strlen($body) : null,
                        responseBody: $body,
                        error: $error,
                        outcome: $timedOut ? RemoteRequestOutcome::Timeout : null,
                    );
                };

                try {
                    $promise = $handler($request, $options);
                } catch (HubException $e) {
                    // Hub-seitige Ablehnung vor dem Senden (Methoden-Guard, Breaker, Rate Limit): kein Slot belegt,
                    // kein Serverfehler. Nur als blocked protokollieren.
                    $this->logger->record(
                        connectionId: $context->connectionId,
                        connectorName: $context->type->value.':'.$channel,
                        method: $request->getMethod(),
                        url: (string) $request->getUri(),
                        status: null,
                        durationMs: 0,
                        requestHeaders: $request->getHeaders(),
                        requestBytes: $requestBytes,
                        error: $e,
                        outcome: RemoteRequestOutcome::Blocked,
                    );

                    throw $e;
                } catch (Throwable $e) {
                    // Synchron geworfene Transportfehler (z. B. Http::fake mit Exception) ebenfalls protokollieren.
                    $finish(null, $e);

                    throw $e;
                }

                return $promise->then(
                    static function (ResponseInterface $response) use ($finish): ResponseInterface {
                        $finish($response, null);

                        return $response;
                    },
                    static function (Throwable $reason) use ($finish): PromiseInterface {
                        $finish(null, $reason);

                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }
}
