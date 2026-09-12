<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Contracts;

use App\Modules\Connector\Support\DavResponse;

/**
 * Fachlicher Verarbeiter einer DAV-Ressource (vCard oder iCalendar) innerhalb eines Pull-Laufs.
 */
interface DavMirrorHandlerInterface
{
    /**
     * @return array{created: int, updated: int, external_ids: array<int, string>}
     */
    public function handleResource(DavResponse $resource): array;

    /**
     * Ressource laut sync-collection nicht mehr vorhanden: missing_since setzen, kein Soft Delete.
     */
    public function handleRemoved(string $href): void;

    /**
     * Mark-and-Sweep nach vollständiger Enumeration; nur Soft Delete, erst ab dem zweiten Fehlen und nur bei
     * bestätigtem Health-Check (07-sync-strategy.md Abschnitt 4).
     *
     * @param  array<string, true>  $seenHrefs
     * @param  bool  $healthOk  sync_runs.health_ok_before des Laufs; false verhindert jeden Soft Delete
     * @return array{missing: int, deleted: int, blocked: bool}
     */
    public function sweep(array $seenHrefs, bool $healthOk = true): array;
}
