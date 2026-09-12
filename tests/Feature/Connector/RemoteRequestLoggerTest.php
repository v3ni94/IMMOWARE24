<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Modules\Connector\Models\RemoteRequest;
use App\Modules\Connector\Services\RemoteRequestLogger;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RemoteRequestLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_masks_passwords_in_url_headers_and_errors(): void
    {
        $connection = $this->createConnection();
        $logger = $this->app->make(RemoteRequestLogger::class);

        $record = $logger->record(
            connectionId: (int) $connection->getKey(),
            connectorName: 'webdav:read',
            method: 'PROPFIND',
            url: 'https://hub:passwort123@dav.example.test/share/?password=passwort123',
            status: 401,
            durationMs: 42,
            requestHeaders: ['Authorization' => ['Basic aHViOnBhc3N3b3J0MTIz'], 'Depth' => ['0']],
            responseHeaders: ['WWW-Authenticate' => ['Basic realm="dav"'], 'Set-Cookie' => ['sid=geheim']],
            responseBody: '<D:error xmlns:D="DAV:"/>',
            error: new \RuntimeException('Login for hub with password=passwort123 failed'),
        );

        $this->assertNotNull($record);
        $dump = json_encode($record->fresh()?->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('passwort123', $dump);
        $this->assertStringNotContainsString('aHViOnBhc3N3b3J0MTIz', $dump);
        $this->assertStringNotContainsString('sid=geheim', $dump);
        $this->assertSame('unauthorized', $record->getAttribute('outcome'));
        $this->assertSame('0', $record->getAttribute('request_headers_masked')['Depth'][0]);
        $this->assertSame(hash('sha256', $record->getAttribute('path')), $record->getAttribute('path_hash'));
    }

    public function test_prune_deletes_only_entries_older_than_retention(): void
    {
        $connection = $this->createConnection();
        RemoteRequest::factory()->count(3)->for($connection, 'connection')->create(['requested_at' => CarbonImmutable::now()->subDays(100)]);
        RemoteRequest::factory()->count(2)->for($connection, 'connection')->create(['requested_at' => CarbonImmutable::now()->subDays(10)]);

        $this->artisan('hub:connector:prune-remote-requests', ['--days' => 90])
            ->expectsOutputToContain('3 Einträge')
            ->assertSuccessful();

        $this->assertSame(2, RemoteRequest::query()->count());
    }

    public function test_logging_can_be_disabled(): void
    {
        config()->set('hub.connector.remote_requests.enabled', false);
        $this->app->forgetInstance(RemoteRequestLogger::class);
        $logger = $this->app->make(RemoteRequestLogger::class);

        $this->assertNull($logger->record(null, null, 'GET', 'https://dav.example.test/', 200, 1));
        $this->assertSame(0, RemoteRequest::query()->count());
    }
}
