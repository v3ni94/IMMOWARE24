<?php

declare(strict_types=1);

namespace App\Modules\Imports\DTO;

use Carbon\CarbonImmutable;

/**
 * Inhalt des Metadaten-Sidecars <datei>.json. Pflicht: organization, export_type, exported_at, exported_by.
 * Optional: is_full_export, as_of_date (TT.MM.JJJJ oder JJJJ-MM-TT), connection.
 */
final readonly class ImportMetadata
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ?string $organization,
        public ?string $exportType,
        public ?CarbonImmutable $exportedAt,
        public ?string $exportedBy,
        public bool $isFullExport,
        public ?CarbonImmutable $asOfDate,
        public ?string $connection,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            organization: self::string($data['organization'] ?? null),
            exportType: self::string($data['export_type'] ?? null),
            exportedAt: self::date($data['exported_at'] ?? null),
            exportedBy: self::string($data['exported_by'] ?? null),
            isFullExport: filter_var($data['is_full_export'] ?? false, FILTER_VALIDATE_BOOLEAN),
            asOfDate: self::date($data['as_of_date'] ?? null),
            connection: self::string($data['connection'] ?? null),
            raw: $data,
        );
    }

    /**
     * @param  array<int, string>  $required
     * @return array<int, string> fehlende Pflichtfelder
     */
    public function missingFields(array $required): array
    {
        $map = [
            'organization' => $this->organization,
            'export_type' => $this->exportType,
            'exported_at' => $this->exportedAt,
            'exported_by' => $this->exportedBy,
        ];

        $missing = [];

        foreach ($required as $field) {
            if (! array_key_exists($field, $map) || $map[$field] === null || $map[$field] === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $m) === 1) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3])
                ? CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1], 0, 0, 0, 'UTC')
                : null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
