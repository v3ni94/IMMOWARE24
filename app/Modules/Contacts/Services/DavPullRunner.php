<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Contracts\DavMirrorHandlerInterface;
use App\Modules\Contacts\Dav\AbstractDavClient;
use App\Modules\Sync\Models\SyncState;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gemeinsame Pull-Strategie für CardDAV und CalDAV (07-sync-strategy.md Abschnitt 2):
 * 1. PROPFIND Depth 0: CTag, sync-token, supported-report-set.
 * 2. Incremental und Probe meldet sync-token: REPORT sync-collection, nur Änderungen, kein Sweep.
 * 3. Sonst CTag unverändert: Lauf endet ohne weitere Requests.
 * 4. Sonst ETag-Liste, Diff gegen bekannte ETags, Multiget nur für geänderte oder unbekannte hrefs,
 *    danach Mark-and-Sweep (Soft Delete). Full lädt alle Ressourcen und ignoriert CTag und ETags.
 * Token und CTag werden erst nach erfolgreichem Lauf committet.
 *
 * Cursor-Vertrag (Änderungsvermerk 12.09.2026): Jeder Durchlauf verarbeitet die Collection vollständig und
 * liefert cursor: null. sync-token und CTag sind keine Fortsetzungscursor und werden nur in SyncResult::tokens
 * gemeldet und über den CollectionStateStore persistiert. Ein Token als Cursor ließ RunSyncJob die Collection
 * je Lauf max_per_run-fach enumerieren und sich endlos neu einplanen.
 */
final class DavPullRunner
{
    public const string STRATEGY_SYNC_TOKEN = 'sync_token';

    public const string STRATEGY_CTAG_ETAG = 'ctag_etag';

    public const string STRATEGY_ETAG_ONLY = 'etag_only';

    public const string STRATEGY_FULL = 'full_hash';

    public function __construct(private readonly CollectionStateStore $states) {}

    public function run(ImmowareConnection $connection, AbstractDavClient $client, DavMirrorHandlerInterface $handler, SyncRequest $request): SyncResult
    {
        $connectionId = (int) $connection->getKey();
        $path = $client->collectionPath();
        $info = $client->propfindCollection();
        $state = $this->states->collection($connectionId, $request->entityType, $path);

        $useSyncToken = $request->mode !== SyncMode::Full
            && $this->probeReportsSyncToken($connection)
            && $info->supportsSyncCollection()
            && $info->syncToken !== null;

        if ($useSyncToken) {
            $result = $this->runSyncCollection($connectionId, $client, $handler, $request, $state);

            if ($result !== null) {
                return $result;
            }

            Log::warning('sync-token ungültig, Rückfall auf ETag-Vergleich', ['connection_id' => $connectionId]);
        }

        if ($request->mode !== SyncMode::Full && $info->ctag !== null && $state->ctag === $info->ctag) {
            $this->states->commitCollection($state, ['strategy' => self::STRATEGY_CTAG_ETAG]);

            return new SyncResult(tokens: ['ctag' => $info->ctag, 'sync_token' => $info->syncToken]);
        }

        $remote = $client->listEtags();
        $known = $request->mode === SyncMode::Full ? [] : $this->states->knownResources($connectionId, $path);

        $toLoad = [];
        foreach ($remote as $href => $etag) {
            $knownEtag = $known[$href]['etag'] ?? null;

            if ($request->mode === SyncMode::Full || $etag === null || $knownEtag === null || $knownEtag !== $etag) {
                $toLoad[] = $href;
            } else {
                $this->states->markSeen($connectionId, $path, $href);
            }
        }

        $result = $this->loadAndHandle($connectionId, $client, $handler, $request->entityType, $path, $toLoad);

        $seen = array_fill_keys(array_keys($remote), true);
        $swept = $handler->sweep($seen);

        $strategy = $request->mode === SyncMode::Full ? self::STRATEGY_FULL : ($info->ctag !== null ? self::STRATEGY_CTAG_ETAG : self::STRATEGY_ETAG_ONLY);
        $this->states->commitCollection($state, ['ctag' => $info->ctag, 'sync_token' => $info->syncToken, 'strategy' => $strategy]);

        return $result->merge(new SyncResult(deleted: $swept['deleted'], tokens: ['ctag' => $info->ctag, 'sync_token' => $info->syncToken]));
    }

    private function runSyncCollection(int $connectionId, AbstractDavClient $client, DavMirrorHandlerInterface $handler, SyncRequest $request, SyncState $state): ?SyncResult
    {
        // Der Token stammt ausschließlich aus dem Collection-State, nie aus dem Seiten-Cursor des Orchestrators.
        $token = $state->sync_token;
        $path = $client->collectionPath();

        if ($token === null) {
            return null;
        }

        $delta = $client->syncCollection($token);

        if ($delta === null) {
            $this->states->commitCollection($state, ['sync_token' => null]);

            return null;
        }

        $result = $this->loadAndHandle($connectionId, $client, $handler, $request->entityType, $path, array_keys($delta['changed']));

        foreach ($delta['deleted'] as $href) {
            $handler->handleRemoved($href);
            $this->states->markMissing($connectionId, $path, $href);
        }

        $this->states->commitCollection($state, ['sync_token' => $delta['token'] ?? $token, 'strategy' => self::STRATEGY_SYNC_TOKEN]);

        return $result->merge(new SyncResult(tokens: ['sync_token' => $delta['token'] ?? $token]));
    }

    /**
     * @param  array<int, string>  $hrefs
     */
    private function loadAndHandle(int $connectionId, AbstractDavClient $client, DavMirrorHandlerInterface $handler, string $entityType, string $path, array $hrefs): SyncResult
    {
        $result = SyncResult::empty();

        if ($hrefs === []) {
            return $result;
        }

        foreach ($client->multiget($hrefs) as $resource) {
            if ($resource->isNotFound() || $resource->data === null) {
                $result = $result->merge(new SyncResult(processed: 1, failed: 1, errors: [['href' => $resource->href, 'error' => 'Ressource ohne Nutzdaten oder nicht gefunden (Status '.($resource->status ?? 'unbekannt').').']]));

                continue;
            }

            try {
                $outcome = $handler->handleResource($resource);

                foreach ($outcome['external_ids'] as $externalId) {
                    $this->states->rememberResource($connectionId, $entityType, $path, $resource->href, $resource->etag, $externalId);
                }

                $result = $result->merge(new SyncResult(processed: 1, created: $outcome['created'], updated: $outcome['updated']));
            } catch (Throwable $e) {
                Log::warning('DAV-Ressource konnte nicht verarbeitet werden', ['connection_id' => $connectionId, 'href' => $resource->href, 'error' => $e->getMessage()]);
                $result = $result->merge(new SyncResult(processed: 1, failed: 1, errors: [['href' => $resource->href, 'error' => $e->getMessage()]]));
            }
        }

        return $result;
    }

    private function probeReportsSyncToken(ImmowareConnection $connection): bool
    {
        /** @var array<string, mixed>|null $probe */
        $probe = $connection->getAttribute('probe_result');

        return (bool) ($probe['sync_token_supported'] ?? $probe['sync_token'] ?? false);
    }
}
