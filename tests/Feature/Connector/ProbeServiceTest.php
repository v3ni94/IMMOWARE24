<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Core\Enums\CapabilityStatus;
use App\Core\Enums\CheckStatus;
use App\Modules\Connector\Enums\CircuitState;
use App\Modules\Connector\Enums\SyncStrategy;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\RemoteRequest;
use App\Modules\Connector\Probe\ProbeService;
use App\Modules\Connector\Services\CircuitBreaker;
use App\Modules\Connector\Services\RateLimitManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProbeServiceTest extends TestCase
{
    use DavFixtures;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function connection(array $attributes = []): ImmowareConnection
    {
        return $this->createConnection(null, array_merge(['rate_limit_rps' => 50, 'status' => 'active'], $attributes));
    }

    private function fakeHealthyServer(string $secondDepth1Etag = 'a1', bool $withSyncToken = true, int $reportStatus = 207): void
    {
        $depth1Calls = 0;

        Http::fake(function (Request $request) use (&$depth1Calls, $secondDepth1Etag, $withSyncToken, $reportStatus) {
            $headers = $this->davHeaders();

            if (! $request->hasHeader('Authorization')) {
                return Http::response('', 401, $headers + ['WWW-Authenticate' => 'Basic realm="Immoware24 DAV"']);
            }

            return match ($request->method()) {
                'OPTIONS' => Http::response('', 200, $headers + ['Allow' => 'OPTIONS, GET, HEAD, PROPFIND, REPORT, PUT']),
                'PROPFIND' => (string) $request->header('Depth')[0] === '0'
                    ? Http::response($this->multistatus([], true, $withSyncToken, $withSyncToken), 207, $headers)
                    : Http::response($this->multistatus(['/share/a.pdf' => ++$depth1Calls === 1 ? 'a1' : $secondDepth1Etag, '/share/b.pdf' => 'b1']), 207, $headers),
                'REPORT' => $reportStatus === 207
                    ? Http::response($this->syncCollectionReport(), 207, $headers)
                    : Http::response('', $reportStatus, $headers),
                default => Http::response('', 405, $headers),
            };
        });
    }

    public function test_successful_probe_persists_strategy_fingerprint_and_capabilities(): void
    {
        $this->fakeHealthyServer();
        $connection = $this->connection();

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);

        $this->assertTrue($report->result->ok, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->assertSame(SyncStrategy::SyncToken, $report->strategy);
        $this->assertTrue($report->facts['etag_stable']);
        $this->assertTrue($report->facts['sync_token_supported']);
        $this->assertSame('basic', $report->facts['auth_scheme_detected']);
        $this->assertSame(['1', '2', '3'], $report->facts['dav_classes']);
        $this->assertSame(CheckStatus::Skipped, $report->result->checks[ProbeService::CHECK_CONDITIONAL]->status, 'If-None-Match wird nicht durch Schreiben geprüft');

        $connection->refresh();
        $this->assertNotNull($connection->getAttribute('server_fingerprint'));
        $this->assertNotNull($connection->getAttribute('last_probe_at'));
        $this->assertSame('sync_token', $connection->getAttribute('probe_result')['strategy']);
        $this->assertTrue($connection->getAttribute('probe_result')['etag_stable']);
        $this->assertTrue($connection->getAttribute('probe_result')['sync_token_supported']);
        $this->assertSame('active', $connection->getAttribute('status'));

        $read = Capability::query()->where('connection_id', $connection->getKey())->where('capability_key', 'documents.read')->firstOrFail();
        $this->assertSame(CapabilityStatus::Tested, $read->getAttribute('evidence_status'));
        $this->assertTrue((bool) $read->getAttribute('enabled'));

        foreach (['documents.delete', 'documents.move'] as $key) {
            $locked = Capability::query()->where('connection_id', $connection->getKey())->where('capability_key', $key)->firstOrFail();
            $this->assertTrue((bool) $locked->getAttribute('hard_locked'));
            $this->assertFalse((bool) $locked->getAttribute('enabled'));
        }

        // Keine Passwörter im Protokoll und in remote_requests
        $this->assertStringNotContainsString('test-secret', (string) $read->getAttribute('test_protocol'));
        $this->assertGreaterThanOrEqual(6, RemoteRequest::query()->count());
        RemoteRequest::query()->lazyById()->each(function (RemoteRequest $r): void {
            $this->assertStringNotContainsString('test-secret', json_encode($r->getAttributes(), JSON_THROW_ON_ERROR));
        });

        // Kein Schreibzugriff durch die Probe
        Http::assertNotSent(fn (Request $r): bool => in_array($r->method(), ['PUT', 'DELETE', 'MOVE', 'PROPPATCH'], true));
    }

    public function test_unstable_etags_and_missing_report_lead_to_lastmodified_strategy(): void
    {
        $this->fakeHealthyServer('a2', false, 405);
        $connection = $this->connection();

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);

        $this->assertSame(CheckStatus::Failed, $report->result->checks[ProbeService::CHECK_ETAG_STABILITY]->status);
        $this->assertSame(CheckStatus::Disabled, $report->result->checks[ProbeService::CHECK_SYNC_COLLECTION]->status);
        $this->assertStringContainsString('405', $report->result->checks[ProbeService::CHECK_SYNC_COLLECTION]->message);
        $this->assertFalse($report->facts['sync_token_supported']);
        $this->assertSame(SyncStrategy::LastModifiedSizeHash, $report->strategy);
        $this->assertFalse($report->result->ok);
    }

    public function test_401_marks_authentication_failed_skips_rest_and_opens_breaker(): void
    {
        Http::fake(fn () => Http::response('', 401, ['WWW-Authenticate' => 'Digest realm="dav", nonce="x"']));
        $connection = $this->connection();

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);

        $this->assertFalse($report->result->ok);
        $this->assertSame('digest', $report->facts['auth_scheme_detected']);
        $this->assertSame(CheckStatus::Failed, $report->result->checks[ProbeService::CHECK_AUTHENTICATION]->status);
        $this->assertStringContainsString('401', $report->result->checks[ProbeService::CHECK_AUTHENTICATION]->message);
        $this->assertSame(CheckStatus::Skipped, $report->result->checks[ProbeService::CHECK_PROPFIND_DEPTH1]->status);
        $this->assertNull($report->strategy);

        $this->assertSame(CircuitState::Open, $this->app->make(CircuitBreaker::class)->state(CircuitBreaker::keyFor((int) $connection->getKey())));

        $read = Capability::query()->where('connection_id', $connection->getKey())->where('capability_key', 'documents.read')->firstOrFail();
        $this->assertSame(CapabilityStatus::Unavailable, $read->getAttribute('evidence_status'));
        $this->assertFalse((bool) $read->getAttribute('enabled'));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function clientErrorProvider(): array
    {
        return [
            '403' => [403, 'Zugriff verweigert'],
            '404' => [404, 'nicht gefunden'],
        ];
    }

    #[DataProvider('clientErrorProvider')]
    public function test_403_and_404_are_reported_without_opening_breaker(int $status, string $expected): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('Authorization') ? Http::response('', $status) : Http::response('', 401, ['WWW-Authenticate' => 'Basic']));
        $connection = $this->connection();

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);

        $this->assertFalse($report->result->ok);
        $this->assertStringContainsString($expected, $report->result->checks[ProbeService::CHECK_AUTHENTICATION]->message);
        $this->assertSame(CircuitState::Closed, $this->app->make(CircuitBreaker::class)->state(CircuitBreaker::keyFor((int) $connection->getKey())));
    }

    public function test_429_throttles_rate_limiter(): void
    {
        Http::fake(fn (Request $r) => $r->hasHeader('Authorization') ? Http::response('', 429, ['Retry-After' => '60']) : Http::response('', 401, ['WWW-Authenticate' => 'Basic']));
        $connection = $this->connection();

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);

        $this->assertStringContainsString('429', $report->result->checks[ProbeService::CHECK_AUTHENTICATION]->message);
        $limiter = $this->app->make(RateLimitManager::class);
        $key = RateLimitManager::keyFor((int) $connection->getKey());
        $this->assertTrue($limiter->isThrottled($key));
        $this->assertGreaterThanOrEqual(1, $limiter->metrics($key)['429_count']);
        $this->assertSame(25.0, $limiter->effectiveRps($key));
    }

    public function test_changed_fingerprint_sets_connection_degraded(): void
    {
        $this->fakeHealthyServer();
        $connection = $this->connection(['server_fingerprint' => str_repeat('0', 64)]);

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);
        $connection->refresh();

        $this->assertTrue($report->result->ok);
        $this->assertSame('degraded', $connection->getAttribute('status'));
        $this->assertSame('server_fingerprint_changed', $connection->getAttribute('degraded_reason'));
        $this->assertTrue($connection->getAttribute('probe_result')['fingerprint_changed']);
    }

    public function test_carddav_probe_records_contacts_read_and_locks_contacts_write(): void
    {
        $this->fakeHealthyServer();
        $connection = ImmowareConnection::factory()->carddav()->create(['rate_limit_rps' => 50]);

        $report = $this->app->make(ProbeService::class)->run($connection, null, 0);

        $this->assertTrue($report->result->ok);
        $this->assertSame('carddav', $report->connector);
        $this->assertSame(CapabilityStatus::Tested, Capability::query()->where('connection_id', $connection->getKey())->where('capability_key', 'contacts.read')->firstOrFail()->getAttribute('evidence_status'));
        $this->assertTrue((bool) Capability::query()->where('connection_id', $connection->getKey())->where('capability_key', 'contacts.write')->firstOrFail()->getAttribute('hard_locked'));
    }

    public function test_rest_api_slot_probe_makes_no_requests(): void
    {
        Http::fake();
        $connection = $this->connection(['connector_type' => 'rest_api_slot', 'name' => 'Slot']);

        $report = $this->app->make(ProbeService::class)->run($connection);

        Http::assertNothingSent();
        $this->assertNull($report->strategy);
        $this->assertSame(CapabilityStatus::WaitingForVendorAccess, Capability::query()->where('connection_id', $connection->getKey())->where('capability_key', 'cases.read')->firstOrFail()->getAttribute('evidence_status'));
    }
}
