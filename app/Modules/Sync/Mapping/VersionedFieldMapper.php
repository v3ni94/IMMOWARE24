<?php

declare(strict_types=1);

namespace App\Modules\Sync\Mapping;

use App\Core\Contracts\FieldMapperInterface;
use App\Modules\Sync\Models\FieldMapping;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * Reine Funktion externe Nutzlast zu Hub-Attributen auf Basis einer versionierten field_mappings-Zeile.
 * Kein Datenbankzugriff im Mapping selbst.
 */
final class VersionedFieldMapper implements FieldMapperInterface
{
    /** @var array<int, array{source_field: string, target_field: string, transform: string|null}> */
    private readonly array $rules;

    /** @var array<string, mixed> */
    private readonly array $keySchema;

    public function __construct(
        private readonly string $entityType,
        private readonly int $version,
        array $rules,
        array $keySchema = [],
    ) {
        $normalized = [];

        foreach ($rules as $rule) {
            if (! is_array($rule) || ! isset($rule['source_field'], $rule['target_field'])) {
                throw new InvalidArgumentException('Jede Mapping-Regel braucht source_field und target_field.');
            }

            $normalized[] = [
                'source_field' => (string) $rule['source_field'],
                'target_field' => (string) $rule['target_field'],
                'transform' => isset($rule['transform']) && $rule['transform'] !== '' ? (string) $rule['transform'] : null,
            ];
        }

        $this->rules = $normalized;
        $this->keySchema = $keySchema;
    }

    public static function fromModel(FieldMapping $mapping): self
    {
        return new self(
            (string) $mapping->getAttribute('entity_type'),
            (int) $mapping->getAttribute('version'),
            (array) $mapping->getAttribute('mapping'),
            (array) ($mapping->getAttribute('key_schema') ?? []),
        );
    }

    public function entityType(): string
    {
        return $this->entityType;
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return array<int, array{source_field: string, target_field: string, transform: string|null}>
     */
    public function rules(): array
    {
        return $this->rules;
    }

    public function toLocal(array $external): array
    {
        $local = [];

        foreach ($this->rules as $rule) {
            $value = $this->read($external, $rule['source_field']);

            if ($value === null) {
                continue;
            }

            $this->write($local, $rule['target_field'], $this->transform($value, $rule['transform']));
        }

        return $local;
    }

    public function toExternal(array $local): array
    {
        $external = [];

        foreach ($this->rules as $rule) {
            $value = $this->read($local, $rule['target_field']);

            if ($value !== null) {
                $this->write($external, $rule['source_field'], $value);
            }
        }

        return $external;
    }

    public function externalId(array $external): string
    {
        $candidates = (array) ($this->keySchema['external_id'] ?? ['UID', 'href']);

        foreach ($candidates as $field) {
            $value = $this->read($external, (string) $field);

            if (is_scalar($value) && trim((string) $value) !== '') {
                $id = trim((string) $value);
                $suffixField = $this->keySchema['external_id_suffix'] ?? null;

                if (is_string($suffixField)) {
                    $suffix = $this->read($external, $suffixField);

                    if (is_scalar($suffix) && trim((string) $suffix) !== '') {
                        $id .= '|'.trim((string) $suffix);
                    }
                }

                return $id;
            }
        }

        throw new InvalidArgumentException(sprintf('Keine externe ID in der Nutzlast (%s) gefunden.', implode(', ', array_map('strval', $candidates))));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function read(array $data, string $path): mixed
    {
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }

        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $target
     */
    private function write(array &$target, string $path, mixed $value): void
    {
        $segments = explode('.', $path);
        $ref = &$target;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $ref[$segment] = $value;

                return;
            }

            if (! isset($ref[$segment]) || ! is_array($ref[$segment])) {
                $ref[$segment] = [];
            }

            $ref = &$ref[$segment];
        }
    }

    private function transform(mixed $value, ?string $transform): mixed
    {
        if ($transform === null) {
            return $value;
        }

        return match ($transform) {
            'trim' => is_string($value) ? trim($value) : $value,
            'lower' => is_string($value) ? mb_strtolower(trim($value)) : $value,
            'upper' => is_string($value) ? mb_strtoupper(trim($value)) : $value,
            'int' => is_numeric($value) ? (int) $value : null,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            'digits' => is_string($value) ? preg_replace('/\D+/', '', $value) : $value,
            'phone_list' => $this->phones($value),
            'list' => is_array($value) ? array_values($value) : [$value],
            'csv_list' => is_string($value) ? array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== '')) : (is_array($value) ? array_values($value) : []),
            'date' => $this->date($value, false),
            'datetime' => $this->date($value, true),
            'mailto' => is_string($value) ? mb_strtolower(preg_replace('/^mailto:/i', '', trim($value)) ?? '') : $value,
            'ical_unescape' => is_string($value) ? str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], ["\n", "\n", ',', ';', '\\'], $value) : $value,
            'vcard_kind' => match (is_string($value) ? mb_strtolower(trim($value)) : '') {
                'individual', 'person' => 'person',
                'org', 'company' => 'company',
                default => 'unknown',
            },
            'dav_path' => is_string($value) ? ltrim(rawurldecode(rtrim($value, '/')), '/') : $value,
            'dav_is_collection' => is_string($value) ? str_contains(mb_strtolower($value), 'collection') : (bool) $value,
            default => throw new InvalidArgumentException(sprintf('Unbekannte Transformation "%s".', $transform)),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function phones(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $result = [];

        foreach ($items as $item) {
            $raw = is_array($item) ? (string) ($item['value'] ?? '') : (string) $item;
            $raw = preg_replace('/^tel:/i', '', trim($raw)) ?? '';
            $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';
            $digits = preg_replace('/^00/', '+', $digits) ?? '';
            $digits = preg_replace('/(?!^)\+/', '', $digits) ?? '';

            $result[] = [
                'type' => is_array($item) ? ($item['type'] ?? null) : null,
                'value' => $digits,
                'raw' => $raw,
                'pref' => is_array($item) ? (bool) ($item['pref'] ?? false) : false,
            ];
        }

        return $result;
    }

    private function date(mixed $value, bool $withTime): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $date = CarbonImmutable::parse(trim($value))->utc();
        } catch (Throwable) {
            return null;
        }

        return $withTime ? $date->toIso8601String() : $date->toDateString();
    }
}
