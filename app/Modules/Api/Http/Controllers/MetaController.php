<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Core\Contracts\CapabilityRegistryInterface;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Api\Support\Provenance;
use App\Modules\Api\Support\ResourceRegistry;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Meta-Endpunkte: GET /api/v1 (Ressourcenverzeichnis), /me, /sync/status, /capabilities.
 */
final class MetaController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ApiResponse $response,
        private readonly ApiCaller $caller,
        private readonly Provenance $provenance,
        private readonly Container $container,
    ) {}

    public function root(Request $request): JsonResponse
    {
        $base = rtrim((string) config('hub.api.base_url', ''), '/');
        $writeEnabled = (bool) config('hub.core.write.enabled', false) && (bool) config('hub.core.write.webdav_create_enabled', false);
        $resources = [];

        foreach ($this->registry->all() as $definition) {
            $entry = [
                'name' => $definition->name,
                'href' => $definition->href(),
                'methods' => $definition->methods,
                'scopes' => [$definition->scope],
                'effect' => $definition->hubOwned ? 'hub' : 'immoware24_mirror',
                'source' => [
                    'access_path' => $definition->accessPath,
                    'evidence_status' => $definition->evidenceStatus,
                ],
            ];

            if ($definition->name === 'documents') {
                $entry['source']['write_enabled'] = $writeEnabled;
                $entry['scopes'][] = 'documents:write';
            }

            if ($definition->name === 'cases') {
                $entry['scopes'][] = 'cases:write';
            }

            if ($definition->name === 'contacts') {
                $entry['scopes'][] = 'contacts:write';
            }

            $resources[] = $entry;
        }

        $resources[] = ['name' => 'directory', 'href' => '/api/v1/directory', 'methods' => ['GET'], 'scopes' => ['directory:read'], 'effect' => 'hub', 'source' => ['access_path' => 'carddav', 'evidence_status' => 'VERIFIZIERT']];
        $resources[] = ['name' => 'sync_status', 'href' => '/api/v1/sync/status', 'methods' => ['GET'], 'scopes' => ['admin'], 'effect' => 'hub', 'source' => ['access_path' => 'manual', 'evidence_status' => null]];
        $resources[] = ['name' => 'capabilities', 'href' => '/api/v1/capabilities', 'methods' => ['GET'], 'scopes' => ['admin'], 'effect' => 'hub', 'source' => ['access_path' => 'manual', 'evidence_status' => null]];
        $resources[] = ['name' => 'webhook_endpoints', 'href' => '/api/v1/webhook-endpoints', 'methods' => ['GET', 'POST', 'DELETE'], 'scopes' => ['admin'], 'effect' => 'hub', 'source' => ['access_path' => 'manual', 'evidence_status' => null]];

        return $this->response->raw([
            'version' => (string) config('hub.api.version', 'v1'),
            'docs_url' => $base.'/api/docs',
            'openapi_url' => $base.'/api/docs/openapi.json',
            'health_url' => $base.'/health',
            'resources' => $resources,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $key = $this->caller->apiKey($request);
        $expires = $key?->getAttribute('expires_at');

        return $this->response->raw([
            'type' => 'api_key',
            'id' => $key !== null ? (int) $key->getKey() : null,
            'name' => $key?->getAttribute('name'),
            'scopes' => $this->caller->scopes($request),
            'expires_at' => $expires instanceof \DateTimeInterface ? CarbonImmutable::instance($expires)->utc()->toIso8601ZuluString('millisecond') : null,
            'organization_id' => $this->caller->organizationId($request),
        ]);
    }

    public function syncStatus(Request $request): JsonResponse
    {
        $organizationId = $this->caller->organizationId($request);
        $connections = ImmowareConnection::query()->withoutGlobalScopes()
            ->when($organizationId !== null, static fn ($q) => $q->where('organization_id', $organizationId))
            ->oldest('id')
            ->get(['id', 'name', 'connector_type', 'purpose', 'status', 'degraded_reason', 'write_enabled', 'last_health_at', 'last_health_ok']);

        $now = CarbonImmutable::now();
        $result = [];

        foreach ($connections as $connection) {
            $states = SyncState::query()
                ->where('connection_id', $connection->getKey())
                ->where('scope', 'collection')
                ->get()
                ->filter(static fn (SyncState $state): bool => $state->getAttribute('entity_type') !== null);

            $entities = [];

            foreach ($states as $state) {
                $entityType = (string) $state->getAttribute('entity_type');
                $lastSuccess = $state->getAttribute('last_success_at');
                $lastSuccess = $lastSuccess instanceof \DateTimeInterface ? CarbonImmutable::instance($lastSuccess) : null;
                $age = $lastSuccess !== null ? (int) $lastSuccess->diffInSeconds($now, true) : null;
                $threshold = $this->provenance->thresholdSeconds($entityType);

                $entities[] = [
                    'entity_type' => $entityType,
                    'last_success_at' => $lastSuccess?->utc()->toIso8601ZuluString('millisecond'),
                    'last_failure_at' => $this->iso($state->getAttribute('last_failure_at')),
                    'data_age_seconds' => $age,
                    'threshold_seconds' => $threshold,
                    'stale' => $age === null || $age > $threshold,
                    'stale_since' => $this->iso($state->getAttribute('stale_since')),
                ];
            }

            $lastRun = SyncRun::query()->where('connection_id', $connection->getKey())->latest('started_at')->first();

            $result[] = [
                'connection_id' => (int) $connection->getKey(),
                'name' => $connection->getAttribute('name'),
                'connector_type' => $connection->getAttribute('connector_type'),
                'purpose' => $connection->getAttribute('purpose'),
                'status' => $connection->getAttribute('status'),
                'degraded_reason' => $connection->getAttribute('degraded_reason'),
                'write_enabled' => (bool) $connection->getAttribute('write_enabled'),
                'last_health_at' => $this->iso($connection->getAttribute('last_health_at')),
                'last_health_ok' => $connection->getAttribute('last_health_ok'),
                'last_run' => $lastRun !== null ? [
                    'id' => (int) $lastRun->getKey(),
                    'run_type' => $lastRun->getAttribute('run_type'),
                    'status' => $lastRun->getAttribute('status') instanceof \BackedEnum ? $lastRun->getAttribute('status')->value : $lastRun->getAttribute('status'),
                    'started_at' => $this->iso($lastRun->getAttribute('started_at')),
                    'finished_at' => $this->iso($lastRun->getAttribute('finished_at')),
                ] : null,
                'entities' => $entities,
            ];
        }

        return $this->response->raw(['connections' => $result, 'generated_at' => $now->toIso8601ZuluString('millisecond')]);
    }

    public function capabilities(Request $request): JsonResponse
    {
        $organizationId = $this->caller->organizationId($request);
        $rows = Capability::query()
            ->whereHas('connection', static function ($q) use ($organizationId): void {
                $q->withoutGlobalScopes();

                if ($organizationId !== null) {
                    $q->where('organization_id', $organizationId);
                }
            })
            ->oldest('connection_id')
            ->oldest('capability_key')
            ->get();

        $items = [];

        foreach ($rows as $row) {
            $status = $row->getAttribute('evidence_status');
            $statusValue = $status instanceof \BackedEnum ? (string) $status->value : (string) $status;
            $hardLocked = (bool) $row->getAttribute('hard_locked');

            $items[] = [
                'connection_id' => (int) $row->getAttribute('connection_id'),
                'capability_key' => $row->getAttribute('capability_key'),
                'evidence_status' => $statusValue,
                'source_url' => $row->getAttribute('source_url'),
                'enabled' => ! $hardLocked && (bool) $row->getAttribute('enabled'),
                // Fähigkeiten mit Status documented gelten nie als verfügbar.
                'available' => ! $hardLocked && (bool) $row->getAttribute('enabled') && in_array($statusValue, ['verified', 'tested'], true),
                'hard_locked' => $hardLocked,
                'tested_at' => $this->iso($row->getAttribute('tested_at')),
            ];
        }

        $registry = $this->container->bound(CapabilityRegistryInterface::class) ? $this->container->make(CapabilityRegistryInterface::class)->all() : [];

        return $this->response->raw(['capabilities' => $items, 'registry' => $registry]);
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601ZuluString('millisecond') : null;
    }
}
