<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Http\Controllers;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Webhooks\Http\Requests\StoreWebhookEndpointRequest;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Verwaltung der Webhook-Endpunkte über die API (Scope admin). Das Secret wird genau einmal im Klartext ausgegeben.
 */
final class WebhookEndpointController
{
    public function __construct(
        private readonly ApiResponse $response,
        private readonly ApiCaller $caller,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $endpoints = WebhookEndpoint::query()->orderBy('id')->paginate(100);

        return $this->response->raw([
            'endpoints' => array_map($this->present(...), $endpoints->items()),
            'events' => (array) config('hub.webhooks.events', []),
            'enabled' => (bool) config('hub.webhooks.enabled', false),
        ], ['page' => $endpoints->currentPage(), 'per_page' => $endpoints->perPage(), 'total' => $endpoints->total()]);
    }

    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        $organizationId = $this->caller->organizationId($request);

        if ($organizationId === null) {
            throw ApiProblemException::forbidden('role_forbidden', 'Kein Mandantenkontext.');
        }

        $data = $request->validated();

        if (WebhookEndpoint::query()->where('name', $data['name'])->exists()) {
            throw ApiProblemException::conflict('conflict', 'Ein Endpunkt mit diesem Namen existiert bereits.');
        }

        $secret = 'whsec_'.Str::random(40);

        $endpoint = new WebhookEndpoint;
        $endpoint->forceFill([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'url' => $data['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($data['events'])),
            'active' => (bool) ($data['active'] ?? true),
        ]);
        $endpoint->save();

        $this->audit->log('webhooks.endpoint.created', $endpoint, [], ['name' => $data['name'], 'events' => $endpoint->getAttribute('events')], AuditSource::Api->value, $this->correlationId->current());

        $payload = $this->present($endpoint);
        $payload['secret'] = $secret;
        $payload['secret_note'] = 'Das Secret wird nur einmal angezeigt und verschlüsselt gespeichert.';

        return $this->response->raw($payload, [], 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        /** @var WebhookEndpoint $endpoint */
        $endpoint = WebhookEndpoint::query()->whereKey((int) $id)->firstOrFail();
        $endpoint->forceFill(['active' => false])->save();
        $endpoint->delete();

        $this->audit->log('webhooks.endpoint.deleted', $endpoint, ['name' => $endpoint->getAttribute('name')], [], AuditSource::Api->value, $this->correlationId->current());

        return new Response('', 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(mixed $endpoint): array
    {
        if (! $endpoint instanceof WebhookEndpoint) {
            return [];
        }

        $created = $endpoint->getAttribute('created_at');

        return [
            'id' => (int) $endpoint->getKey(),
            'name' => $endpoint->getAttribute('name'),
            'url' => $endpoint->getAttribute('url'),
            'events' => $endpoint->getAttribute('events'),
            'active' => (bool) $endpoint->getAttribute('active'),
            'created_at' => $created instanceof \DateTimeInterface ? CarbonImmutable::instance($created)->utc()->toIso8601ZuluString('millisecond') : null,
        ];
    }
}
