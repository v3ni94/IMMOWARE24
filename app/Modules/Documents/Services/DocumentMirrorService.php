<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\Enums\ConflictState;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\DTO\FolderScanResult;
use App\Modules\Documents\DTO\ScanContext;
use App\Modules\Documents\Http\PropfindResult;
use App\Modules\Documents\Http\WebDavClient;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Documents\Support\DavEntry;
use App\Modules\Documents\Support\DocumentAssignmentResolver;
use App\Modules\Documents\Support\DocumentTypeClassifier;
use App\Modules\Documents\Support\WebDavPath;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\SyncEvent;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dokumentenspiegel: PROPFIND Depth 1 je Ordner, Upsert von document_folders und documents über
 * external_id = normalisierter Pfad, Änderungserkennung (ETag bei etag_stable, sonst getlastmodified plus
 * Größe, optional SHA-256 des Inhalts), Mark-and-Sweep je Ordner (Soft Delete, nie Hard Delete) und
 * Move-Erkennung über identischen content_hash. Es wird kein Dateiinhalt gespeichert (document_reference).
 */
final class DocumentMirrorService
{
    public const string ORIGIN_REMOTE = 'remote';

    public const string ORIGIN_HUB_UPLOAD = 'hub_upload';

    /** deletion_reason nach zweitem Fehlen in einem gesunden Folgelauf (07-sync-strategy.md Abschnitt 4 Punkt 4). */
    public const string DELETION_MISSING_TWICE = 'missing_twice';

    public const string DELETION_FOLDER_REMOVED = 'folder_removed';

    /** degraded_reason der Connection bei Überschreiten der Schutzgrenze (07 Abschnitt 4 Punkt 6). */
    public const string DEGRADED_MASS_MISSING = 'mass_missing';

    /** conflict_type auf Collection-Ebene bei Überschreiten der Schutzgrenze. */
    public const string CONFLICT_MASS_MISSING = 'uncertain_identity';

    /** conflict_type, wenn eine Depth-1-Antwort auf max_entries_per_folder gekappt wurde (kein Sweep möglich). */
    public const string CONFLICT_LISTING_TRUNCATED = 'listing_truncated';

    /** degraded_reason der Connection bei 404 auf Ordner-Ebene (07-sync-strategy.md Abschnitt 6.1). */
    public const string DEGRADED_FOLDER_MISSING = 'folder_missing';

    /** conflict_type für einen per 404 nicht mehr erreichbaren Ordner. */
    public const string CONFLICT_FOLDER_MISSING = 'folder_missing';

    /** payload_type der archivierten PROPFIND-Antworten (02-data-model.md, AP 3.7); Quelle für hub:replay document. */
    public const string PAYLOAD_TYPE_PROPFIND = 'propfind_xml';

    public function __construct(
        private readonly DocumentTypeClassifier $classifier,
        private readonly DocumentAssignmentResolver $assignments,
        private readonly WebhookDispatcherInterface $webhooks,
        private readonly ExternalPayloadArchiver $payloads,
    ) {}

