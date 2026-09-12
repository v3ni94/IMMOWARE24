<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

/**
 * Eigener, bewusst kleiner JSON-Schema-Prüfer (kein Paket): type, enum, required, properties, additionalProperties,
 * items, minItems, maxItems, minLength, maxLength, minimum, maximum, format (date, date-time, email, iban_masked).
 * Prüft KI-Antworten serverseitig, unabhängig davon, was der Anbieter als "strict" zusichert.
 */
final class SchemaValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string> Fehlerliste, leer bei gültiger Antwort
     */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $errors = [];

        if (array_key_exists('type', $schema) && ! $this->matchesType($data, $schema['type'])) {
            $errors[] = sprintf('%s: erwartet Typ %s, erhalten %s', $path, $this->typeName($schema['type']), get_debug_type($data));

            return $errors;
        }

        if (array_key_exists('enum', $schema) && is_array($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $errors[] = sprintf('%s: Wert nicht in Aufzählung', $path);
        }

        if (is_string($data)) {
            $errors = array_merge($errors, $this->validateString($data, $schema, $path));
        }

        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $errors[] = sprintf('%s: kleiner als Minimum %s', $path, (string) $schema['minimum']);
            }

            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $errors[] = sprintf('%s: größer als Maximum %s', $path, (string) $schema['maximum']);
            }
        }

        if (is_array($data) && $this->isList($data) && (($schema['type'] ?? null) === 'array' || isset($schema['items']))) {
            $errors = array_merge($errors, $this->validateArray($data, $schema, $path));
        } elseif (is_array($data) && (($schema['type'] ?? null) === 'object' || isset($schema['properties']))) {
            $errors = array_merge($errors, $this->validateObject($data, $schema, $path));
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateString(string $data, array $schema, string $path): array
    {
        $errors = [];
        $length = mb_strlen($data);

        if (isset($schema['minLength']) && $length < (int) $schema['minLength']) {
            $errors[] = sprintf('%s: kürzer als %d Zeichen', $path, (int) $schema['minLength']);
        }

        if (isset($schema['maxLength']) && $length > (int) $schema['maxLength']) {
            $errors[] = sprintf('%s: länger als %d Zeichen', $path, (int) $schema['maxLength']);
        }

        if (isset($schema['format']) && is_string($schema['format']) && ! $this->matchesFormat($data, $schema['format'])) {
            $errors[] = sprintf('%s: entspricht nicht Format %s', $path, $schema['format']);
        }

        return $errors;
    }

    /**
     * @param  array<int, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateArray(array $data, array $schema, string $path): array
    {
        $errors = [];

        if (isset($schema['minItems']) && count($data) < (int) $schema['minItems']) {
            $errors[] = sprintf('%s: weniger als %d Einträge', $path, (int) $schema['minItems']);
        }

        if (isset($schema['maxItems']) && count($data) > (int) $schema['maxItems']) {
            $errors[] = sprintf('%s: mehr als %d Einträge', $path, (int) $schema['maxItems']);
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            foreach ($data as $index => $item) {
                $errors = array_merge($errors, $this->validate($item, $schema['items'], $path.'['.$index.']'));
            }
        }

        return $errors;
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @param  array<string, mixed>  $schema
     * @return array<int, string>
     */
    private function validateObject(array $data, array $schema, string $path): array
    {
        $errors = [];
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists((string) $required, $data)) {
                $errors[] = sprintf('%s: Pflichtfeld %s fehlt', $path, (string) $required);
            }
        }

        foreach ($data as $key => $value) {
            $key = (string) $key;

            if (array_key_exists($key, $properties) && is_array($properties[$key])) {
                $errors = array_merge($errors, $this->validate($value, $properties[$key], $path.'.'.$key));
            } elseif (($schema['additionalProperties'] ?? true) === false) {
                $errors[] = sprintf('%s: unbekanntes Feld %s', $path, $key);
            }
        }

        return $errors;
    }

    private function matchesType(mixed $data, mixed $type): bool
    {
        if (is_array($type)) {
            foreach ($type as $candidate) {
                if ($this->matchesType($data, $candidate)) {
                    return true;
                }
            }

            return false;
        }

        return match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'null' => $data === null,
            'array' => is_array($data) && $this->isList($data),
            'object' => is_array($data) && ($data === [] || ! $this->isList($data)),
            default => false,
        };
    }

    private function typeName(mixed $type): string
    {
        return is_array($type) ? implode('|', array_map('strval', $type)) : (string) $type;
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    private function isList(array $data): bool
    {
        return $data === [] || array_is_list($data);
    }

    private function matchesFormat(string $value, string $format): bool
    {
        return match ($format) {
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            'date-time' => preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value) === 1,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            // Maskierte IBAN aus dem PromptMasker, nie eine Klartext-IBAN.
            'iban_masked' => preg_match('/^\[IBAN_\d+\]$/', $value) === 1,
            default => true,
        };
    }
}
