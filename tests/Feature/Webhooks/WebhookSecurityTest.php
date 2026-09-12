<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookOutbox;
use App\Modules\Webhooks\Services\WebhookSigner;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\ApiTestCase;

/**
 * Review-Findings 12.09.2026 Webhooks: SSRF-Schutz beim Anlegen und vor Versand, kein Doppel-Dispatch durch
 * hub:webhooks:redeliver, Ereignis sync.stale.
 */
final class WebhookSecurityTest extends ApiTestCase
{
    /** @var array<string, array<int, string>> */
    private array $dns = [
        'hooks.example.test' => ['93.184.216.34'],
        'intern.example.test' => ['93.184.216.34', '10.20.30.40'],
        'v6.example.test' => ['fd00::1'],
        'meta.example.test' => ['169.254.169.254'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.webhooks.enabled', true);
        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(fn (string $host): array => $this->dns[$host] ?? []));
    }

    public function test_url_guard_blocks_private_local_and_link_local_targets(): void
    {
        $guard = $this->app->make(WebhookUrlGuard::class);

        $this->assertNull($guard->reason('https://hooks.example.test/hub'));
        $this->assertSame('scheme_not_https', $guard->reason('http://hooks.example.test/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://127.0.0.1/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://10.1.2.3/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://172.16.5.5/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://172.31.255.255/hub'));
        $this->assertNull($guard->reason('https://172.32.0.1/hub'), '172.32/12 liegt außerhalb von 172.16/12');
        $this->assertSame('ip_blocked', $guard->reason('https://192.168.0.10/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://169.254.169.254/latest/meta-data'));
        $this->assertSame('ip_blocked', $guard->reason('https://[::1]/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://[fc00::1]/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://[fd12::1]/hub'));
        $this->assertSame('ip_blocked', $guard->reason('https://[::ffff:10.0.0.1]/hub'), 'IPv4-mapped IPv6');
        $this->assertSame('host_blocked', $guard->reason('https://localhost/hub'));
        $this->assertSame('host_blocked', $guard->reason('https://db.internal/hub'));
        $this->assertSame('host_blocked', $guard->reason('https://nas.local/hub'));
        $this->assertSame('credentials_in_url', $guard->reason('https://user:pw@hooks.example.test/hub'));
        $this->assertSame('resolves_to_blocked_ip', $guard->reason('https://intern.example.test/hub'), 'DNS-Rebinding: eine private Adresse im Antwortsatz genügt');
        $this->assertSame('resolves_to_blocked_ip', $guard->reason('https://v6.example.test/hub'));
        $this->assertSame('resolves_to_blocked_ip', $guard->reason('https://meta.example.test/hub'));
        $this->assertSame('dns_unresolved', $guard->reason('https://gibt-es-nicht.example.test/hub'));
    }

    public function test_store_rejects_private_targets_with_422(): void
    {
        $this->issueKey(['webhooks:manage']);

        foreach (['https://127.0.0.1/hook', 'https://10.0.0.5/hook', 'https://192.168.1.1/hook', 'https://169.254.169.254/hook', 'https://[::1]/hook', 'https://localhost/hook', 'https://db.internal/hook', 'https://intern.example.test/hook'] as $url) {
            $this->postJson('/api/v1/webhook-endpoints', ['name' => 'n8n '.md5($url), 'url' => $url, 'events' => ['contact.updated']], $this->writeHeaders('idem-'.md5($url)))
                ->assertStatus(422)
                ->assertJson(['code' => 'validation_failed'])
                ->assertJsonPath('errors.0.field', 'url');
        }

        $this->assertDatabaseCount('webhook_endpoints', 0);

        $this->postJson('/api/v1/webhook-endpoints', ['name' => 'n8n ok', 'url' => 'https://hooks.example.test/hook', 'events' => ['contact.updated']], $this->writeHeaders('idem-ok'))
            ->assertStatus(201);
    }

    public function test_delivery_is_skipped_without_request_when_target_resolves_to_private_address(): void
    {
        Bus::fake();
        Http::fake();

        // Endpunkt wurde angelegt, als der Name öffentlich auflöste; vor dem Versand zeigt DNS auf 10.20.30.40.
        WebhookEndpoint::factory()->for($this->organization)->events(['contact.updated'])->create(['url' => 'https://intern.example.test/hook']);
        $this->app->make(WebhookDispatcherInterface::class)->dispatch('contact.updated', ['id' => 5, 'type' => 'contact', 'href' => '/api/v1/contacts/5'], (int) $this->organization->getKey());

        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertNotNull($delivery->getAttribute('queued_at'), 'Dispatch markiert die Zustellung als eingereiht');

        (new DeliverWebhookJob((int) $delivery->getKey()))->handle($this->app->make(WebhookSigner::class), $this->app->make(WebhookUrlGuard::class));

        Http::assertNothingSent();
        $delivery->refresh();
        $this->assertSame(WebhookDelivery::STATUS_SKIPPED, $delivery->getAttribute('status'));
        $this->assertSame('url_blocked:resolves_to_blocked_ip', $delivery->getAttribute('last_error'));
        $this->assertNull($delivery->getAttribute('queued_at'));
    }

