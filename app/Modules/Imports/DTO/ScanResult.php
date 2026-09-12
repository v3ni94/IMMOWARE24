<?php

declare(strict_types=1);

namespace App\Modules\Imports\DTO;

/**
 * Ergebnis eines Drop-Ordner-Scans.
 */
final class ScanResult
{
    /** @var array<int, int> IDs neu angelegter import_files mit Status received */
    public array $received = [];

    /** @var array<int, int> IDs neu angelegter import_files mit Status quarantined */
    public array $quarantined = [];

    /** @var array<int, string> Dateinamen, die als Duplikat (gleicher Inhalt) übersprungen wurden */
    public array $duplicates = [];

    /** @var array<int, string> Fehlermeldungen ohne persistierten Datensatz */
    public array $errors = [];

    public function total(): int
    {
        return count($this->received) + count($this->quarantined);
    }
}
