<?php

declare(strict_types=1);

namespace App\Modules\Imports\DTO;

/**
 * Zähler und Fehlerliste eines Importlaufs.
 */
final class ImportOutcome
{
    public int $rowsTotal = 0;

    public int $rowsImported = 0;

    public int $rowsRejected = 0;

    public int $rowsFailed = 0;

    public int $rowsDuplicate = 0;

    public int $rowsSwept = 0;

    /** Erstes Fehlen im Vollexport (missing_since gesetzt, noch kein Soft Delete). */
    public int $rowsMarkedMissing = 0;

    /** @var array<int, array{line: int|null, reason: string}> */
    public array $errors = [];

    public function reject(?int $line, string $reason): void
    {
        $this->rowsRejected++;
        $this->addError($line, $reason);
    }

    public function fail(?int $line, string $reason): void
    {
        $this->rowsFailed++;
        $this->addError($line, $reason);
    }

    public function addError(?int $line, string $reason): void
    {
        $limit = (int) config('hub.imports.max_errors_stored', 200);

        if (count($this->errors) < $limit) {
            $this->errors[] = ['line' => $line, 'reason' => $reason];
        }
    }
}
