<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Tests\Feature\Api\ApiTestCase;

final class WebhookEndpointApiTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(WebhookUrlGuard::class, new WebhookUrlGuard(static fn (string $host): array => ['93.184.216.34']));
    }

    public function test_endpoint_management_requires_admin_and_returns_secret_once(): void
    {
        $this->issueKey(['properties:read']);
        $this->getJson('/api/v1/webhook-endpoints', $this->authHeaders())->assertStatus(403);

        $this->issueKey(['admin']);

        $this->postJson('/api/v1/webhook-endpoints', ['name' => 'n8n', 'url' => 'http://insecure.example.test/hook', 'events' => ['document.created']], $this->writeHeaders('idem-wh-0'))
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed']);

        $created = $this->postJson('/api/v1/webhook-endpoints', [
            'name' => 'n8n',
            'url' => 'https://n8n.example.test/webhook/hub',
            'events' => ['document.created', 'sync.failed'],
        ], $this->writeHeaders('idem-wh-1'));

        $created->assertStatus(201)->assertJsonPath('data.active', true);
        $secret = $created->json('data.secret');
        $this->assertStringStartsWith('whsec_', $secret);

        $endpoint = WebhookEndpoint::query()->firstOrFail();
        $this->assertSame($secret, $endpoint->getAttribute('secret'));
        $this->assertNotSame($secret, $endpoint->getRawOriginal('secret'));

        $list = $this->getJson('/api/v1/webhook-endpoints', $this->authHeaders())->assertOk();
        $this->assertStringNotContainsString($secret, (string) $list->getContent());
        $list->assertJsonPath('data.endpoints.0.name', 'n8n');

        $this->deleteJson('/api/v1/webhook-endpoints/'.$endpoint->getKey(), [], $this->authHeaders())->assertStatus(204);
        $this->assertDatabaseCount('webhook_endpoints', 0);
    }
}
