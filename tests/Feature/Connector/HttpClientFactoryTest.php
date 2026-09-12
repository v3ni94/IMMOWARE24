<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Core\Exceptions\CircuitOpenException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Enums\CircuitState;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Models\RemoteRequest;
use App\Modules\Connector\Services\CircuitBreaker;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Services\RateLimitManager;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class HttpClientFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_sends_user_agent_basic_auth_and_logs_masked_request(): void
    {
        Http::fake(fn (Request $request) => Http::response('<D:multistatus xmlns:D="DAV:"/>', 207, ['Content-Type' => 'application/xml']));

        $connection = $this->createConnection(null, ['rate_limit_rps' => 50, 'credentials' => ['username' => 'hub-read', 'password' => 'streng-geheim']]);
        $context = $this->app->make(ConnectorManager::class)->contextFor($connection);
        $factory = $this->app->make(HttpClientFactory::class);

        $response = $factory->for($context)->withHeaders(['Depth' => '0'])->send('PROPFIND', '/?token=abc123');

        $this->assertSame(207, $response->status());

        Http::assertSent(function (Request $request): bool {
            return str_starts_with((string) $request->header('User-Agent')[0], 'ImmowareHub/')
                && $request->hasHeader('Authorization')
                && $request->method() === 'PROPFIND';
        });

        $record = RemoteRequest::query()->firstOrFail();
        $this->assertSame('PROPFIND', $record->getAttribute('method'));
        $this->assertSame(207, $record->getAttribute('response_status'));
        $this->assertSame('webdav:read', $record->getAttribute('connector_name'));
        $this->assertSame('success', $record->getAttribute('outcome'));
        $this->assertNotNull($record->getAttribute('response_schema_fingerprint'));
        $this->assertNotNull($record->getAttribute('correlation_id'));

        $serialized = json_encode($record->getAttributes(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('streng-geheim', $serialized);
        $this->assertStringNotContainsString(base64_encode('hub-read:streng-geheim'), $serialized);
        $this->assertStringNotContainsString('abc123', $serialized);
        $this->assertSame('***', $record->getAttribute('request_headers_masked')['Authorization']);
    }

    public function test_method_guard_blocks_delete_move_and_put_without_if_none_match(): void
    {
        Http::fake();
        $connection = $this->createConnection(null, ['rate_limit_rps' => 50]);
        $context = $this->app->make(ConnectorManager::class)->contextFor($connection);
        $factory = $this->app->make(HttpClientFactory::class);

        foreach (['DELETE', 'MOVE', 'COPY', 'PROPPATCH', 'LOCK', 'UNLOCK'] as $method) {
            try {
                $factory->for($context, 'write')->send($method, '/Posteingang/x.pdf');
                $this->fail($method.' wurde nicht blockiert.');
            } catch (WriteBlockedException $e) {
                $this->assertSame($method, $e->operation);
            }
        }

        try {
            $factory->for($context, 'write')->withBody('x', 'application/pdf')->send('PUT', '/Posteingang/x.pdf');
            $this->fail('PUT ohne If-None-Match wurde nicht blockiert.');
        } catch (WriteBlockedException) {
            $this->addToAssertionCount(1);
        }

        // POST und PATCH sind ebenfalls fest gesperrt (05 2.3), unabhängig von jeder Konfiguration.
        foreach (['POST', 'PATCH'] as $method) {
            try {
                $factory->for($context, 'write')->send($method, '/Posteingang/x.pdf');
                $this->fail($method.' wurde nicht blockiert.');
            } catch (WriteBlockedException) {
                $this->addToAssertionCount(1);
            }
        }

        // Lese-Connection: PUT mit If-None-Match bleibt gesperrt (purpose read).
        try {
            $factory->for($context, 'write')->withHeaders(['If-None-Match' => '*'])->withBody('x', 'application/pdf')->send('PUT', '/Posteingang/x.pdf');
            $this->fail('PUT über eine Lese-Connection wurde nicht blockiert.');
        } catch (WriteBlockedException) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();

        // Schreib-Connection: PUT nur innerhalb des Schreibpräfixes.
        $write = $this->createConnection($connection->organization, ['rate_limit_rps' => 50, 'purpose' => 'write', 'allowed_write_prefix' => '/Posteingang/']);
        $writeContext = $this->app->make(ConnectorManager::class)->contextFor($write);

        try {
            $factory->for($writeContext, 'write')->withHeaders(['If-None-Match' => '*'])->withBody('x', 'application/pdf')->send('PUT', '/Dokumente/x.pdf');
            $this->fail('PUT außerhalb des Schreibpräfixes wurde nicht blockiert.');
        } catch (WriteBlockedException) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();

        $factory->for($writeContext, 'write')->withHeaders(['If-None-Match' => '*'])->withBody('x', 'application/pdf')->send('PUT', '/Posteingang/x.pdf');
        Http::assertSentCount(1);
    }

    public function test_method_guard_allows_only_read_methods_for_carddav_and_caldav(): void
    {
        Http::fake();
        $factory = $this->app->make(HttpClientFactory::class);

        foreach (['carddav_contacts', 'caldav_calendar'] as $type) {
            $connection = $this->createConnection(null, ['rate_limit_rps' => 50, 'connector_type' => $type, 'purpose' => 'write', 'allowed_write_prefix' => '/']);
            $context = $this->app->make(ConnectorManager::class)->contextFor($connection);

            try {
                $factory->for($context, 'write')->withHeaders(['If-None-Match' => '*'])->withBody('x', 'text/vcard')->send('PUT', '/x.vcf');
                $this->fail('PUT für '.$type.' wurde nicht blockiert.');
            } catch (WriteBlockedException $e) {
                $this->assertSame('PUT', $e->operation);
            }

            $factory->for($context)->withHeaders(['Depth' => '0'])->send('PROPFIND', '/');
        }

        Http::assertSentCount(2);
    }

    public function test_429_throttles_rate_and_circuit_opens_after_five_server_errors(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push('', 429, ['Retry-After' => '30'])
                ->push('', 500)->push('', 500)->push('', 500)->push('', 500)->push('', 500),
        ]);

        $connection = $this->createConnection(null, ['rate_limit_rps' => 50]);
        $context = $this->app->make(ConnectorManager::class)->contextFor($connection);
        $factory = $this->app->make(HttpClientFactory::class);
        $limiter = $this->app->make(RateLimitManager::class);
        $breaker = $this->app->make(CircuitBreaker::class);
        $rateKey = RateLimitManager::keyFor((int) $connection->getKey());
        $breakerKey = CircuitBreaker::keyFor((int) $connection->getKey());

        $factory->for($context)->send('PROPFIND', '/');
        $this->assertTrue($limiter->isThrottled($rateKey));
        $this->assertSame(1, $limiter->metrics($rateKey)['429_count']);
        $this->assertSame(25.0, $limiter->effectiveRps($rateKey));
        $this->assertSame(CircuitState::Closed, $breaker->state($breakerKey), '429 mit Retry-After zählt nicht für den Breaker');

        for ($i = 0; $i < 5; $i++) {
            $factory->for($context)->send('PROPFIND', '/');
        }

        $this->assertSame(CircuitState::Open, $breaker->state($breakerKey));
        $this->assertSame(0, $limiter->metrics($rateKey)['in_flight'], 'Slots wurden freigegeben');
        $this->assertSame(6, RemoteRequest::query()->count());
        $this->assertSame('throttled', RemoteRequest::query()->orderBy('id')->first()?->getAttribute('outcome'));

        $this->expectException(CircuitOpenException::class);
        $factory->for($context)->send('PROPFIND', '/');
    }

    public function test_connection_failure_is_logged_and_slot_released(): void
    {
        Http::fake(fn () => throw new ConnectException('cURL error 28: Operation timed out', new \GuzzleHttp\Psr7\Request('PROPFIND', 'https://dav.example.test/')));

        $connection = $this->createConnection(null, ['rate_limit_rps' => 50]);
        $context = $this->app->make(ConnectorManager::class)->contextFor($connection);
        $factory = $this->app->make(HttpClientFactory::class);
        $limiter = $this->app->make(RateLimitManager::class);
        $rateKey = RateLimitManager::keyFor((int) $connection->getKey());

        try {
            $factory->for($context)->send('PROPFIND', '/');
            $this->fail('Verbindungsfehler erwartet.');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        $record = RemoteRequest::query()->firstOrFail();
        $this->assertSame('timeout', $record->getAttribute('outcome'));
        $this->assertNull($record->getAttribute('response_status'));
        $this->assertSame(0, $limiter->metrics($rateKey)['in_flight']);
        $this->assertSame(1, $limiter->metrics($rateKey)['timeout_count']);
    }
}
