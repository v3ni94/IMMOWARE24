<?php

declare(strict_types=1);

namespace App\Modules\Connector\Http;

use App\Core\Exceptions\HubException;
use App\Core\Exceptions\WriteBlockedException;
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
            ->beforeSending(function (Request $request) use ($rateKey, $breakerKey): void {
                $this->guardMethod($request);
                $this->breaker->assertAvailable($breakerKey);
                $this->rateLimiter->acquire($rateKey);
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
     * Methoden-Guard: DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK, MKCOL sowie PUT ohne
     * If-None-Match: * werden unabhängig von jeder Konfiguration abgebrochen.
     *
     * @throws WriteBlockedException
     */
    public function guardMethod(Request $request): void
    {
        $method = strtoupper($request->method());
        $blocked = array_map('strtoupper', (array) $this->config->get('hub.connector.http.blocked_methods', []));

        if (in_array($method, $blocked, true)) {
            throw new WriteBlockedException(sprintf('HTTP-Methode %s ist gegenüber Immoware24 hart gesperrt.', $method), $method);
        }

        if ($method === 'PUT' && trim((string) ($request->header('If-None-Match')[0] ?? '')) !== '*') {
            throw new WriteBlockedException('PUT ohne If-None-Match: * ist gesperrt (nur create-only).', 'PUT');
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
