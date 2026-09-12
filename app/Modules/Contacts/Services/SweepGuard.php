<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\Enums\ConflictState;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\Conflict;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;

/**
 * Schutzgrenze und Bedingungen des Mark-and-Sweep für CardDAV- und CalDAV-Collections
 * (07-sync-strategy.md Abschnitt 4 Punkte 3 bis 6, Änderungsvermerk 12.09.2026):
 * Soft Delete erst ab required_misses aufeinanderfolgenden vollständigen Läufen (Default 2) und nur bei bestätigtem
 * Health-Check. Fehlen mehr als max_missing_ratio (ab min_count_for_ratio Einträgen) oder mehr als max_missing_count
 * Ressourcen, wird nichts gelöscht, die Connection erhält degraded_reason mass_missing und einen Konflikt
 * uncertain_identity auf Collection-Ebene.
 */
final class SweepGuard
{
    public const string DEGRADED_MASS_MISSING = 'mass_missing';

    public const string CONFLICT_MASS_MISSING = 'uncertain_identity';

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * @param  string  $configPrefix  hub.contacts.sweep oder hub.calendar.sweep
     */
    public function requiredMisses(string $configPrefix): int
    {
        return max(1, (int) $this->config->get($configPrefix.'.required_misses', 2));
    }

    public function exceeded(string $configPrefix, int $missing, int $total): bool
    {
        if ($missing <= 0) {
            return false;
        }

        if ($missing > (int) $this->config->get($configPrefix.'.max_missing_count', 500)) {
            return true;
        }

        $minCount = (int) $this->config->get($configPrefix.'.min_count_for_ratio', 10);
        $ratio = (float) $this->config->get($configPrefix.'.max_missing_ratio', 0.2);

        return $total >= $minCount && $total > 0 && ($missing / $total) > $ratio;
    }

    /**
     * Massenfehlen: Connection degraded, ein offener Konflikt je Connection, Entität und Collection.
     */
    public function recordMassMissing(int $connectionId, string $entityType, string $collectionPath, int $missing, int $total, ?int $syncRunId = null): void
    {
        $now = CarbonImmutable::now();

        ImmowareConnection::query()
            ->withoutGlobalScopes()
            ->whereKey($connectionId)
            ->where('status', 'active')
            ->update(['status' => 'degraded', 'degraded_reason' => self::DEGRADED_MASS_MISSING]);

        $existing = Conflict::query()
            ->where('connection_id', $connectionId)
            ->where('entity_type', $entityType.'_collection')
            ->where('entity_id', $connectionId)
            ->where('conflict_type', self::CONFLICT_MASS_MISSING)
            ->where('open_key', true)
            ->first();

        if ($existing instanceof Conflict) {
            $existing->forceFill([
                'occurrences' => (int) $existing->getAttribute('occurrences') + 1,
                'last_seen_run_id' => $syncRunId,
                'last_seen_at' => $now,
                'local_snapshot_json' => ['collection_path' => $collectionPath, 'missing' => $missing, 'total' => $total],
            ])->save();
        } else {
            $conflict = new Conflict;
            $conflict->forceFill([
                'connection_id' => $connectionId,
                'sync_run_id' => $syncRunId,
                'entity_type' => $entityType.'_collection',
                'entity_id' => $connectionId,
                'conflict_type' => self::CONFLICT_MASS_MISSING,
                'conflict_state' => ConflictState::RemoteNewer,
                'local_snapshot_json' => ['collection_path' => $collectionPath, 'missing' => $missing, 'total' => $total],
                'status' => 'open',
                'open_key' => true,
                'occurrences' => 1,
                'last_seen_run_id' => $syncRunId,
                'last_seen_at' => $now,
            ]);
            $conflict->save();
        }

        Log::warning('Sweep blockiert: Schutzgrenze überschritten, Connection degraded.', [
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'collection_path' => $collectionPath,
            'missing' => $missing,
            'total' => $total,
            'reason' => self::DEGRADED_MASS_MISSING,
        ]);
    }
}
