<?php

declare(strict_types=1);

namespace App\Modules\Imports\DTO;

use App\Modules\Imports\Models\ImportFile;
use Carbon\CarbonImmutable;

/**
 * Laufzeitkontext eines Imports: Mandant, Verbindung, Datei, bestätigtes Mapping und Schlüsselschema.
 */
final readonly class ImportContext
{
    /**
     * @param  array<string, string>  $mapping  Zielfeld => normalisierte Quellspalte
     * @param  array<int, string>  $keySchema  Zielfelder des stabilen Schlüssels
     */
    public function __construct(
        public ImportFile $file,
        public int $organizationId,
        public ?int $connectionId,
        public array $mapping,
        public array $keySchema,
        public bool $isFullExport,
        public CarbonImmutable $asOfDate,
        public CarbonImmutable $startedAt,
    ) {}

    /**
     * Liest ein Zielfeld aus einer Zeile über das Mapping. Nicht gemappte Felder ergeben null.
     *
     * @param  array<string, string|int>  $row
     */
    public function value(array $row, string $target): ?string
    {
        $source = $this->mapping[$target] ?? null;

        if ($source === null || ! array_key_exists($source, $row)) {
            return null;
        }

        $value = trim((string) $row[$source]);

        return $value === '' ? null : $value;
    }

    /**
     * Stabiler Schlüssel aus den konfigurierten Schlüsselspalten. null, wenn eine Schlüsselspalte leer ist.
     *
     * @param  array<string, string|int>  $row
     */
    public function key(array $row): ?string
    {
        $parts = [];

        foreach ($this->keySchema as $field) {
            $value = $this->value($row, $field);

            if ($value === null) {
                return null;
            }

            $parts[] = $value;
        }

        return $parts === [] ? null : implode('|', $parts);
    }
}