    /**
     * Scannt genau einen Ordner (Depth 1), aktualisiert Ordner- und Dokumentzeilen und führt den Sweep aus.
     */
    public function scanFolder(WebDavClient $client, string $folderPath, ScanContext $context): FolderScanResult
    {
        $folderPath = WebDavPath::normalize($folderPath, true);
        $scanStartedAt = CarbonImmutable::now();

        $result = $client->propfind($folderPath, 1);

        if (! $result->isMultistatus()) {
            $this->markFolderUnreachable($folderPath, $result->status, $context, $scanStartedAt);

            return new FolderScanResult(
                path: $folderPath,
                status: $result->status,
                enumerated: false,
                failed: 1,
                errors: [['path' => $folderPath, 'status' => $result->status, 'reason' => $result->isNotFound() ? 'folder_not_found' : 'folder_unreachable']],
            );
        }

        $this->archivePropfind($client, $result, $folderPath, $context);

        $folder = $this->upsertFolder($folderPath, $context, $result->self(), $scanStartedAt);

        $allChildren = $result->children();
        // Kappung: alles oberhalb der Grenze wird nicht verarbeitet. Die Auflistung ist dann unvollständig und darf
        // keinen Sweep auslösen, sonst würden nie gelistete Dateien dauerhaft soft-gelöscht (Änderungsvermerk 12.09.2026).
        $truncated = count($allChildren) > $context->maxEntriesPerFolder;
        $children = $truncated ? array_slice($allChildren, 0, $context->maxEntriesPerFolder) : $allChildren;
        $childFolders = [];
        $seenFolderIds = [];
        $created = $updated = $moved = $unchanged = $failed = 0;
        $fingerprintParts = [];

        foreach ($children as $entry) {
            $fingerprintParts[] = implode('|', [$entry->name(), (string) $entry->contentLength, (string) $entry->lastModified, (string) $entry->etag]);

            try {
                if ($entry->isCollection) {
                    $child = $this->upsertFolder($entry->path, $context, $entry, null, $folder);
                    $seenFolderIds[] = (int) $child->getKey();
                    $childFolders[] = $entry->path;

                    continue;
                }

                $outcome = $this->upsertDocument($client, $entry, $folder, $context, $scanStartedAt);

                match ($outcome) {
                    'created' => $created++,
                    'updated', 'restored' => $updated++,
                    'moved' => $moved++,
                    default => $unchanged++,
                };
            } catch (Throwable $e) {
                $failed++;
                Log::warning('Dokumenteintrag konnte nicht gespiegelt werden.', [
                    'connection_id' => $context->connectionId,
                    'path' => $entry->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        sort($fingerprintParts, SORT_STRING);
        $fingerprint = hash('sha256', implode("\n", $fingerprintParts));

        if ($folder->getAttribute('children_fingerprint') !== $fingerprint) {
            $folder->setAttribute('children_fingerprint', $fingerprint);
            $folder->setAttribute('last_change_seen_at', $scanStartedAt);
        }

        $folder->setAttribute('last_scanned_at', $scanStartedAt);
        $folder->setAttribute('missing_since', null);
        $folder->save();

        if ($truncated) {
            $this->recordFolderConflict($folder, $context, $scanStartedAt, self::CONFLICT_LISTING_TRUNCATED, [
                'listed' => count($allChildren), 'processed' => count($children), 'max_entries_per_folder' => $context->maxEntriesPerFolder,
            ]);
            Log::warning('Depth-1-Auflistung gekappt: Ordner als truncated markiert, kein Sweep.', [
                'connection_id' => $context->connectionId,
                'folder' => $folderPath,
                'listed' => count($allChildren),
                'limit' => $context->maxEntriesPerFolder,
            ]);
            [$deleted, $sweepBlocked] = [0, true];
        } else {
            [$deleted, $sweepBlocked] = $this->sweep($folder, $seenFolderIds, $scanStartedAt, $context);
        }

        return new FolderScanResult(
            path: $folderPath,
            status: $result->status,
            enumerated: true,
            processed: count($children),
            created: $created,
            updated: $updated,
            deleted: $deleted,
            moved: $moved,
            unchanged: $unchanged,
            failed: $failed,
            childFolders: $childFolders,
            sweepBlocked: $sweepBlocked,
            truncated: $truncated,
        );
    }

    /**
     * AP 3.7: jede erfolgreiche Depth-1-Antwort wandert maskiert und komprimiert in external_payloads
     * (payload_type propfind_xml, external_id = Ordnerpfad, import_metadata path und base_url), damit
     * hub:replay document den Spiegelstand ohne Request an Immoware24 wiederherstellen kann. Ein Fehler beim
     * Archivieren bricht den Scan nicht ab. Abschaltbar über hub.documents.payloads.archive_propfind.
     */
    private function archivePropfind(WebDavClient $client, PropfindResult $result, string $folderPath, ScanContext $context): void
    {
        if ($result->body === null || ! (bool) config('hub.documents.payloads.archive_propfind', true)) {
            return;
        }

        try {
            $this->payloads->archive(
                $context->connectionId,
                self::PAYLOAD_TYPE_PROPFIND,
                $result->body,
                $folderPath,
                $context->syncRunId,
                ['path' => $folderPath, 'base_url' => $client->context()->baseUrl, 'organization_id' => $context->organizationId],
                $result->status,
            );
        } catch (Throwable $e) {
            Log::warning('PROPFIND-Antwort konnte nicht archiviert werden.', [
                'connection_id' => $context->connectionId,
                'folder' => $folderPath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Ordnerzeile anlegen oder aktualisieren (Schlüssel connection_id plus path_hash), Soft Delete aufheben.
     */
    public function upsertFolder(string $path, ScanContext $context, ?DavEntry $entry = null, ?CarbonImmutable $scannedAt = null, ?DocumentFolder $parent = null): DocumentFolder
    {
        $path = WebDavPath::normalize($path, true);
        $pathHash = Document::hashPath($path);

        /** @var DocumentFolder|null $folder */
        $folder = DocumentFolder::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('connection_id', $context->connectionId)
            ->where('path_hash', $pathHash)
            ->first();

        if ($folder === null) {
            $folder = new DocumentFolder;
            $folder->setAttribute('organization_id', $context->organizationId);
            $folder->setAttribute('connection_id', $context->connectionId);
            $folder->setAttribute('path', $path);
            $folder->setAttribute('path_hash', $pathHash);
            $folder->setAttribute('depth', WebDavPath::depth($path));
            $folder->setAttribute('writable_by_hub', false);
        }

        if ($folder->trashed()) {
            $folder->setAttribute('deleted_at', null);
        }

        $folder->setAttribute('missing_since', null);

        if ($parent !== null) {
            $folder->setAttribute('parent_id', $parent->getKey());
        } elseif ($folder->getAttribute('parent_id') === null) {
            $parentPath = WebDavPath::parent($path);

            if ($parentPath !== null && $parentPath !== '/') {
                $parentId = DocumentFolder::query()
                    ->withoutGlobalScopes()
                    ->where('connection_id', $context->connectionId)
                    ->where('path_hash', Document::hashPath($parentPath))
                    ->value('id');

                if ($parentId !== null) {
                    $folder->setAttribute('parent_id', (int) $parentId);
                }
            }
        }

        if ($scannedAt !== null) {
            $folder->setAttribute('last_scanned_at', $scannedAt);
        }

        $folder->save();

        return $folder;
    }

    /**
     * Dokumentzeile anlegen, aktualisieren, wiederherstellen oder als verschoben erkennen.
     *
     * @return string created|updated|unchanged|unchanged_meta_noise|restored|moved
     */
    public function upsertDocument(WebDavClient $client, DavEntry $entry, DocumentFolder $folder, ScanContext $context, CarbonImmutable $scanStartedAt): string
    {
        $path = WebDavPath::normalize($entry->path, false);
        $pathHash = Document::hashPath($path);

        /** @var Document|null $document */
        $document = Document::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('connection_id', $context->connectionId)
            ->where('path_hash', $pathHash)
            ->first();

        $lastModified = $entry->lastModifiedAt();
        $size = $entry->contentLength;

        if ($document === null) {
            $hash = $this->hashIfConfigured($client, $path, $size, $context);

            if ($hash !== null) {
                $movedFrom = $this->findMoveCandidate($client, $hash, $path, $context, $scanStartedAt);

                if ($movedFrom !== null) {
                    $this->applyMove($movedFrom, $entry, $path, $pathHash, $folder, $hash, $context, $scanStartedAt);

                    return 'moved';
                }
            }

            $document = new Document;
            $document->setAttribute('organization_id', $context->organizationId);
            $document->setAttribute('connection_id', $context->connectionId);
            $document->setAttribute('source_system', Document::SOURCE_IMMOWARE24);
            $document->setAttribute('external_id', $path);
            $document->setAttribute('origin', self::ORIGIN_REMOTE);
            $document->setAttribute('first_synced_at', $scanStartedAt);
            $document->setAttribute('content_stored', false);

            $this->fillMetadata($document, $entry, $path, $pathHash, $folder, $hash, $context, $scanStartedAt);
            $document->applyChecksum($this->normalized($path, $size, $lastModified, $hash));
            $document->save();

            $this->event($context, $document, 'created', $hash !== null ? 'content_hash' : 'listing', null, (string) $document->getAttribute('checksum'));
            $this->webhook($context, $document, 'document.created');

            return 'created';
        }

        $restored = false;

        if ($document->trashed()) {
            $document->setAttribute('deleted_at', null);
            $document->setAttribute('deletion_reason', null);
            $restored = true;
        }

        $document->setAttribute('missing_since', null);

        $oldEtag = $document->getAttribute('remote_etag');
        $oldModified = $document->getAttribute('remote_last_modified');
        $oldSize = $document->getAttribute('size_bytes');
        $oldHash = $document->getAttribute('content_hash');

        $metaChanged = $this->metadataChanged($context, $entry, $oldEtag, $oldModified instanceof \DateTimeInterface ? CarbonImmutable::instance($oldModified) : null, $oldSize !== null ? (int) $oldSize : null);

        if (! $metaChanged && ! $restored) {
            // Kein Write auf Version, Checksumme oder updated_at: nur die Sichtung für den Sweep festhalten.
            $document->setAttribute('last_synced_at', $scanStartedAt);
            $document->timestamps = false;
            $document->saveQuietly();
            $document->timestamps = true;

            return 'unchanged';
        }

        $hash = $metaChanged ? $this->hashIfConfigured($client, $path, $size, $context) : (is_string($oldHash) ? $oldHash : null);

        if ($metaChanged && $hash !== null && $oldHash === $hash) {
            // ETag oder lastmodified wechselte, Inhalt identisch: Meta-Rauschen, keine neue Version.
            $this->fillMetadata($document, $entry, $path, $pathHash, $folder, $hash, $context, $scanStartedAt);
            $document->save();
            $this->event($context, $document, $restored ? 'restored' : 'unchanged_meta_noise', 'content_hash', (string) $document->getAttribute('checksum'), (string) $document->getAttribute('checksum'));

            return $restored ? 'restored' : 'unchanged_meta_noise';
        }

        $oldChecksum = $document->getAttribute('checksum');
        $this->fillMetadata($document, $entry, $path, $pathHash, $folder, $hash ?? (is_string($oldHash) && ! $metaChanged ? $oldHash : null), $context, $scanStartedAt);
        $changed = $document->applyChecksum($this->normalized($path, $size, $lastModified, $hash));
        $document->save();

        if ($restored) {
            $this->event($context, $document, 'restored', 'listing', is_string($oldChecksum) ? $oldChecksum : null, (string) $document->getAttribute('checksum'));

            return 'restored';
        }

        if ($changed) {
            $this->event($context, $document, 'updated', $context->etagStable && $entry->etag !== null ? 'etag' : ($hash !== null ? 'content_hash' : 'lastmodified_size'), is_string($oldChecksum) ? $oldChecksum : null, (string) $document->getAttribute('checksum'));

            return 'updated';
        }

        return 'unchanged';
    }

    /**
     * Mark-and-Sweep je Ordner in zwei Stufen (07-sync-strategy.md Abschnitt 4 Punkte 3 bis 6): Dokumente und
     * Unterordner, die in diesem Scan nicht gelistet wurden, erhalten beim ersten Fehlen nur missing_since. Erst ein
     * Folgelauf mit gesundem Health-Check (health_ok_before) setzt deleted_at (deletion_reason missing_twice,
     * sync_event soft_deleted). Über der Schutzgrenze wird nichts gelöscht, die Connection erhält degraded_reason
     * mass_missing und einen Konflikt uncertain_identity auf Ordnerebene.
     *
     * @param  array<int, int>  $seenFolderIds
     * @return array{0: int, 1: bool} Anzahl Soft Deletes, Sweep blockiert
     */
    private function sweep(DocumentFolder $folder, array $seenFolderIds, CarbonImmutable $scanStartedAt, ScanContext $context): array
    {
        $documentsQuery = Document::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->where('folder_id', $folder->getKey())
            ->whereNull('deleted_at')
            ->where(static function (Builder $query) use ($scanStartedAt): void {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $scanStartedAt);
            });

        $totalInFolder = Document::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->where('folder_id', $folder->getKey())
            ->whereNull('deleted_at')
            ->count();

        $missingCount = (clone $documentsQuery)->count();

        $missingFolders = DocumentFolder::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->where('parent_id', $folder->getKey())
            ->whereNull('deleted_at');

        if ($seenFolderIds !== []) {
            $missingFolders->whereNotIn('id', $seenFolderIds);
        }

        if ($missingCount === 0 && (clone $missingFolders)->count() === 0) {
            return [0, false];
        }

        if ($this->sweepGuardTriggered($missingCount, $totalInFolder, $context)) {
            (clone $documentsQuery)->whereNull('missing_since')->update(['missing_since' => $scanStartedAt]);
            $this->degradeConnection($context, self::DEGRADED_MASS_MISSING);
            $this->recordFolderConflict($folder, $context, $scanStartedAt, self::CONFLICT_MASS_MISSING, ['missing' => $missingCount, 'total' => $totalInFolder]);
            Log::warning('Sweep blockiert: Schutzgrenze für fehlende Dokumente überschritten, Connection degraded.', [
                'connection_id' => $context->connectionId,
                'folder' => $folder->getAttribute('path'),
                'missing' => $missingCount,
                'total' => $totalInFolder,
                'reason' => self::DEGRADED_MASS_MISSING,
            ]);

            return [0, true];
        }

        $deleted = 0;
        $healthOk = $context->healthOk === true;

        foreach ($documentsQuery->orderBy('id')->lazyById(200) as $document) {
            if (! $document instanceof Document) {
                continue;
            }

            $missingSince = $document->getAttribute('missing_since');

            if ($missingSince === null) {
                // Erstes Fehlen: nur markieren.
                $document->setAttribute('missing_since', $scanStartedAt);
                $document->timestamps = false;
                $document->saveQuietly();
                $document->timestamps = true;
                $this->event($context, $document, 'missing', 'sweep', $document->getAttribute('checksum'), $document->getAttribute('checksum'));

                continue;
            }

            if (! $healthOk) {
                continue;
            }

            $this->softDeleteDocument($document, $scanStartedAt, $context, self::DELETION_MISSING_TWICE);
            $deleted++;
        }

        if (! $healthOk && $missingCount > 0) {
            Log::info('Sweep: Health-Check nicht bestätigt, fehlende Dokumente bleiben nur markiert.', [
                'connection_id' => $context->connectionId,
                'folder' => $folder->getAttribute('path'),
                'missing' => $missingCount,
            ]);
        }

        foreach ($missingFolders->orderBy('id')->lazyById(100) as $child) {
            if (! $child instanceof DocumentFolder) {
                continue;
            }

            if ($child->getAttribute('missing_since') === null) {
                DocumentFolder::query()->withoutGlobalScopes()->whereKey($child->getKey())->update(['missing_since' => $scanStartedAt]);

                continue;
            }

            if ($healthOk) {
                $deleted += $this->softDeleteFolderTree($child, $scanStartedAt, $context);
            }
        }

        return [$deleted, false];
    }

    private function sweepGuardTriggered(int $missing, int $total, ScanContext $context): bool
    {
        if ($missing > $context->sweepMaxMissingCount) {
            return true;
        }

        if ($total >= $context->sweepMinCountForRatio && $total > 0 && ($missing / $total) > $context->sweepMaxMissingRatio) {
            return true;
        }

        return false;
    }

    /**
     * LIKE-Muster für einen Pfadpräfix mit explizitem, portablem ESCAPE-Zeichen (!). Ohne ESCAPE-Klausel wirkt
     * der Backslash unter SQLite nicht als Escape, unter MariaDB nur im Standard-SQL-Mode.
     */
    public static function likePrefix(string $prefix): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%';
    }

    /**
     * Anzahl aktiver Dokumente unterhalb eines Ordnerpfads (Schutzgrenze vor Teilbaum-Löschungen).
     */
    private function countDocumentsBelow(string $prefix, ScanContext $context): int
    {
        return Document::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->whereNull('deleted_at')
            ->whereRaw("path like ? escape '!'", [self::likePrefix($prefix)])
            ->count();
    }

    private function softDeleteFolderTree(DocumentFolder $folder, CarbonImmutable $at, ScanContext $context): int
    {
        $deleted = 0;
        $prefix = (string) $folder->getAttribute('path');

        // Schutzgrenze wie im regulären Sweep: ein verschwundener Ordner mit mehr als sweep_max_missing_count
        // Dokumenten wird nur als fehlend markiert, nicht gelöscht (07-sync-strategy.md Abschnitt 4 Punkt 6).
        $below = $this->countDocumentsBelow($prefix, $context);

        if ($below > $context->sweepMaxMissingCount) {
            DocumentFolder::query()->withoutGlobalScopes()->whereKey($folder->getKey())->whereNull('missing_since')->update(['missing_since' => $at]);
            $this->degradeConnection($context, self::DEGRADED_MASS_MISSING);
            $this->recordFolderConflict($folder, $context, $at, self::CONFLICT_MASS_MISSING, ['documents_below' => $below, 'limit' => $context->sweepMaxMissingCount]);
            Log::warning('Teilbaum-Löschung blockiert: Schutzgrenze überschritten, Connection degraded.', [
                'connection_id' => $context->connectionId,
                'folder' => $prefix,
                'documents_below' => $below,
                'limit' => $context->sweepMaxMissingCount,
            ]);

            return 0;
        }

        $documents = Document::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->whereNull('deleted_at')
            ->whereRaw("path like ? escape '!'", [self::likePrefix($prefix)])
            ->orderBy('id')
            ->lazyById(200);

        foreach ($documents as $document) {
            if ($document instanceof Document) {
                $this->softDeleteDocument($document, $at, $context, self::DELETION_FOLDER_REMOVED);
                $deleted++;
            }
        }

        DocumentFolder::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->whereNull('deleted_at')
            ->whereRaw("path like ? escape '!'", [self::likePrefix($prefix)])
            ->update(['deleted_at' => $at, 'missing_since' => $at]);

        return $deleted;
    }

    private function softDeleteDocument(Document $document, CarbonImmutable $at, ScanContext $context, string $reason): void
    {
        $document->setAttribute('missing_since', $document->getAttribute('missing_since') ?? $at);
        $document->setAttribute('deletion_reason', $reason);
        $document->setAttribute('deleted_at', $at);
        $document->save();

        $this->event($context, $document, 'soft_deleted', 'sweep', $document->getAttribute('checksum'), null);
    }

    private function degradeConnection(ScanContext $context, string $reason): void
    {
        ImmowareConnection::query()
            ->withoutGlobalScopes()
            ->whereKey($context->connectionId)
            ->where('status', 'active')
            ->update(['status' => 'degraded', 'degraded_reason' => $reason]);
    }

    private function markFolderUnreachable(string $path, int $status, ScanContext $context, CarbonImmutable $at): void
    {
        $folder = DocumentFolder::query()
            ->withoutGlobalScopes()
            ->where('connection_id', $context->connectionId)
            ->where('path_hash', Document::hashPath($path))
            ->first();

        if ($folder === null) {
            return;
        }

        if ($status !== 404) {
            return;
        }

        // 404 auf Collection-Ebene bedeutet geänderte oder entzogene Freigabe, nicht gelöschte Dokumente
        // (07-sync-strategy.md Abschnitt 4 Punkt 5 und Abschnitt 6.1): Ordner als fehlend markieren, Connection
        // auf degraded setzen, Konflikt für die manuelle Prüfung anlegen. Kein Sweep, kein Soft Delete des Teilbaums.
        if ($folder->getAttribute('missing_since') === null) {
            $folder->setAttribute('missing_since', $at);
            $folder->save();
        }

        $this->degradeConnection($context, self::DEGRADED_FOLDER_MISSING);
        $this->recordFolderConflict($folder, $context, $at, self::CONFLICT_FOLDER_MISSING, ['path' => $folder->getAttribute('path'), 'missing_since' => $at->toIso8601String(), 'http_status' => 404]);

        Log::warning('Ordner per 404 nicht erreichbar: Connection degraded, kein Sweep.', [
            'connection_id' => $context->connectionId,
            'folder' => $path,
            'reason' => self::DEGRADED_FOLDER_MISSING,
        ]);
    }

    /**
     * Höchstens ein offener Konflikt je Ordner und Typ; Wiederholungen erhöhen occurrences (07 Abschnitt 7).
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function recordFolderConflict(DocumentFolder $folder, ScanContext $context, CarbonImmutable $at, string $type, array $snapshot): void
    {
        $existing = Conflict::query()
            ->where('entity_type', 'document_folder')
            ->where('entity_id', $folder->getKey())
            ->where('conflict_type', $type)
            ->where('open_key', true)
            ->first();

        if ($existing instanceof Conflict) {
            $existing->forceFill([
                'occurrences' => (int) $existing->getAttribute('occurrences') + 1,
                'last_seen_run_id' => $context->syncRunId,
                'last_seen_at' => $at,
            ])->save();

            return;
        }

        $conflict = new Conflict;
        $conflict->forceFill([
            'connection_id' => $context->connectionId,
            'sync_run_id' => $context->syncRunId,
            'entity_type' => 'document_folder',
            'entity_id' => $folder->getKey(),
            'conflict_type' => $type,
            'conflict_state' => ConflictState::RemoteNewer,
            'local_snapshot_json' => ['path' => $folder->getAttribute('path'), ...$snapshot],
            'status' => 'open',
            'open_key' => true,
            'occurrences' => 1,
            'last_seen_run_id' => $context->syncRunId,
            'last_seen_at' => $at,
        ]);
        $conflict->save();
    }

    private function metadataChanged(ScanContext $context, DavEntry $entry, mixed $oldEtag, ?CarbonImmutable $oldModified, ?int $oldSize): bool
    {
        if ($context->etagStable && $entry->etag !== null && is_string($oldEtag) && $oldEtag !== '') {
            return $entry->etag !== $oldEtag;
        }

        $newModified = $entry->lastModifiedAt();

        $modifiedChanged = ($newModified?->getTimestamp()) !== ($oldModified?->getTimestamp());
        $sizeChanged = $entry->contentLength !== $oldSize;

        if (! $modifiedChanged && ! $sizeChanged && $entry->etag !== null && is_string($oldEtag) && $oldEtag !== '' && $entry->etag !== $oldEtag) {
            // ETag wechselt ohne Größe oder Datum: gilt bei instabilen ETags nicht als Änderung.
            return false;
        }

        return $modifiedChanged || $sizeChanged;
    }

    private function hashIfConfigured(WebDavClient $client, string $path, ?int $size, ScanContext $context): ?string
    {
        if (! $context->contentHashEnabled) {
            return null;
        }

        if ($size !== null && $size > $context->contentHashMaxBytes) {
            return null;
        }

        try {
            $download = $client->download($path, $context->contentHashMaxBytes);
        } catch (Throwable $e) {
            Log::info('Inhalts-Hash nicht ermittelbar.', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }

        return $download->isOk() ? $download->sha256 : null;
    }

    /**
     * Kandidat für einen Move: gleicher content_hash, anderer Pfad, in diesem Lauf nicht gesehen und
     * die alte Ressource antwortet mit 404.
     */
    private function findMoveCandidate(WebDavClient $client, string $hash, string $newPath, ScanContext $context, CarbonImmutable $scanStartedAt): ?Document
    {
        // Im selben Lauf bereits gesweepte Dokumente (Ordner wurde vor dem Zielordner gescannt) gelten ebenfalls als Kandidaten.
        $sweptInRun = SyncEvent::query()
            ->where('sync_run_id', $context->syncRunId)
            ->where('entity_type', 'document')
            ->where('action', 'remote_deleted')
            ->pluck('entity_id')
            ->all();

        /** @var Document|null $candidate */
        $candidate = Document::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('connection_id', $context->connectionId)
            ->where('content_hash', $hash)
            ->where('path', '!=', $newPath)
            ->where(static function (Builder $query) use ($scanStartedAt, $sweptInRun): void {
                $query->where(static function (Builder $active) use ($scanStartedAt): void {
                    $active->whereNull('deleted_at')->where(static function (Builder $unseen) use ($scanStartedAt): void {
                        $unseen->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $scanStartedAt);
                    });
                });

                if ($sweptInRun !== []) {
                    $query->orWhereIn('id', $sweptInRun);
                }
            })
            ->orderBy('id')
            ->first();

        if ($candidate === null) {
            return null;
        }

        try {
            $head = $client->head((string) $candidate->getAttribute('path'));
        } catch (Throwable) {
            return null;
        }

        return $head['status'] === 404 ? $candidate : null;
    }

    private function applyMove(Document $document, DavEntry $entry, string $path, string $pathHash, DocumentFolder $folder, string $hash, ScanContext $context, CarbonImmutable $at): void
    {
        $oldPath = (string) $document->getAttribute('path');
        $oldChecksum = $document->getAttribute('checksum');

        $this->fillMetadata($document, $entry, $path, $pathHash, $folder, $hash, $context, $at);
        $document->setAttribute('external_id', $path);
        $document->setAttribute('missing_since', null);
        $document->setAttribute('deleted_at', null);
        $document->setAttribute('deletion_reason', null);
        $document->applyChecksum($this->normalized($path, $entry->contentLength, $entry->lastModifiedAt(), $hash));
        $document->save();

        $this->event($context, $document, 'moved', 'content_hash', is_string($oldChecksum) ? $oldChecksum : null, (string) $document->getAttribute('checksum'));

        Log::info('Dokument als verschoben erkannt.', ['connection_id' => $context->connectionId, 'from' => $oldPath, 'to' => $path]);
    }

    private function fillMetadata(Document $document, DavEntry $entry, string $path, string $pathHash, DocumentFolder $folder, ?string $hash, ScanContext $context, CarbonImmutable $at): void
    {
        $filename = WebDavPath::basename($path);
        $folderPath = (string) $folder->getAttribute('path');
        $classification = $this->classifier->classify($filename, $folderPath);

        $document->setAttribute('folder_id', $folder->getKey());
        $document->setAttribute('path', $path);
        $document->setAttribute('path_hash', $pathHash);
        $document->setAttribute('filename', mb_substr($filename, 0, 512));
        $document->setAttribute('display_name', $entry->displayName !== null ? mb_substr($entry->displayName, 0, 512) : null);
        $document->setAttribute('content_type', $entry->contentType ?? $this->guessContentType($filename));
        $document->setAttribute('document_type', $classification['type']);
        $document->setAttribute('document_type_rule', $classification['rule']);
        $document->setAttribute('size_bytes', $entry->contentLength);
        $document->setAttribute('remote_etag', $entry->etag !== null ? substr($entry->etag, 0, 255) : null);
        $document->setAttribute('remote_last_modified', $entry->lastModifiedAt());

        if ($hash !== null) {
            $document->setAttribute('content_hash', $hash);
        }

        $document->setAttribute('external_parent_id', $folderPath);
        $document->setAttribute('external_updated_at', $entry->lastModifiedAt());
        $document->setAttribute('last_synced_at', $at);

        // Zuordnung nur über explizite Regeln; bestehende Hub-Zuordnungen bleiben erhalten.
        if ($document->getAttribute('property_id') === null && $document->getAttribute('unit_id') === null && $document->getAttribute('contact_id') === null) {
            $assignment = $this->assignments->resolve($context->organizationId, $path);

            foreach ($assignment as $key => $value) {
                if ($value !== null) {
                    $document->setAttribute($key, $value);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalized(string $path, ?int $size, ?CarbonImmutable $lastModified, ?string $hash): array
    {
        if ($hash !== null) {
            return ['path' => $path, 'content_hash' => $hash];
        }

        return ['path' => $path, 'size' => $size, 'last_modified' => $lastModified?->toIso8601String()];
    }

    private function guessContentType(string $filename): ?string
    {
        $pos = strrpos($filename, '.');

        if ($pos === false) {
            return null;
        }

        return match (strtolower(substr($filename, $pos + 1))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'tif', 'tiff' => 'image/tiff',
            'txt' => 'text/plain',
            'csv' => 'text/csv',
            'xml' => 'application/xml',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'zip' => 'application/zip',
            default => null,
        };
    }

    /**
     * Webhook-Outbox (nur IDs, Typ und Link, keine Inhalte). Fehler dürfen den Spiegel nie stoppen.
     */
    private function webhook(ScanContext $context, Document $document, string $event): void
    {
        try {
            $this->webhooks->dispatch($event, [
                'id' => (int) $document->getKey(),
                'type' => 'document',
                'href' => '/api/v1/documents/'.(int) $document->getKey(),
                'connection_id' => $context->connectionId,
                'sync_run_id' => $context->syncRunId,
            ], $context->organizationId);
        } catch (Throwable $e) {
            Log::warning('Webhook-Ereignis konnte nicht abgelegt werden.', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    private function event(ScanContext $context, Document $document, string $action, string $detectedBy, ?string $oldChecksum, ?string $newChecksum): void
    {
        try {
            SyncEvent::query()->create([
                'sync_run_id' => $context->syncRunId,
                'connection_id' => $context->connectionId,
                'entity_type' => 'document',
                'entity_id' => (int) $document->getKey(),
                'action' => $action,
                'detected_by' => $detectedBy,
                'old_checksum' => $oldChecksum,
                'new_checksum' => $newChecksum,
                'occurred_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('sync_event konnte nicht geschrieben werden.', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
