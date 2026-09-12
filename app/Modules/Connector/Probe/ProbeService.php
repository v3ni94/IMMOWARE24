<?php

declare(strict_types=1);

namespace App\Modules\Connector\Probe;

use App\Core\DTO\CheckResult;
use App\Core\DTO\ConnectionResult;
use App\Core\Enums\CapabilityStatus;
use App\Core\Enums\CheckStatus;
use App\Core\Exceptions\CircuitOpenException;
use App\Core\Exceptions\RateLimitedException;
use App\Core\Support\SecretMasker;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Enums\SyncStrategy;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\CapabilityRegistry;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Connector\Support\DavMultistatusParser;
use App\Modules\Connector\Support\DavResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Capability-Test einer Connection (docs/immoware/07-sync-strategy.md Abschnitt 2.1, ohne Schreibtest):
 * Auth-Challenge, OPTIONS, PROPFIND Depth 0 und 1, ETag-Stabilität, sync-collection REPORT.
 * If-None-Match wird nicht durch Schreiben geprüft, nur über DAV-Header eingeschätzt.
 * Ergebnis: capabilities (tested oder unavailable), probe_result, server_fingerprint, Strategie.
 */
final class ProbeService
{
    public const string CHECK_AUTH_CHALLENGE = 'auth_challenge';

    public const string CHECK_OPTIONS = 'options';

    public const string CHECK_AUTHENTICATION = 'authentication';

    public const string CHECK_PROPFIND_DEPTH1 = 'propfind_depth1';

    public const string CHECK_ETAG_STABILITY = 'etag_stability';

    public const string CHECK_SYNC_COLLECTION = 'sync_collection_report';

    public const string CHECK_CONDITIONAL = 'conditional_requests';

    public const string CHECK_NOT_APPLICABLE = 'not_applicable';

    private const string PROPFIND_BODY = '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:propfind xmlns:d="DAV:" xmlns:cs="http://calendarserver.org/ns/">'
        .'<d:prop><d:resourcetype/><d:getetag/><d:getlastmodified/><d:getcontentlength/>'
        .'<d:supported-report-set/><d:sync-token/><cs:getctag/><d:displayname/></d:prop>'
        .'</d:propfind>';

    private const string SYNC_COLLECTION_BODY = '<?xml version="1.0" encoding="utf-8"?>'
        .'<d:sync-collection xmlns:d="DAV:"><d:sync-token/><d:sync-level>1</d:sync-level>'
        .'<d:prop><d:getetag/></d:prop></d:sync-collection>';

    public function __construct(
        private readonly ConnectorManager $manager,
        private readonly HttpClientFactory $http,
        private readonly CapabilityRegistry $registry,
        private readonly DavMultistatusParser $parser,
        private readonly SecretMasker $masker,
        private readonly ConfigRepository $config,
    ) {}

    public static function label(string $check): string
    {
        return match ($check) {
            self::CHECK_AUTH_CHALLENGE => 'Auth-Challenge (OPTIONS)',
            self::CHECK_OPTIONS => 'OPTIONS / DAV-Header',
            self::CHECK_AUTHENTICATION => 'Authentifizierung (PROPFIND 0)',
            self::CHECK_PROPFIND_DEPTH1 => 'PROPFIND Depth 1',
            self::CHECK_ETAG_STABILITY => 'ETag-Stabilität',
            self::CHECK_SYNC_COLLECTION => 'REPORT sync-collection',
            self::CHECK_CONDITIONAL => 'If-None-Match (nur Header)',
            self::CHECK_NOT_APPLICABLE => 'Probe',
            default => $check,
        };
    }

    public function run(ImmowareConnection $connection, ?int $testedBy = null, ?int $etagDelaySeconds = null): ProbeReport
    {
        $context = $this->manager->contextFor($connection);
        $connectionId = (int) $connection->getKey();

        Log::info('Probe gestartet.', $context->toLogContext());

        $this->registry->ensureHardLocks($connectionId);

        $report = match ($context->type) {
            ConnectorType::WebDav, ConnectorType::CardDav, ConnectorType::CalDav => $this->probeDav($context, $etagDelaySeconds),
            ConnectorType::FileImport => $this->probeFileImport($context),
            ConnectorType::RestApiSlot => $this->probeRestApiSlot($context),
        };

        $this->recordCapabilities($context, $report, $testedBy);
        $this->persist($connection, $report);

        Log::info('Probe beendet.', ['connection_id' => $connectionId, 'ok' => $report->result->ok, 'strategy' => $report->strategy?->value]);

        return $report;
    }

