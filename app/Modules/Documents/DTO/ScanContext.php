<?php

declare(strict_types=1);

namespace App\Modules\Documents\DTO;

/**
 * Laufzeitparameter eines Ordner-Scans: Connection, Mandant, Sync-Lauf und Änderungserkennung.
 */
final readonly class ScanContext
{
    public function __construct(
        public int $connectionId,
        public int $organizationId,
        public int $syncRunId,
        public bool $etagStable = false,
        public bool $contentHashEnabled = false,
        public int $contentHashMaxBytes = 10485760,
        public int $maxEntriesPerFolder = 5000,
        public float $sweepMaxMissingRatio = 0.2,
        public int $sweepMinCountForRatio = 10,
        public int $sweepMaxMissingCount = 500,
        /**
         * Health-Check-Ergebnis der Connection vor dem Lauf (sync_runs.health_ok_before). Nur true erlaubt Soft Deletes
         * (07-sync-strategy.md Abschnitt 4 Punkt 5); null bedeutet unbekannt und zählt als nicht gesund.
         */
        public ?bool $healthOk = null,
    ) {}
}
