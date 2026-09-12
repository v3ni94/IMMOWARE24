<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Contracts;

use App\Modules\Contacts\Dav\DavResource;

/**
 * Fachlicher Verarbeiter einer DAV-Ressource (vCard oder iCalendar) innerhalb eines Pull-Laufs.
 */
interface DavMirrorHandlerInterface
{
    /**
     * @return array{created: int, updated: int, external_ids: array<int, string>}
     */
    public function handleResource(DavResource $resource): array;

    /**
     * Ressource laut sync-collection nicht mehr vorhanden: missing_since setzen, kein Soft Delete.
     */
    public function handleRemoved(string $href): void;

    /**
     * Mark-and-Sweep nach vollständiger Enumeration; nur Soft Delete.
     *
     * @param  array<string, true>  $seenHrefs
     * @return array{missing: int, deleted: int}
     */
    public function sweep(array $seenHrefs): array;
}