    private function probeDav(ConnectorContext $context, ?int $etagDelaySeconds): ProbeReport
    {
        /** @var array<string, CheckResult> $checks */
        $checks = [];
        $facts = [
            'auth_scheme_detected' => null,
            'realm' => null,
            'server' => null,
            'dav_classes' => [],
            'allow' => [],
            'supported_reports' => [],
            'sync_token_present' => false,
            'sync_token_supported' => false,
            'ctag_present' => false,
            'etag_present' => false,
            'etag_stable' => false,
            'lastmodified_size_present' => false,
            'depth1_entries' => 0,
        ];

        if ($context->baseUrl === null) {
            $checks[self::CHECK_AUTHENTICATION] = CheckResult::failed('Keine Freigabe-URL hinterlegt.');

            return $this->finish($context, $checks, $facts, false);
        }

        // 1. Unauthentifizierte Auth-Challenge
        $checks[self::CHECK_AUTH_CHALLENGE] = $this->guard(function () use ($context, &$facts): CheckResult {
            $response = $this->send($this->http->for($context, 'read', false), 'OPTIONS', '');
            $this->collectServerFacts($response, $facts);

            if ($response->status() === 401) {
                $challenge = (string) $response->header('WWW-Authenticate');
                $facts['auth_scheme_detected'] = $this->detectScheme($challenge);
                $facts['realm'] = $this->detectRealm($challenge);

                return CheckResult::ok(sprintf('401 mit Schema %s', $facts['auth_scheme_detected'] ?? 'unbekannt'), $this->latency($response));
            }

            if ($response->successful()) {
                return new CheckResult(CheckStatus::Unknown, sprintf('%d ohne Authentifizierung, Freigabe prüfen', $response->status()), $this->latency($response));
            }

            return new CheckResult(CheckStatus::Skipped, sprintf('Status %d ohne WWW-Authenticate', $response->status()), $this->latency($response));
        });

        // 2. OPTIONS authentifiziert
        $optionsStatus = null;
        $checks[self::CHECK_OPTIONS] = $this->guard(function () use ($context, &$facts, &$optionsStatus): CheckResult {
            $response = $this->send($this->http->for($context), 'OPTIONS', '');
            $this->collectServerFacts($response, $facts);
            $optionsStatus = $response->status();

            if ($response->successful()) {
                return CheckResult::ok(
                    $facts['dav_classes'] !== [] ? 'DAV: '.implode(', ', $facts['dav_classes']) : 'kein DAV-Header',
                    $this->latency($response),
                );
            }

            return $this->failureFor($response);
        });

        // 3. PROPFIND Depth 0. Nach einem 401 auf OPTIONS kein weiterer Versuch (Breaker offen, Sperrgefahr).
        $rootResponses = [];
        $checks[self::CHECK_AUTHENTICATION] = $optionsStatus === 401 ? $checks[self::CHECK_OPTIONS] : $this->guard(function () use ($context, &$facts, &$rootResponses): CheckResult {
            $response = $this->send($this->http->for($context), 'PROPFIND', '', ['Depth' => '0'], self::PROPFIND_BODY);
            $this->collectServerFacts($response, $facts);

            if (! in_array($response->status(), [200, 207], true)) {
                return $this->failureFor($response);
            }

            $rootResponses = $this->parser->parse($response->body());
            $root = $rootResponses[0] ?? null;

            if ($root !== null) {
                $facts['supported_reports'] = $root->supportedReports;
                $facts['sync_token_present'] = $root->syncToken !== null;
                $facts['ctag_present'] = $root->ctag !== null;
            }

            return CheckResult::ok(sprintf('%d, %s', $response->status(), $root?->isCollection ? 'Collection' : 'Ressource'), $this->latency($response));
        });

        $authenticated = $checks[self::CHECK_AUTHENTICATION]->isOk();

        // 4. PROPFIND Depth 1
        $firstDepth1 = [];
        $checks[self::CHECK_PROPFIND_DEPTH1] = ! $authenticated
            ? CheckResult::skipped('übersprungen, Authentifizierung fehlgeschlagen')
            : $this->guard(function () use ($context, &$facts, &$firstDepth1): CheckResult {
                $response = $this->send($this->http->for($context), 'PROPFIND', '', ['Depth' => '1'], self::PROPFIND_BODY);

                if (! in_array($response->status(), [200, 207], true)) {
                    return $this->failureFor($response);
                }

                $firstDepth1 = $this->limitEntries($this->parser->parse($response->body()));
                $members = array_slice($firstDepth1, 1);
                $facts['depth1_entries'] = count($members);
                $facts['etag_present'] = $members !== [] && count(array_filter($members, static fn (DavResponse $r): bool => $r->etag !== null)) === count($members);
                $facts['lastmodified_size_present'] = $members !== [] && count(array_filter($members, static fn (DavResponse $r): bool => $r->lastModified !== null && ($r->contentLength !== null || $r->isCollection))) === count($members);

                return CheckResult::ok(sprintf('%d Einträge, ETags %s', count($members), $facts['etag_present'] ? 'vorhanden' : 'fehlen'), $this->latency($response));
            });

        // 5. ETag-Stabilität
        $checks[self::CHECK_ETAG_STABILITY] = match (true) {
            ! $checks[self::CHECK_PROPFIND_DEPTH1]->isOk() => CheckResult::skipped('übersprungen'),
            ! $facts['etag_present'] => CheckResult::skipped('keine ETags, Strategie ohne ETag'),
            default => $this->guard(function () use ($context, &$facts, $firstDepth1, $etagDelaySeconds): CheckResult {
                $delay = $etagDelaySeconds ?? (int) $this->config->get('hub.connector.probe.etag_delay_seconds', 60);

                if ($delay > 0) {
                    Sleep::for($delay)->seconds();
                }

                $response = $this->send($this->http->for($context), 'PROPFIND', '', ['Depth' => '1'], self::PROPFIND_BODY);

                if (! in_array($response->status(), [200, 207], true)) {
                    return $this->failureFor($response);
                }

                $second = $this->limitEntries($this->parser->parse($response->body()));
                $diff = $this->etagDifferences($firstDepth1, $second);

                if ($diff === 0) {
                    $facts['etag_stable'] = true;

                    return CheckResult::ok(sprintf('identisch über %d Einträge', max(0, count($firstDepth1) - 1)), $this->latency($response));
                }

                return CheckResult::failed(sprintf('%d abweichende ETags', $diff), $this->latency($response));
            }),
        };

        // 6. REPORT sync-collection
        $checks[self::CHECK_SYNC_COLLECTION] = ! $authenticated
            ? CheckResult::skipped('übersprungen')
            : $this->guard(function () use ($context, &$facts): CheckResult {
                $response = $this->send($this->http->for($context), 'REPORT', '', ['Depth' => '0'], self::SYNC_COLLECTION_BODY);

                if ($response->status() === 207 && $this->parser->isMultistatus($response->body())) {
                    $token = $this->parser->rootSyncToken($response->body());
                    $facts['sync_token_supported'] = $token !== null || in_array('sync-collection', $facts['supported_reports'], true);

                    return $facts['sync_token_supported']
                        ? CheckResult::ok('207, sync-token geliefert', $this->latency($response))
                        : new CheckResult(CheckStatus::Disabled, '207 ohne sync-token', $this->latency($response));
                }

                if (in_array($response->status(), [400, 403, 405, 415, 501], true)) {
                    return new CheckResult(CheckStatus::Disabled, sprintf('%d, nicht unterstützt', $response->status()), $this->latency($response));
                }

                return $this->failureFor($response);
            });

        // 7. If-None-Match: keine Schreibprüfung, nur Einschätzung aus DAV-Header
        $checks[self::CHECK_CONDITIONAL] = $facts['dav_classes'] !== []
            ? CheckResult::skipped('nicht durch Schreiben geprüft, DAV-Klasse '.implode('/', $facts['dav_classes']).' vorhanden')
            : CheckResult::unknown('nicht durch Schreiben geprüft, kein DAV-Header');

        return $this->finish($context, $checks, $facts, $authenticated);
    }

