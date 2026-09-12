<?php

declare(strict_types=1);

namespace App\Modules\Documents\DTO;

/**
 * Ergebnis eines Depth-1-Scans eines Ordners.
 *
 * @property array<int, string> $childFolders Normalisierte Pfade der gefundenen Unterordner
 */
final readonly class FolderScanResult
{
    /**
     * @param  array<int, string>  $childFolders
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function __construct(
        public string $path,
        public int $status,
        public bool $enumerated,
        public int $processed = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public int $moved = 0,
        public int $unchanged = 0,
        public int $failed = 0,
        public array $childFolders = [],
        public array $errors = [],
        public bool $sweepBlocked = false,
    ) {}
}
