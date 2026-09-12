<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Support\SecretMasker;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * sync_states auf Collection-Ebene: Cursor, letzter Erfolg, CTag, sync-token je Connection und Entität.
 * Cursor werden nur in finalize committet (Transaktion mit Sperre).
 */
final class SyncStateService
{
    public function __construct(
        private readonly SecretMasker $masker,
    ) {}

    public static function collectionPathHash(string $entityType): string
    {
        return hash('sha256', 'entity:'.$entityType);
    }

    public function forEntity(int $connectionId, string $entityType): SyncState
    {
        $state = SyncState::query()
            ->where('connection_id', $connectionId)
            ->where('scope', SyncState::SCOPE_COLLECTION)
            ->where('collection_path_hash', self::collectionPathHash($entityType))
            ->where('resource_external_id_hash', '')
            ->first();

        if ($state instanceof SyncState) {
            return $state;
        }

        $state = new SyncState;
        $state->forceFill([
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'scope' => SyncState::SCOPE_COLLECTION,
            'collection_path_hash' => self::collectionPathHash($entityType),
            'resource_external_id_hash' => '',
            'consecutive_missing' => 0,
        ]);
        $state->save();

        return $state;
    }

    public function find(int $connectionId, string $entityType): ?SyncState
    {
        return SyncState::query()
            ->where('connection_id', $connectionId)
            ->where('scope', SyncState::SCOPE_COLLECTION)
            ->where('collection_path_hash', self::collectionPathHash($entityType))
            ->where('resource_external_id_hash', '')
            ->first();
    }

    public function cursor(SyncState $state): ?string
    {
        $cursor = $state->getAttribute('cursor_json');

        return is_array($cursor) && isset($cursor['cursor']) && is_string($cursor['cursor']) ? $cursor['cursor'] : null;
    }

    /**
     * Commit des Cursors und der Token in finalize. Der Erfolg setzt stale_since zurück.
     *
     * @param  array<string, string|null>  $tokens  Schlüssel: sync_token, ctag, etag
     */
    public function commitSuccess(SyncState $state, SyncRun $run, ?string $cursor, array $tokens = []): SyncState
    {
        DB::transaction(function () use ($state, $run, $cursor, $tokens): void {
            /** @var SyncState $locked */
            $locked = SyncState::query()->lockForUpdate()->findOrFail($state->getKey());
            $now = CarbonImmutable::now();

            $locked->forceFill([
                'cursor_json' => $cursor !== null ? ['cursor' => $cursor] : null,
                'last_synced_at' => $now,
                'last_success_at' => $now,
                'last_run_id' => $run->getKey(),
                'last_seen_run_id' => $run->getKey(),
                'stale_since' => null,
                'last_error' => null,
            ]);

            foreach (['sync_token', 'ctag', 'etag'] as $key) {
                if (array_key_exists($key, $tokens)) {
                    $locked->setAttribute($key, $tokens[$key]);
                }
            }

            $locked->save();
            $state->setRawAttributes($locked->getAttributes(), true);
        });

        return $state;
    }

    public function recordFailure(SyncState $state, SyncRun $run, string $error): SyncState
    {
        $state->forceFill([
            'last_failure_at' => CarbonImmutable::now(),
            'last_error' => mb_substr($this->masker->maskString($error), 0, 4000),
            'last_run_id' => $run->getKey(),
        ]);
        $state->save();

        return $state;
    }

    /**
     * Ungültiges Token: Cursor verwerfen (voller Depth-1-Vergleich im nächsten Lauf).
     */
    public function resetCursor(SyncState $state): SyncState
    {
        $state->forceFill(['cursor_json' => null, 'sync_token' => null]);
        $state->save();

        return $state;
    }
}
