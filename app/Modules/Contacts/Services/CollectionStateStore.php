<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Modules\Sync\Models\SyncState;
use Carbon\CarbonImmutable;

/**
 * Persistiert den Collection-Zustand (CTag, sync-token, Strategie) und die je Ressource zuletzt
 * gesehenen ETags in sync_states (Scope collection bzw. resource). Kein Hard Delete: fehlende Ressourcen
 * werden über consecutive_missing gezählt.
 */
final class CollectionStateStore
{
    public const string SCOPE_COLLECTION = 'collection';

    public const string SCOPE_RESOURCE = 'resource';

    public static function pathHash(string $collectionPath): string
    {
        return hash('sha256', rtrim($collectionPath, '/').'/');
    }

    public static function hrefHash(string $href): string
    {
        return hash('sha256', $href);
    }

    public function collection(int $connectionId, string $entityType, string $collectionPath): SyncState
    {
        return SyncState::query()->firstOrCreate([
            'connection_id' => $connectionId,
            'scope' => self::SCOPE_COLLECTION,
            'collection_path_hash' => self::pathHash($collectionPath),
            'resource_external_id_hash' => '',
        ], ['entity_type' => $entityType]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function commitCollection(SyncState $state, array $attributes): void
    {
        $state->fill($attributes + ['last_synced_at' => CarbonImmutable::now()]);
        $state->save();
    }

    /**
     * Bekannte ETags je href. Speicherbedarf bei 250.000 Kontakten: zwei Strings je Eintrag, akzeptabel.
     *
     * @return array<string, array{etag: string|null, missing: int, id: int}>
     */
    public function knownResources(int $connectionId, string $collectionPath): array
    {
        $known = [];

        $rows = SyncState::query()
            ->where('connection_id', $connectionId)
            ->where('scope', self::SCOPE_RESOURCE)
            ->where('collection_path_hash', self::pathHash($collectionPath))
            ->select(['id', 'etag', 'consecutive_missing', 'cursor_json'])
            ->lazyById(500);

        /** @var SyncState $state */
        foreach ($rows as $state) {
            $href = (string) (($state->cursor_json ?? [])['href'] ?? '');

            if ($href !== '') {
                $known[$href] = ['etag' => $state->etag, 'missing' => (int) $state->consecutive_missing, 'id' => (int) $state->getKey()];
            }
        }

        return $known;
    }

    public function rememberResource(int $connectionId, string $entityType, string $collectionPath, string $href, ?string $etag, string $externalId): void
    {
        SyncState::query()->updateOrCreate([
            'connection_id' => $connectionId,
            'scope' => self::SCOPE_RESOURCE,
            'collection_path_hash' => self::pathHash($collectionPath),
            'resource_external_id_hash' => self::hrefHash($href),
        ], [
            'entity_type' => $entityType,
            'etag' => $etag,
            'consecutive_missing' => 0,
            'cursor_json' => ['href' => $href, 'external_id_hash' => hash('sha256', $externalId)],
            'last_synced_at' => CarbonImmutable::now(),
        ]);
    }

    public function markSeen(int $connectionId, string $collectionPath, string $href): void
    {
        SyncState::query()
            ->where('connection_id', $connectionId)
            ->where('scope', self::SCOPE_RESOURCE)
            ->where('collection_path_hash', self::pathHash($collectionPath))
            ->where('resource_external_id_hash', self::hrefHash($href))
            ->update(['consecutive_missing' => 0, 'last_synced_at' => CarbonImmutable::now()]);
    }

    /**
     * Zählt ein Fehlen. Ohne State-Zeile (z. B. Ressource aus einer älteren Version des Spiegels) wird die Zeile mit
     * consecutive_missing = 1 angelegt, damit das zweite Fehlen im Folgelauf zählbar ist (Änderungsvermerk 12.09.2026).
     *
     * @return int neue Anzahl aufeinanderfolgender Fehlzeiten
     */
    public function markMissing(int $connectionId, string $collectionPath, string $href, ?string $entityType = null): int
    {
        $state = SyncState::query()
            ->where('connection_id', $connectionId)
            ->where('scope', self::SCOPE_RESOURCE)
            ->where('collection_path_hash', self::pathHash($collectionPath))
            ->where('resource_external_id_hash', self::hrefHash($href))
            ->first();

        if ($state === null) {
            SyncState::query()->create([
                'connection_id' => $connectionId,
                'entity_type' => $entityType,
                'scope' => self::SCOPE_RESOURCE,
                'collection_path_hash' => self::pathHash($collectionPath),
                'resource_external_id_hash' => self::hrefHash($href),
                'consecutive_missing' => 1,
                'cursor_json' => ['href' => $href],
            ]);

            return 1;
        }

        $state->consecutive_missing = min(255, (int) $state->consecutive_missing + 1);
        $state->save();

        return (int) $state->consecutive_missing;
    }
}
