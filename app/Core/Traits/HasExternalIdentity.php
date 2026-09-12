<?php

declare(strict_types=1);

namespace App\Core\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Herkunftsblock für alle Spiegeldaten aus Immoware24.
 * Spalten: source_system, external_id, external_id_hash, external_parent_id, external_updated_at,
 * first_synced_at, last_synced_at, checksum, sync_version, deleted_at (Soft Delete, kein Hard Delete).
 *
 * @mixin Model
 */
trait HasExternalIdentity
{
    use SoftDeletes;

    public const string SOURCE_IMMOWARE24 = 'immoware24';

    public const string SOURCE_HUB = 'hub';

    public static function bootHasExternalIdentity(): void
    {
        static::saving(function (Model $model): void {
            $externalId = $model->getAttribute('external_id');

            if (is_string($externalId) && $externalId !== '') {
                $model->setAttribute('external_id_hash', hash('sha256', $externalId));
            }

            if ($model->getAttribute('sync_version') === null) {
                $model->setAttribute('sync_version', 1);
            }
        });
    }

    public function initializeHasExternalIdentity(): void
    {
        $this->mergeCasts([
            'external_updated_at' => 'immutable_datetime',
            'first_synced_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'missing_since' => 'immutable_datetime',
            'stale_since' => 'immutable_datetime',
            'sync_version' => 'integer',
        ]);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSource(Builder $query, string $sourceSystem): Builder
    {
        return $query->where($this->qualifyColumn('source_system'), $sourceSystem);
    }

    /**
     * Zuordnung ausschließlich über die externe ID, nie über Namen oder E-Mail.
     */
    public static function findByExternalId(string $sourceSystem, string $externalId, ?int $connectionId = null): ?static
    {
        $query = static::query()
            ->withTrashed()
            ->where('source_system', $sourceSystem)
            ->where('external_id_hash', hash('sha256', $externalId));

        if ($connectionId !== null) {
            $query->where('connection_id', $connectionId);
        }

        /** @var static|null $model */
        $model = $query->first();

        return $model;
    }

    /**
     * Deterministische Prüfsumme des normalisierten Datensatzes, unabhängig von der Key-Reihenfolge.
     *
     * @param  array<mixed>  $normalized
     */
    public static function computeChecksum(array $normalized): string
    {
        return hash('sha256', json_encode(self::sortKeysRecursively($normalized), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Setzt Checksumme und erhöht sync_version nur bei tatsächlicher Änderung.
     *
     * @param  array<mixed>  $normalized
     * @return bool true, wenn sich die Prüfsumme geändert hat
     */
    public function applyChecksum(array $normalized): bool
    {
        $checksum = self::computeChecksum($normalized);

        if ($this->getAttribute('checksum') === $checksum) {
            return false;
        }

        if ($this->getAttribute('checksum') !== null) {
            $this->setAttribute('sync_version', ((int) $this->getAttribute('sync_version')) + 1);
        }

        $this->setAttribute('checksum', $checksum);

        return true;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    private static function sortKeysRecursively(array $data): array
    {
        $isList = array_is_list($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeysRecursively($value);
            }
        }

        if (! $isList) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
