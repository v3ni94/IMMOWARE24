<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Drive\Models\DocumentReference;
use App\Modules\Estate\Models\Property;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Security\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Suche und Index (mail_document_references mit Textauszug) für Drive-Dokumente eines Vorgangs. Indexiert nur
 * Dateien aus den dem Objekt zugeordneten Ordnern, liefert Auszüge nur nach doppelter Rechteprüfung
 * (DriveAccessGuard) und entfernt Auszüge, sobald die Drive-Berechtigung fehlt (Rechteentzug).
 */
final class DriveSearchService
{
    public function __construct(
        private readonly DriveProvider $drive,
        private readonly FolderMappingResolver $folders,
        private readonly DriveAccessGuard $guard,
    ) {}

    /**
     * Status für die Oberfläche: not_configured, ok.
     */
    public function status(): string
    {
        return $this->drive->isConfigured() ? 'ok' : 'not_configured';
    }

    /**
     * Indexiert die Dateien der Objektordner eines Vorgangs als Dokumentreferenzen mit Textauszug.
     *
     * @return array<int, DocumentReference>
     */
    public function indexCase(MailCase $case, ?User $linkedBy = null, int $maxFiles = 50): array
    {
        $property = $case->getAttribute('property_id') !== null ? Property::query()->withoutGlobalScopes()->find((int) $case->getAttribute('property_id')) : null;

        if (! $property instanceof Property) {
            return [];
        }

        $connection = $this->drive->connection();
        $references = [];

        foreach ($this->folders->foldersForProperty($property) as $folderId) {
            $pageToken = null;

            do {
                $page = $this->drive->listFolder($folderId, $pageToken);

                foreach ($page['files'] as $file) {
                    if (count($references) >= $maxFiles) {
                        break 2;
                    }

                    if ($file['mime_type'] === 'application/vnd.google-apps.folder') {
                        continue;
                    }

                    $references[] = $this->upsertReference($case, (int) $connection->getKey(), $file, $linkedBy);
                }

                $pageToken = $page['next_page_token'];
            } while ($pageToken !== null);
        }

        return $references;
    }

    /**
     * Suche im Index des Vorgangs. Liefert nur Referenzen, deren Auszug die Person lesen darf; bei fehlender
     * Drive-Berechtigung wird der Auszug aus dem Index entfernt.
     *
     * @return array<int, array{reference: DocumentReference, excerpt: ?string}>
     */
    public function search(User $user, MailCase $case, string $term, int $limit = 20): array
    {
        if (! $this->guard->canAccessCase($user, $case)) {
            return [];
        }

        $term = trim($term);
        $query = DocumentReference::query()
            ->withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            ->where('source', 'drive')
            ->whereNull('deleted_at')
            ->orderByDesc('excerpt_indexed_at')
            ->limit($limit);

        if ($term !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(static fn ($q) => $q->where('name', 'like', $like)->orWhere('text_excerpt', 'like', $like));
        }

        $results = [];

        foreach ($query->get() as $reference) {
            if (! $reference instanceof DocumentReference) {
                continue;
            }

            $excerpt = $this->authorizedExcerpt($user, $case, $reference);
            $results[] = ['reference' => $reference, 'excerpt' => $excerpt];
        }

        return $results;
    }

    /**
     * Auszug nach doppelter Rechteprüfung. Ohne Drive-Recht: Auszug entfernen (revoke) und null.
     */
    public function authorizedExcerpt(User $user, MailCase $case, DocumentReference $reference): ?string
    {
        if (! $this->guard->canAccessCase($user, $case)) {
            return null;
        }

        $fileId = (string) $reference->getAttribute('drive_file_id');

        if ($fileId === '') {
            return null;
        }

        try {
            $permissions = $this->drive->permissions($fileId);
        } catch (MailIntegrationNotConfiguredException|MailRemoteException $e) {
            Log::info('Drive-Berechtigung nicht prüfbar, kein Auszug', ['file_id' => $fileId, 'class' => $e::class]);

            return null;
        }

        $reference->forceFill([
            'permissions_summary_json' => array_map(static fn (array $p): array => ['type' => $p['type'], 'role' => $p['role'], 'email' => $p['email']], $permissions),
            'access_checked_at' => now()->toImmutable(),
        ]);

        if (! $this->guard->hasDrivePermission($user, $permissions)) {
            $this->revokeExcerpt($reference);

            return null;
        }

        $reference->forceFill(['access_status' => 'ok'])->save();
        $excerpt = $reference->getAttribute('text_excerpt');

        return is_string($excerpt) && $excerpt !== '' ? $excerpt : null;
    }

    /**
     * Rechteentzug: Textauszug und Hash entfernen, Referenz bleibt als Verweis ohne Inhalt.
     */
    public function revokeExcerpt(DocumentReference $reference): void
    {
        $reference->forceFill([
            'text_excerpt' => null,
            'excerpt_hash' => null,
            'excerpt_indexed_at' => null,
            'access_status' => 'revoked',
            'access_checked_at' => now()->toImmutable(),
        ])->save();
    }

    /**
     * Entfernt alle Auszüge einer Datei (z. B. nach Meldung eines Rechteentzugs).
     */
    public function revokeFile(string $fileId): int
    {
        $count = 0;

        $references = DocumentReference::query()
            ->withoutGlobalScopes()
            ->where('drive_file_id', $fileId)
            ->whereNotNull('text_excerpt')
            ->lazyById();

        foreach ($references as $reference) {
            if ($reference instanceof DocumentReference) {
                $this->revokeExcerpt($reference);
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array{id: string, name: string, mime_type: string, modified_at: ?string, web_view_link: ?string, parents: array<int, string>}  $file
     */
    private function upsertReference(MailCase $case, int $connectionId, array $file, ?User $linkedBy): DocumentReference
    {
        $reference = DocumentReference::query()
            ->withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            ->where('drive_file_id', $file['id'])
            ->first();

        $reference ??= new DocumentReference([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'source' => 'drive',
            'drive_connection_id' => $connectionId,
            'drive_file_id' => $file['id'],
            'linked_by' => $linkedBy?->getKey(),
            'linked_at' => now()->toImmutable(),
        ]);

        $excerpt = null;

        try {
            $excerpt = $this->drive->textContent($file['id'], $file['mime_type']);
        } catch (MailRemoteException $e) {
            Log::info('Drive-Auszug nicht lesbar', ['file_id' => $file['id'], 'status' => $e->httpStatus]);
        }

        $reference->forceFill([
            'name' => mb_substr($file['name'], 0, 255),
            'mime_type' => mb_substr($file['mime_type'], 0, 120),
            'web_view_link' => $file['web_view_link'] !== null ? mb_substr($file['web_view_link'], 0, 1024) : null,
            'text_excerpt' => $excerpt,
            'excerpt_hash' => $excerpt !== null ? hash('sha256', $excerpt) : null,
            'excerpt_indexed_at' => $excerpt !== null ? now()->toImmutable() : null,
            'verified_at' => now()->toImmutable(),
        ])->save();

        return $reference;
    }
}