    private function probeFileImport(ConnectorContext $context): ProbeReport
    {
        $checks = [self::CHECK_NOT_APPLICABLE => CheckResult::skipped('Dateiimport erzeugt keine Requests an Immoware24')];

        return new ProbeReport($context->connectionId, $context->type->value, ConnectionResult::fromChecks($checks), ['strategy_reason' => 'file_import'], SyncStrategy::FileSnapshot, null, false);
    }

    private function probeRestApiSlot(ConnectorContext $context): ProbeReport
    {
        $checks = [self::CHECK_NOT_APPLICABLE => CheckResult::disabled('Keine Immoware24-API, Status WAITING_FOR_VENDOR_ACCESS')];

        return new ProbeReport($context->connectionId, $context->type->value, ConnectionResult::fromChecks($checks), ['status' => 'WAITING_FOR_VENDOR_ACCESS'], null, null, false);
    }

    /**
     * @param  array<string, CheckResult>  $checks
     * @param  array<string, mixed>  $facts
     */
    private function finish(ConnectorContext $context, array $checks, array $facts, bool $authenticated): ProbeReport
    {
        $strategy = $authenticated
            ? SyncStrategy::choose(
                (bool) $facts['sync_token_supported'],
                (bool) $facts['ctag_present'],
                (bool) $facts['etag_stable'],
                (bool) $facts['lastmodified_size_present'],
            )
            : null;

        $fingerprint = $authenticated ? $this->fingerprint($facts) : null;

        return new ProbeReport(
            $context->connectionId,
            $context->type->value,
            ConnectionResult::fromChecks($checks),
            $facts,
            $strategy,
            $fingerprint,
            false,
        );
    }

