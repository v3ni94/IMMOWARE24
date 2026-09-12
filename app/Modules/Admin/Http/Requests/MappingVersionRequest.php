<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Sync\Enums\SyncEntity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Neue Mapping-Version: Regeln als Textzeilen "immoware_feld => hub_feld | transform" (Transform optional).
 */
final class MappingVersionRequest extends FormRequest
{
    /**
     * Rechteprüfung vor der Validierung (403 statt Validierungsfehler für unberechtigte Rollen).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('connections.manage') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(SyncEntity::values())],
            'source_format' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_\-]+$/'],
            'rules_text' => ['required', 'string', 'max:60000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['entity_type' => 'Entität', 'source_format' => 'Quellformat', 'rules_text' => 'Regeln', 'notes' => 'Notiz'];
    }

    /**
     * Zerlegt den Regeltext. Leere Zeilen und Zeilen mit # werden übersprungen.
     *
     * @return array<int, array{source_field: string, target_field: string, transform: string|null}>
     */
    public static function parseRules(string $text): array
    {
        $rules = [];

        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $index => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! str_contains($line, '=>')) {
                throw new \InvalidArgumentException(sprintf('Zeile %d: erwartet "quelle => ziel | transform".', $index + 1));
            }

            [$source, $rest] = array_map('trim', explode('=>', $line, 2));
            $transform = null;
            $target = $rest;

            if (str_contains($rest, '|')) {
                [$target, $transform] = array_map('trim', explode('|', $rest, 2));
                $transform = $transform === '' ? null : $transform;
            }

            if ($source === '' || $target === '') {
                throw new \InvalidArgumentException(sprintf('Zeile %d: Quelle und Ziel dürfen nicht leer sein.', $index + 1));
            }

            $rules[] = ['source_field' => $source, 'target_field' => $target, 'transform' => $transform];
        }

        if ($rules === []) {
            throw new \InvalidArgumentException('Mindestens eine Regel ist erforderlich.');
        }

        return $rules;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rules
     */
    public static function rulesToText(array $rules): string
    {
        $lines = [];

        foreach ($rules as $rule) {
            $rule = (array) $rule;
            $line = ($rule['source_field'] ?? '').' => '.($rule['target_field'] ?? '');

            if (! empty($rule['transform'])) {
                $line .= ' | '.$rule['transform'];
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
