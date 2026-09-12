<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Mapping\DefaultFieldMappings;
use App\Modules\Sync\Mapping\VersionedFieldMapper;
use App\Modules\Sync\Models\FieldMapping;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Versionierte Feldmappings: Änderungen erzeugen eine neue Version, die alte wird retired,
 * jeder Wechsel wird auditiert.
 */
final class FieldMappingService
{
    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_RETIRED = 'retired';

    public const string STATUS_DRAFT = 'draft';

    public function __construct(
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    public function active(string $entityType, string $sourceFormat, string $sourceSystem = DefaultFieldMappings::SOURCE_SYSTEM): ?FieldMapping
    {
        return FieldMapping::query()
            ->where('source_system', $sourceSystem)
            ->where('entity_type', $entityType)
            ->where('source_format', $sourceFormat)
            ->active()
            ->orderByDesc('version')
            ->first();
    }

    public function mapper(string $entityType, string $sourceFormat, string $sourceSystem = DefaultFieldMappings::SOURCE_SYSTEM): VersionedFieldMapper
    {
        $mapping = $this->active($entityType, $sourceFormat, $sourceSystem);

        if ($mapping === null) {
            throw new InvalidArgumentException(sprintf('Kein aktives Mapping für %s/%s. Default-Mapping mit seedDefaults() anlegen.', $entityType, $sourceFormat));
        }

        return VersionedFieldMapper::fromModel($mapping);
    }

    /**
     * Veröffentlicht eine neue Mapping-Version. Unveränderte Regeln erzeugen keine neue Version.
     *
     * @param  array<int, array{source_field: string, target_field: string, transform?: string|null}>  $rules
     * @param  array<string, mixed>|null  $keySchema
     */
    public function publish(string $entityType, string $sourceFormat, array $rules, ?User $user = null, ?string $notes = null, ?array $keySchema = null, string $sourceSystem = DefaultFieldMappings::SOURCE_SYSTEM): FieldMapping
    {
        // Validierung der Regeln über den Mapper (wirft bei ungültigen Regeln).
        new VersionedFieldMapper($entityType, 0, $rules, $keySchema ?? []);

        return DB::transaction(function () use ($entityType, $sourceFormat, $rules, $user, $notes, $keySchema, $sourceSystem): FieldMapping {
            $current = $this->active($entityType, $sourceFormat, $sourceSystem);

            if ($current !== null && $this->sameRules((array) $current->getAttribute('mapping'), $rules) && ($keySchema === null || $keySchema === (array) $current->getAttribute('key_schema'))) {
                return $current;
            }

            $maxVersion = (int) FieldMapping::query()
                ->where('source_system', $sourceSystem)
                ->where('entity_type', $entityType)
                ->where('source_format', $sourceFormat)
                ->max('version');

            $now = CarbonImmutable::now();

            if ($current !== null) {
                $current->forceFill(['status' => self::STATUS_RETIRED, 'retired_at' => $now]);
                $current->save();
            }

            $mapping = new FieldMapping;
            $mapping->forceFill([
                'source_system' => $sourceSystem,
                'entity_type' => $entityType,
                'source_format' => $sourceFormat,
                'version' => $maxVersion + 1,
                'status' => self::STATUS_ACTIVE,
                'mapping' => array_values($rules),
                'key_schema' => $keySchema ?? $current?->getAttribute('key_schema'),
                'notes' => $notes,
                'created_by' => $user?->getKey(),
                'activated_by' => $user?->getKey(),
                'activated_at' => $now,
                'previous_version_id' => $current?->getKey(),
            ]);
            $mapping->save();

            $this->audit->log(
                'field_mapping.published',
                $mapping,
                ['version' => $current?->getAttribute('version'), 'rules' => $current?->getAttribute('mapping')],
                ['version' => $mapping->getAttribute('version'), 'rules' => $rules, 'notes' => $notes],
                $user !== null ? AuditSource::User->value : AuditSource::System->value,
                $this->correlationId->current(),
            );

            return $mapping;
        });
    }

    /**
     * Legt die Default-Mappings v1 an, sofern für Entität und Format noch keine Version existiert.
     *
     * @return array<int, FieldMapping>
     */
    public function seedDefaults(): array
    {
        $created = [];

        foreach (DefaultFieldMappings::all() as $default) {
            $exists = FieldMapping::query()
                ->where('source_system', DefaultFieldMappings::SOURCE_SYSTEM)
                ->where('entity_type', $default['entity_type'])
                ->where('source_format', $default['source_format'])
                ->exists();

            if ($exists) {
                continue;
            }

            $created[] = $this->publish($default['entity_type'], $default['source_format'], $default['mapping'], null, $default['notes'], $default['key_schema']);
        }

        return $created;
    }

    /**
     * @return array<int, FieldMapping>
     */
    public function history(string $entityType, string $sourceFormat, string $sourceSystem = DefaultFieldMappings::SOURCE_SYSTEM): array
    {
        $result = [];

        $query = FieldMapping::query()
            ->where('source_system', $sourceSystem)
            ->where('entity_type', $entityType)
            ->where('source_format', $sourceFormat)
            ->orderBy('version');

        foreach ($query->cursor() as $mapping) {
            if ($mapping instanceof FieldMapping) {
                $result[] = $mapping;
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $a
     * @param  array<int, mixed>  $b
     */
    private function sameRules(array $a, array $b): bool
    {
        $normalize = static fn (array $rules): string => json_encode(array_map(static function (mixed $rule): array {
            $rule = (array) $rule;

            return ['s' => $rule['source_field'] ?? null, 't' => $rule['target_field'] ?? null, 'x' => ($rule['transform'] ?? null) ?: null];
        }, array_values($rules)), JSON_THROW_ON_ERROR);

        return $normalize($a) === $normalize($b);
    }
}
