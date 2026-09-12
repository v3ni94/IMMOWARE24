<?php

declare(strict_types=1);

namespace App\Modules\Actions\Support;

/**
 * Kanonische Hashes über Schritte und Alt/Neu-Werte. Freigaben binden an plan_version_id plus diff_hash;
 * jede Änderung an Alt oder Neu erzeugt einen anderen Hash und macht Freigaben ungültig.
 */
final class DiffHasher
{
    /**
     * @param  array<int|string, mixed>  $data
     */
    public function hash(array $data): string
    {
        return hash('sha256', $this->canonical($data));
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     * @param  array<int, array<string, mixed>>  $oldValues  je Schrittindex
     * @param  array<int, array<string, mixed>>  $newValues  je Schrittindex
     */
    public function diffHash(array $steps, array $oldValues, array $newValues): string
    {
        return $this->hash(['steps' => $steps, 'old' => $oldValues, 'new' => $newValues]);
    }

    /**
     * Unterschiede je Feld: ['feld' => ['old' => ..., 'new' => ...]] nur für geänderte Felder.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diff(array $old, array $new): array
    {
        $result = [];

        foreach ($new as $field => $value) {
            $before = $old[$field] ?? null;

            if ($this->canonical([$before]) !== $this->canonical([$value])) {
                $result[(string) $field] = ['old' => $before, 'new' => $value];
            }
        }

        return $result;
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    public function canonical(array $data): string
    {
        return json_encode($this->sortRecursive($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    private function sortRecursive(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(fn (mixed $v): mixed => is_array($v) ? $this->sortRecursive($v) : $this->normalizeScalar($v), $data);
        }

        ksort($data, SORT_STRING);

        foreach ($data as $key => $value) {
            $data[$key] = is_array($value) ? $this->sortRecursive($value) : $this->normalizeScalar($value);
        }

        return $data;
    }

    private function normalizeScalar(mixed $value): mixed
    {
        return is_string($value) ? trim($value) : $value;
    }
}
