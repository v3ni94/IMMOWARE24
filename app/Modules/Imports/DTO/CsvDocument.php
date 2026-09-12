<?php

declare(strict_types=1);

namespace App\Modules\Imports\DTO;

/**
 * Ergebnis der Header-Analyse einer CSV-Datei. Zeilen werden separat gestreamt.
 */
final readonly class CsvDocument
{
    /**
     * @param  array<int, string>  $headers  Originalspalten (UTF-8)
     * @param  array<int, string>  $normalizedHeaders
     */
    public function __construct(
        public string $path,
        public string $encoding,
        public bool $hasBom,
        public string $delimiter,
        public array $headers,
        public array $normalizedHeaders,
        public string $fingerprint,
        public int $headerLine = 1,
    ) {}
}
