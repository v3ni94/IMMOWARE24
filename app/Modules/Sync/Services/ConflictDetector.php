<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Enums\ConflictState;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;

/**
 * Vorbereitung für bidirektionale Entitäten (aktuell keine). Vergleicht die Prüfsumme des letzten
 * Syncs (Basis) mit lokaler und entfernter Prüfsumme. BOTH_CHANGED erzeugt einen conflicts-Eintrag,
 * der entfernte Wert darf dann nicht automatisch übernommen werden.
 */
final class ConflictDetector
{
    public const string TYPE_LOCAL_VS_REMOTE = 'local_change_vs_remote';

    public function state(?string $baseChecksum, ?string $localChecksum, ?string $remoteChecksum): ConflictState
    {
        $localChanged = $baseChecksum !== null && $localChecksum !== null && $localChecksum !== $baseChecksum;
        $remoteChanged = $baseChecksum === null ? $remoteChecksum !== $localChecksum : ($remoteChecksum !== null && $remoteChecksum !== $baseChecksum);

        return match (true) {
            $localChanged && $remoteChanged => ConflictState::BothChanged,
            $localChanged => ConflictState::LocalNewer,
            $remoteChanged => ConflictState::RemoteNewer,
            default => ConflictState::NoConflict,
        };
    }

    /**
     * Bewertet einen Datensatz und legt bei BOTH_CHANGED einen Konflikt an bzw. aktualisiert den offenen Eintrag.
     *
     * @param  array<string, mixed>|null  $localSnapshot
     * @return array{state: ConflictState, apply_remote: bool, conflict: Conflict|null}
     */
    public function evaluate(
        int $connectionId,
        string $entityType,
        int $entityId,
        ?string $baseChecksum,
        ?string $localChecksum,
        ?string $remoteChecksum,
        ?SyncRun $run = null,
        ?array $localSnapshot = null,
        ?int $remotePayloadId = null,
    ): array {
        $state = $this->state($baseChecksum, $localChecksum, $remoteChecksum);

        if ($state !== ConflictState::BothChanged) {
            return ['state' => $state, 'apply_remote' => $state === ConflictState::RemoteNewer, 'conflict' => null];
        }

        $conflict = $this->upsertOpen($connectionId, $entityType, $entityId, $run, $localSnapshot, $remotePayloadId);

        return ['state' => $state, 'apply_remote' => false, 'conflict' => $conflict];
    }

    /**
     * @param  array<string, mixed>|null  $localSnapshot
     */
    private function upsertOpen(int $connectionId, string $entityType, int $entityId, ?SyncRun $run, ?array $localSnapshot, ?int $remotePayloadId): Conflict
    {
        $now = CarbonImmutable::now();

        /** @var Conflict|null $existing */
        $existing = Conflict::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('conflict_type', self::TYPE_LOCAL_VS_REMOTE)
            ->whereIn('status', Conflict::OPEN_STATUSES)
            ->first();

        if ($existing !== null) {
            $existing->forceFill([
                'occurrences' => ((int) $existing->getAttribute('occurrences')) + 1,
                'last_seen_run_id' => $run?->getKey(),
                'last_seen_at' => $now,
                'remote_payload_id' => $remotePayloadId ?? $existing->getAttribute('remote_payload_id'),
            ]);
            $existing->save();

            return $existing;
        }

        $conflict = new Conflict;
        $conflict->forceFill([
            'connection_id' => $connectionId,
            'sync_run_id' => $run?->getKey(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'conflict_type' => self::TYPE_LOCAL_VS_REMOTE,
            'conflict_state' => ConflictState::BothChanged,
            'remote_payload_id' => $remotePayloadId,
            'local_snapshot_json' => $localSnapshot,
            'status' => 'open',
            'occurrences' => 1,
            'last_seen_run_id' => $run?->getKey(),
            'last_seen_at' => $now,
        ]);
        $conflict->save();

        return $conflict;
    }
}