    private function recordCapabilities(ConnectorContext $context, ProbeReport $report, ?int $testedBy): void
    {
        $protocol = json_encode($report->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $protocol = $protocol === false ? null : $protocol;

        $readKey = match ($context->type) {
            ConnectorType::WebDav => 'documents.read',
            ConnectorType::CardDav => 'contacts.read',
            ConnectorType::CalDav => 'calendar.read',
            default => null,
        };

        if ($readKey !== null) {
            $readOk = ($report->result->checks[self::CHECK_AUTHENTICATION]?->isOk() ?? false)
                && ($report->result->checks[self::CHECK_PROPFIND_DEPTH1]?->isOk() ?? false);

            $this->registry->recordTestResult($context->connectionId, $readKey, $readOk ? CapabilityStatus::Tested : CapabilityStatus::Unavailable, $protocol, $testedBy);
        }

        if ($context->type === ConnectorType::RestApiSlot) {
            foreach (['cases.read', 'cases.write'] as $key) {
                $this->registry->recordTestResult($context->connectionId, $key, CapabilityStatus::WaitingForVendorAccess, $protocol, $testedBy);
            }
        }
    }

    private function persist(ImmowareConnection $connection, ProbeReport $report): void
    {
        $previous = $connection->getAttribute('server_fingerprint');
        $changed = is_string($previous) && $previous !== '' && $report->serverFingerprint !== null && $previous !== $report->serverFingerprint;

        $stored = $report->toArray();
        $stored['fingerprint_changed'] = $changed;
        $stored['ran_at'] = CarbonImmutable::now()->toIso8601String();
        $stored['etag_stable'] = (bool) ($report->facts['etag_stable'] ?? false);
        $stored['sync_token_supported'] = (bool) ($report->facts['sync_token_supported'] ?? false);

        $attributes = [
            'probe_result' => $stored,
            'last_probe_at' => CarbonImmutable::now(),
        ];

        if ($report->serverFingerprint !== null) {
            $attributes['server_fingerprint'] = $report->serverFingerprint;
        }

        if ($changed) {
            $attributes['status'] = 'degraded';
            $attributes['degraded_reason'] = 'server_fingerprint_changed';
            Log::warning('Server-Fingerprint geändert, Connection degraded.', ['connection_id' => $connection->getKey()]);
        }

        $connection->forceFill($attributes)->save();
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function fingerprint(array $facts): string
    {
        $reports = $facts['supported_reports'];
        sort($reports);

        return hash('sha256', json_encode([
            'dav' => $facts['dav_classes'],
            'reports' => $reports,
            'server' => $facts['server'],
            'auth' => $facts['auth_scheme_detected'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(PendingRequest $request, string $method, string $path, array $headers = [], ?string $body = null): Response
    {
        $request = $request->withHeaders($headers);

        if ($body !== null) {
            $request = $request->withBody($body, 'application/xml; charset=utf-8');
        }

        return $request->send($method, $path);
    }

    /**
     * @param  callable(): CheckResult  $callback
     */
    private function guard(callable $callback): CheckResult
    {
        try {
            return $callback();
        } catch (CircuitOpenException $e) {
            return CheckResult::failed('Circuit Breaker offen: '.$this->masker->maskString($e->getMessage()));
        } catch (RateLimitedException $e) {
            return CheckResult::failed('Rate Limit: '.$this->masker->maskString($e->getMessage()));
        } catch (ConnectionException $e) {
            return CheckResult::failed('Verbindungsfehler: '.$this->masker->maskString($e->getMessage()));
        } catch (Throwable $e) {
            return CheckResult::failed($e::class.': '.$this->masker->maskString($e->getMessage()));
        }
    }

    private function failureFor(Response $response): CheckResult
    {
        $status = $response->status();
        $latency = $this->latency($response);

        return match (true) {
            $status === 401 => CheckResult::failed('401, Zugangsdaten abgelehnt', $latency),
            $status === 403 => CheckResult::failed('403, Zugriff verweigert (Freigabe oder Rolle prüfen)', $latency),
            $status === 404 => CheckResult::failed('404, Freigabe-URL nicht gefunden', $latency),
            $status === 405 => CheckResult::failed('405, Methode nicht erlaubt', $latency),
            $status === 429 => CheckResult::failed('429, Server drosselt, Rate halbiert', $latency),
            $status >= 500 => CheckResult::failed(sprintf('%d, Serverfehler', $status), $latency),
            default => CheckResult::failed(sprintf('Status %d', $status), $latency),
        };
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function collectServerFacts(Response $response, array &$facts): void
    {
        $dav = (string) $response->header('DAV');

        if ($dav !== '') {
            $facts['dav_classes'] = array_values(array_filter(array_map('trim', explode(',', $dav)), static fn (string $v): bool => $v !== ''));
        }

        $allow = (string) $response->header('Allow');

        if ($allow !== '') {
            $facts['allow'] = array_values(array_filter(array_map(static fn (string $v): string => strtoupper(trim($v)), explode(',', $allow))));
        }

        $server = (string) $response->header('Server');

        if ($server !== '') {
            $facts['server'] = substr($server, 0, 120);
        }
    }

    private function detectScheme(string $challenge): ?string
    {
        $lower = strtolower($challenge);

        return match (true) {
            str_starts_with($lower, 'digest') => 'digest',
            str_starts_with($lower, 'basic') => 'basic',
            $lower === '' => null,
            default => 'other',
        };
    }

    private function detectRealm(string $challenge): ?string
    {
        return preg_match('/realm="([^"]*)"/i', $challenge, $m) === 1 ? substr($m[1], 0, 120) : null;
    }

    /**
     * @param  array<int, DavResponse>  $first
     * @param  array<int, DavResponse>  $second
     */
    private function etagDifferences(array $first, array $second): int
    {
        $map = static function (array $responses): array {
            $result = [];

            foreach ($responses as $response) {
                $result[$response->href] = $response->etag;
            }

            return $result;
        };

        $a = $map($first);
        $b = $map($second);
        $diff = 0;

        foreach ($a as $href => $etag) {
            if (! array_key_exists($href, $b) || $b[$href] !== $etag) {
                $diff++;
            }
        }

        return $diff + count(array_diff_key($b, $a));
    }

    /**
     * @param  array<int, DavResponse>  $responses
     * @return array<int, DavResponse>
     */
    private function limitEntries(array $responses): array
    {
        return array_slice($responses, 0, (int) $this->config->get('hub.connector.probe.max_depth1_entries', 500) + 1);
    }

    private function latency(Response $response): ?int
    {
        $stats = $response->handlerStats();
        $total = $stats['total_time'] ?? null;

        return is_numeric($total) ? (int) round((float) $total * 1000) : null;
    }
}
