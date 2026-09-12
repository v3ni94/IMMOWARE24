<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Services\SyncStateService;
use App\Modules\Sync\Support\Heartbeat;
use Carbon\CarbonImmutable;

final class HealthTest extends ApiTestCase
{
    public function test_health_is_ok_without_auth_and_hides_details(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk()->assertJsonPath('status', 'ok');
        $this->assertSame(['status'], array_keys($response->json('checks.database')));
        $this->assertArrayNotHasKey('checked_at', $response->json());

        $this->getJson('/health/database')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/health/queue')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_queue_check_reports_down_when_redis_queue_is_unreachable(): void
    {
        // Vor dem Fix zählte /health/queue bei Redis-Queue die (leere) Tabelle jobs und meldete ok.
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.connection', 'health-test');
        config()->set('database.redis.health-test', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);

        $this->getJson('/health/queue')->assertStatus(503)->assertJsonPath('status', 'down');
    }

    public function test_queue_check_is_degraded_when_worker_heartbeat_is_stale(): void
    {
        $heartbeat = $this->app->make(Heartbeat::class);
        $heartbeat->beat(Heartbeat::WORKER, CarbonImmutable::now('UTC')->subSeconds(600));

        try {
            $this->getJson('/health/queue')->assertStatus(200)->assertJsonPath('status', 'degraded');
        } finally {
            @unlink(Heartbeat::path(Heartbeat::WORKER));
        }
    }

    public function test_health_returns_503_when_mirror_is_stale_and_details_for_admin(): void
    {
        $connection = $this->createConnection($this->organization, ['status' => 'active']);

        SyncState::query()->create([
            'connection_id' => $connection->getKey(),
            'entity_type' => 'contact',
            'scope' => 'collection',
            'collection_path_hash' => SyncStateService::collectionPathHash('contact'),
            'resource_external_id_hash' => '',
            'last_success_at' => CarbonImmutable::now()->subDays(2),
        ]);

        $this->getJson('/health')->assertStatus(503)->assertJsonPath('status', 'down');
        $this->getJson('/health/immoware')->assertStatus(503)->assertExactJson(['status' => 'down']);

        $this->issueKey(['properties:read']);
        $this->getJson('/health/immoware', $this->authHeaders())->assertStatus(503)->assertExactJson(['status' => 'down']);

        $this->issueKey(['admin']);
        $detailed = $this->getJson('/health/immoware', $this->authHeaders());
        $detailed->assertStatus(503)
            ->assertJsonPath('details.connections.0.entities.0.entity_type', 'contact')
            ->assertJsonPath('details.connections.0.entities.0.stale', true)
            ->assertJsonStructure(['checked_at']);

        $this->assertStringNotContainsString('dav.example.test', (string) $detailed->getContent());

        SyncState::query()->update(['last_success_at' => CarbonImmutable::now()->subMinutes(5)]);

        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
