<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Webhooks\Jobs\DeliverWebhookJob;
use App\Modules\Webhooks\Models\WebhookDelivery;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    private function deadDelivery(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $outbox = WebhookOutbox::query()->create([
            'event_id' => (string) Str::uuid(),
            'organization_id' => $endpoint->organization_id,
            'event_type' => 'document.created',
            'payload_json' => ['event' => 'document.created'],
            'occurred_at' => now(),
        ]);

        return WebhookDelivery::query()->create([
            'endpoint_id' => $endpoint->getKey(),
            'outbox_id' => $outbox->getKey(),
            'delivery_uuid' => (string) Str::uuid(),
            'signature' => str_repeat('a', 64),
            'attempts' => 5,
            'status' => WebhookDelivery::STATUS_DEAD,
            'last_response_code' => 503,
            'duration_ms' => 812,
            'last_error' => 'HTTP 503',
            'dead_at' => now(),
        ]);
    }

    public function test_administrator_sees_endpoints_deliveries_and_dlq(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        $endpoint = WebhookEndpoint::factory()->for($organization)->create(['name' => 'n8n Produktiv']);
        $foreign = WebhookEndpoint::factory()->create(['name' => 'Fremder Endpunkt']);
        $this->deadDelivery($endpoint);

        $this->login($user)->get('/admin/webhooks')->assertOk()->assertSee('n8n Produktiv')->assertDontSee('Fremder Endpunkt')->assertDontSee('whsec_');
        $this->login($user)->get('/admin/webhooks/deliveries')->assertOk()->assertSee('document.created')->assertSee('812 ms')->assertSee('503')->assertSee('Erneut zustellen');
        $this->login($user)->get('/admin/webhooks/dlq')->assertOk()->assertSee('document.created')->assertSee('Webhook-DLQ');
        $this->assertNotNull($foreign);
    }

    public function test_read_only_is_forbidden(): void
    {
        $user = User::factory()->role(Role::ReadOnly)->withoutTotp()->create();

        $this->actingAs($user)->get('/admin/webhooks')->assertForbidden();
        $this->actingAs($user)->post('/admin/webhooks', ['name' => 'x', 'url' => 'https://example.test/h', 'events' => ['document.created']])->assertForbidden();
    }

    public function test_store_shows_secret_once_and_audits(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();

        $response = $this->login($user)->post('/admin/webhooks', [
            'name' => 'Buchhaltung',
            'url' => 'https://hooks.example.test/hub',
            'events' => ['document.created', 'sync.failed'],
            'active' => '1',
        ]);
        $response->assertRedirect('/admin/webhooks');

        $endpoint = WebhookEndpoint::query()->where('name', 'Buchhaltung')->firstOrFail();
        $this->assertSame((int) $organization->getKey(), (int) $endpoint->organization_id);
        $this->assertStringStartsWith('whsec_', (string) $endpoint->secret);

        $page = $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->get('/admin/webhooks');
        $page->assertOk()->assertSee('data-secret-reveal', false)->assertSee((string) $endpoint->secret);

        // Beim zweiten Aufruf ist das Secret nicht mehr sichtbar.
        $this->login($user)->get('/admin/webhooks')->assertOk()->assertDontSee((string) $endpoint->secret);

        $log = AuditLog::query()->where('action', 'admin.webhooks.endpoint_created')->firstOrFail();
        $this->assertSame('WebhookEndpoint', $log->entity_type);
        $this->assertArrayNotHasKey('secret', (array) $log->after_json);
    }

    public function test_store_requires_https_and_known_events(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();

        $this->login($user)->from('/admin/webhooks/create')->post('/admin/webhooks', [
            'name' => 'Unsicher',
            'url' => 'http://hooks.example.test/hub',
            'events' => ['unbekannt.event'],
        ])->assertRedirect('/admin/webhooks/create')->assertSessionHasErrors(['url', 'events.0']);
    }

    public function test_update_deactivate_and_redeliver_write_audit_entries(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Owner)->for($organization)->create();
        $endpoint = WebhookEndpoint::factory()->for($organization)->create();
        $delivery = $this->deadDelivery($endpoint);

        $this->login($user)->put('/admin/webhooks/'.$endpoint->getKey(), [
            'name' => 'Umbenannt',
            'url' => 'https://hooks.example.test/neu',
            'events' => ['contact.updated'],
        ])->assertRedirect('/admin/webhooks');
        $this->assertSame('Umbenannt', $endpoint->fresh()->name);
        $this->assertSame(['contact.updated'], $endpoint->fresh()->events);

        $this->login($user)->post('/admin/webhooks/'.$endpoint->getKey().'/deactivate', ['confirmation' => 'nein'])->assertStatus(422);
        $this->login($user)->post('/admin/webhooks/'.$endpoint->getKey().'/deactivate', ['confirmation' => 'BESTÄTIGEN', 'reason' => 'Empfänger abgeschaltet'])->assertRedirect('/admin/webhooks');
        $this->assertFalse((bool) $endpoint->fresh()->active);

        $this->login($user)->post('/admin/webhooks/deliveries/'.$delivery->getKey().'/redeliver')->assertRedirect('/admin/webhooks/deliveries');
        $fresh = $delivery->fresh();
        $this->assertSame(WebhookDelivery::STATUS_PENDING, $fresh->status);
        $this->assertSame(0, (int) $fresh->attempts);
        Queue::assertPushed(DeliverWebhookJob::class, static fn (DeliverWebhookJob $job): bool => $job->deliveryId === (int) $delivery->getKey());

        foreach (['admin.webhooks.endpoint_updated', 'admin.webhooks.endpoint_deactivated', 'admin.webhooks.delivery_redelivered'] as $action) {
            $this->assertNotNull(AuditLog::query()->where('action', $action)->first(), $action);
        }
    }
}
