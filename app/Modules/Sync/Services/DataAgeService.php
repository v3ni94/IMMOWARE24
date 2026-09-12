<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Models\SyncState;
use Carbon\CarbonImmutable;

/**
 * Datenalter je Connection und Entität für API und UI; setzt stale_since, wenn der letzte Erfolg
 * älter als die konfigurierte Schwelle ist.
 */
final class DataAgeService
{
    public function __construct(
        private readonly SyncStateService $states,
    ) {}

    public function thresholdSeconds(string $entityType): int
    {
        $thresholds = (array) config('hub.sync.stale_after_seconds', []);

        return (int) ($thresholds[$entityType] ?? $thresholds['default'] ?? 86400);
    }

    /**
     * @return array{connection_id: int, entity_type: string, last_success_at: string|null, age_seconds: int|null, threshold_seconds: int, stale: bool, stale_since: string|null, cursor_present: bool}
     */
    public function for(int $connectionId, string $entityType): array
    {
        $state = $this->states->find($connectionId, $entityType);
        $now = CarbonImmutable::now();
        $threshold = $this->thresholdSeconds($entityType);

        $lastSuccess = $state?->getAttribute('last_success_at');
        $lastSuccess = $lastSuccess instanceof CarbonImmutable ? $lastSuccess : null;
        $age = $lastSuccess !== null ? (int) $lastSuccess->diffInSeconds($now, true) : null;
        $staleSince = $state?->getAttribute('stale_since');

        return [
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'last_success_at' => $lastSuccess?->toIso8601String(),
            'age_seconds' => $age,
            'threshold_seconds' => $threshold,
            'stale' => $age === null || $age > $threshold,
            'stale_since' => $staleSince instanceof CarbonImmutable ? $staleSince->toIso8601String() : null,
            'cursor_present' => $state !== null && $this->states->cursor($state) !== null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forConnection(int $connectionId): array
    {
        return array_map(fn (SyncEntity $entity): array => $this->for($connectionId, $entity->value), SyncEntity::cases());
    }

    /**
     * Setzt stale_since auf allen Collection-States, deren letzter Erfolg älter als die Schwelle ist.
     *
     * @return int Anzahl neu als veraltet markierter Zustände
     */
    public function refreshStaleness(): int
    {
        $now = CarbonImmutable::now();
        $marked = 0;

        SyncState::query()->collections()->whereNull('stale_since')->lazyById(200)->each(function (SyncState $state) use ($now, &$marked): void {
            $entityType = (string) ($state->getAttribute('entity_type') ?? 'default');
            $threshold = $this->thresholdSeconds($entityType);
            $lastSuccess = $state->getAttribute('last_success_at');
            $reference = $lastSuccess instanceof CarbonImmutable ? $lastSuccess : $state->getAttribute('created_at');

            if (! $reference instanceof \DateTimeInterface) {
                return;
            }

            $referenceTime = CarbonImmutable::instance($reference);

            if ($referenceTime->diffInSeconds($now, true) > $threshold) {
                $state->forceFill(['stale_since' => $referenceTime->addSeconds($threshold)]);
                $state->save();
                $marked++;
            }
        });

        return $marked;
    }
}