    public function test_redeliver_dispatches_only_due_failed_deliveries_that_are_not_queued(): void
    {
        Bus::fake();
        $endpoint = WebhookEndpoint::factory()->for($this->organization)->events(['contact.updated'])->create();
        $organizationId = (int) $this->organization->getKey();

        $make = function (string $status, ?CarbonImmutable $nextAttempt, ?CarbonImmutable $queuedAt) use ($endpoint, $organizationId): WebhookDelivery {
            static $n = 0;
            $n++;
            // Unique (endpoint_id, outbox_id): je Zustellung eigener Outbox-Eintrag.
            $outbox = new WebhookOutbox;
            $outbox->forceFill(['event_id' => 'e-'.$n, 'organization_id' => $organizationId, 'event_type' => 'contact.updated', 'payload_json' => ['x' => 1], 'occurred_at' => now()])->save();
            $delivery = new WebhookDelivery;
            $delivery->forceFill(['endpoint_id' => $endpoint->getKey(), 'outbox_id' => $outbox->getKey(), 'delivery_uuid' => 'd-'.$n, 'signature' => '', 'attempts' => 1, 'status' => $status, 'next_attempt_at' => $nextAttempt, 'queued_at' => $queuedAt]);
            $delivery->save();

            return $delivery;
        };

        $now = CarbonImmutable::now();
        $dueLost = $make(WebhookDelivery::STATUS_FAILED, $now->subMinute(), null);                    // fällig, nicht in Queue: einreihen
        $dueQueued = $make(WebhookDelivery::STATUS_FAILED, $now->subMinute(), $now->subMinutes(3));  // fällig, Queue-Retry läuft: nicht einreihen
        $dueQueuedLost = $make(WebhookDelivery::STATUS_FAILED, $now->subMinutes(10), $now->subMinutes(15)); // Karenz überschritten: einreihen
        $notDue = $make(WebhookDelivery::STATUS_FAILED, $now->addMinutes(20), null);                 // nicht fällig
        $pending = $make(WebhookDelivery::STATUS_PENDING, $now->subMinutes(10), null);               // pending: Job liegt aus dem Dispatch in der Queue
        $dead = $make(WebhookDelivery::STATUS_DEAD, $now->subMinutes(10), null);

        $this->artisan('hub:webhooks:redeliver')->assertSuccessful()->expectsOutputToContain('2 Zustellungen eingereiht.');

        Bus::assertDispatchedTimes(DeliverWebhookJob::class, 2);
        Bus::assertDispatched(DeliverWebhookJob::class, static fn (DeliverWebhookJob $job): bool => $job->deliveryId === (int) $dueLost->getKey());
        Bus::assertDispatched(DeliverWebhookJob::class, static fn (DeliverWebhookJob $job): bool => $job->deliveryId === (int) $dueQueuedLost->getKey());
        $this->assertNotNull($dueLost->refresh()->getAttribute('queued_at'), 'Einreihung wird markiert');

        foreach ([$dueQueued, $notDue, $pending, $dead] as $untouched) {
            Bus::assertNotDispatched(DeliverWebhookJob::class, static fn (DeliverWebhookJob $job): bool => $job->deliveryId === (int) $untouched->getKey());
        }

        // Zweiter Lauf direkt danach: nichts mehr fällig und frei.
        $this->artisan('hub:webhooks:redeliver')->expectsOutputToContain('0 Zustellungen eingereiht.');
    }

    public function test_sync_stale_event_is_emitted_once_per_stale_phase(): void
    {
        Bus::fake();
        $connection = $this->createConnection($this->organization);
        WebhookEndpoint::factory()->for($this->organization)->events(['sync.stale'])->create();

        $state = new SyncState;
        $state->forceFill(['connection_id' => $connection->getKey(), 'entity_type' => 'document', 'scope' => 'collection', 'collection_path_hash' => hash('sha256', '/Dokumente/'), 'stale_since' => CarbonImmutable::now()->subHours(3)])->save();
        $fresh = new SyncState;
        $fresh->forceFill(['connection_id' => $connection->getKey(), 'entity_type' => 'contact', 'scope' => 'collection', 'collection_path_hash' => hash('sha256', '/addressbooks/'), 'stale_since' => null])->save();

        $this->artisan('hub:webhooks:emit-stale')->assertSuccessful()->expectsOutputToContain('1 sync.stale-Ereignisse ausgelöst.');
        $this->artisan('hub:webhooks:emit-stale')->expectsOutputToContain('0 sync.stale-Ereignisse ausgelöst.');

        $outbox = WebhookOutbox::query()->where('event_type', 'sync.stale')->get();
        $this->assertCount(1, $outbox);
        $payload = $outbox->first()?->getAttribute('payload_json');
        $this->assertSame((int) $connection->getKey(), $payload['data']['connection_id']);
        $this->assertSame('document', $payload['data']['entity_type']);
        $this->assertSame('webdav', $payload['source']['connector']);
        $this->assertSame(1, WebhookDelivery::query()->count());

        // Neue Stale-Phase nach zwischenzeitlichem Erfolg: erneut genau ein Ereignis.
        $state->forceFill(['stale_since' => CarbonImmutable::now()->addMinute()])->save();
        $this->artisan('hub:webhooks:emit-stale')->expectsOutputToContain('1 sync.stale-Ereignisse ausgelöst.');
    }
}
