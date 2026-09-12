<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Modules\Webhooks\Events\HubEvent;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class WebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hub.webhooks.enabled', true);
    }

    public function test_dispatcher_is_noop_when_feature_flag_is_disabled(): void
    {
        config()->set('hub.webhooks.enabled', false);
        $organization = $this->createOrganization();
        WebhookEndpoint::factory()->for($organization)->create();

        $this->app->make(WebhookDispatcherInterface::class)->dispatch('document.created', ['id' => 1, 'type' => 'document'], (int) $organization->getKey());

        $this->assertDatabaseCount('webhook_outbox', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
    }

    public function test_dispatch_writes_outbox_and_deliveries_only_for_active_subscribed_endpoints(): void
    {
        Bus::fake();
        $organization = $this->createOrganization();
        $active = WebhookEndpoint::factory()->for($organization)->events(['document.created'])->create();
        WebhookEndpoint::factory()->for($organization)->events(['contact.updated'])->create();
        WebhookEndpoint::factory()->for($organization)->events(['document.created'])->inactive()->create();
        WebhookEndpoint::factory()->events(['document.created'])->create(); // anderer Mandant

        HubEvent::dispatch('document.created', (int) $organization->getKey(), ['id' => 42, 'type' => 'document', 'href' => '/api/v1/documents/42']);

        $this->assertDatabaseCount('webhook_outbox', 1);
        $this->assertDatabaseHas('webhook_outbox', ['event_type' => 'document.created', 'entity_type' => 'document', 'entity_id' => 42, 'organization_id' => $organization->getKey()]);
        $this->assertDatabaseCount('webhook_deliveries', 1);
        $this->assertDatabaseHas('webhook_deliveries', ['endpoint_id' => $active->getKey(), 'status' => 'pending']);

        Bus::assertDispatched(DeliverWebhookJob::class, 1);
    }

    public function test_delivery_sends_verifiable_signature_and_headers(): void
    {
        Bus::fake();
        Http::fake(['https://consumer.example.test/*' => Http::response('{"ok":true}', 200)]);

        $organization = $this->createOrganization();
        $secret = 'whsec_testsecret_1234567890';
        WebhookEndpoint::factory()->for($organization)->events(['contact.updated'])->secret($secret)->create();

        $this->app->make(WebhookDispatcherInterface::class)->dispatch('contact.updated', ['id' => 5, 'type' => 'contact', 'href' => '/api/v1/contacts/5'], (int) $organization->getKey());

        $delivery = WebhookDelivery::query()->firstOrFail();
        (new DeliverWebhookJob((int) $delivery->getKey()))->handle($this->app->make(WebhookSigner::class));

        $signer = $this->app->make(WebhookSigner::class);

        Http::assertSent(function (Request $request) use ($signer, $secret, $delivery): bool {
            $signature = $request->header('X-Hub-Signature')[0] ?? '';
            $body = $request->body();
            $payload = json_decode($body, true);

            $this->assertMatchesRegularExpression('/^t=\d+,v1=[0-9a-f]{64}$/', $signature);
            $this->assertTrue($signer->verify($signature, $body, $secret));
            $this->assertFalse($signer->verify($signature, $body.' ', $secret));
            $this->assertFalse($signer->verify($signature, $body, 'falsches-secret'));
            $this->assertFalse($signer->verify($signature, $body, $secret, time() + 1000), 'Replay-Fenster muss greifen.');

            $this->assertSame('contact.updated', $request->header('X-Hub-Event')[0]);
            $this->assertSame($delivery->getAttribute('delivery_uuid'), $request->header('X-Hub-Delivery')[0]);
            $this->assertSame('contact.updated', $payload['event']);
            $this->assertSame(5, $payload['data']['id']);
            $this->assertArrayHasKey('event_id', $payload);

            return $request->url() === $delivery->endpoint()->firstOrFail()->getAttribute('url');
        });

        $delivery->refresh();
        $this->assertSame('delivered', $delivery->getAttribute('status'));
        $this->assertSame(200, $delivery->getAttribute('last_response_code'));
        $this->assertSame(1, $delivery->getAttribute('attempts'));
        $this->assertNotNull($delivery->getAttribute('duration_ms'));
        $this->assertSame('{"ok":true}', $delivery->getAttribute('response_excerpt'));
    }

    public function test_failed_delivery_uses_backoff_and_lands_in_dlq_after_five_attempts(): void
    {
        Bus::fake();
        Http::fake(['https://consumer.example.test/*' => Http::response(str_repeat('x', 2000), 500)]);

        $organization = $this->createOrganization();
        WebhookEndpoint::factory()->for($organization)->events(['sync.failed'])->create();
        $this->app->make(WebhookDispatcherInterface::class)->dispatch('sync.failed', ['id' => 9, 'type' => 'sync_run'], (int) $organization->getKey());

        $delivery = WebhookDelivery::query()->firstOrFail();
        $job = new DeliverWebhookJob((int) $delivery->getKey());
        $signer = $this->app->make(WebhookSigner::class);

        $this->assertSame([30, 120, 600, 1800], $job->backoff());
        $this->assertSame(5, $job->tries);

        $expectedWaits = [30, 120, 600, 1800];

        foreach ($expectedWaits as $index => $wait) {
            $this->travelTo(now()->addMinutes($index * 40));
            $before = now()->toImmutable();

            try {
                $job->handle($signer);
                $this->fail('Fehlgeschlagene Zustellung muss eine Exception für den Queue-Retry werfen.');
            } catch (RuntimeException) {
                // erwartet
            }

            $delivery->refresh();
            $this->assertSame('failed', $delivery->getAttribute('status'));
            $this->assertSame($index + 1, $delivery->getAttribute('attempts'));
            $this->assertSame($before->addSeconds($wait)->timestamp, $delivery->getAttribute('next_attempt_at')->timestamp);
            $this->assertSame(500, $delivery->getAttribute('last_response_code'));
            $this->assertSame(512, strlen((string) $delivery->getAttribute('response_excerpt')));
        }

        $job->handle($signer);

        $delivery->refresh();
        $this->assertSame('dead', $delivery->getAttribute('status'));
        $this->assertSame(5, $delivery->getAttribute('attempts'));
        $this->assertNotNull($delivery->getAttribute('dead_at'));
        $this->assertNull($delivery->getAttribute('next_attempt_at'));

        Http::assertSentCount(5);
    }

    public function test_inactive_endpoint_receives_nothing_even_if_delivery_exists(): void
    {
        Bus::fake();
        Http::fake();

        $organization = $this->createOrganization();
        $endpoint = WebhookEndpoint::factory()->for($organization)->events(['unit.updated'])->create();
        $this->app->make(WebhookDispatcherInterface::class)->dispatch('unit.updated', ['id' => 3, 'type' => 'unit'], (int) $organization->getKey());

        $endpoint->forceFill(['active' => false])->save();

        $delivery = WebhookDelivery::query()->firstOrFail();
        (new DeliverWebhookJob((int) $delivery->getKey()))->handle($this->app->make(WebhookSigner::class));

        Http::assertNothingSent();
        $this->assertSame('skipped', $delivery->refresh()->getAttribute('status'));
    }

    public function test_unknown_events_are_dropped(): void
    {
        $organization = $this->createOrganization();
        $this->app->make(WebhookDispatcherInterface::class)->dispatch('foo.bar', [], (int) $organization->getKey());

        $this->assertDatabaseCount('webhook_outbox', 0);
    }
}
