<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\FieldMapping;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Herkunftsblock jeder API-Ressource: source_system, external_id, last_synced_at, connector,
 * mapping_version, data_age_seconds, stale. Connector- und Mapping-Auflösung werden je Request gecacht.
 */
final class Provenance
{
    /** @var array<int, string|null> */
    private array $connectors = [];

    /** @var array<string, int|null> */
    private array $mappingVersions = [];

    /**
     * @return array{source_system: string, external_id: string|null, last_synced_at: string|null, connector: string|null, mapping_version: int|null, data_age_seconds: int|null, stale: bool}
     */
    public function for(Model $model, ResourceDefinition $definition): array
    {
        $sourceSystem = (string) ($model->getAttribute('source_system') ?? ($definition->hubOwned ? 'hub' : 'immoware24'));
        $lastSynced = $model->getAttribute('last_synced_at');
        $lastSynced = $lastSynced instanceof \DateTimeInterface ? CarbonImmutable::instance($lastSynced) : null;

        $age = $lastSynced !== null ? (int) $lastSynced->diffInSeconds(CarbonImmutable::now(), true) : null;
        $threshold = $this->thresholdSeconds($definition->entityType);

        $stale = false;

        if (! $definition->hubOwned && $sourceSystem !== 'hub') {
            $stale = $age === null || $age > $threshold || $model->getAttribute('stale_since') !== null;
        }

        $connectionId = $model->getAttribute('connection_id');

        return [
            'source_system' => $sourceSystem,
            'external_id' => $definition->hubOwned ? null : $model->getAttribute('external_id'),
            'last_synced_at' => $lastSynced?->toIso8601ZuluString('millisecond'),
            'connector' => $connectionId !== null ? $this->connector((int) $connectionId) : ($definition->hubOwned ? null : $definition->accessPath),
            'mapping_version' => $definition->hubOwned ? null : $this->mappingVersion($definition->entityType),
            'data_age_seconds' => $age,
            'stale' => $stale,
        ];
    }

    public function thresholdSeconds(string $entityType): int
    {
        $thresholds = (array) config('hub.sync.stale_after_seconds', []);

        return (int) ($thresholds[$entityType] ?? $thresholds['default'] ?? config('hub.api.health.stale_after_seconds', 86400));
    }

    private function connector(int $connectionId): ?string
    {
        if (! array_key_exists($connectionId, $this->connectors)) {
            $connection = ImmowareConnection::query()->withoutGlobalScopes()->find($connectionId, ['id', 'connector_type']);
            $this->connectors[$connectionId] = $connection !== null ? (string) $connection->getAttribute('connector_type') : null;
        }

        return $this->connectors[$connectionId];
    }

    private function mappingVersion(string $entityType): ?int
    {
        if (! array_key_exists($entityType, $this->mappingVersions)) {
            $version = null;

            if (class_exists(FieldMapping::class)) {
                $version = FieldMapping::query()
                    ->where('entity_type', $entityType)
                    ->where('status', 'active')
                    ->max('version');
            }

            $this->mappingVersions[$entityType] = $version !== null ? (int) $version : null;
        }

        return $this->mappingVersions[$entityType];
    }
}
