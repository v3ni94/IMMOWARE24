<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Services;

/**
 * Prüft Tool-Argumente gegen die im Katalog hinterlegte JSON-Schema-Teilmenge
 * (type, required, additionalProperties, enum, minimum, maximum, minLength, maxLength, minProperties, maxProperties).
 * Eigene Implementierung ohne Fremdpaket (CLAUDE.md Regel 8). Die fachliche Validierung erfolgt zusätzlich
 * in den FormRequests der Hub-API.
 */
final class ArgumentValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  array<string, mixed>  $arguments
     * @return array<int, array{field: string, code: string, message: string}>
     */
    public function validate(array $schema, array $arguments): array
    {
        $errors = [];
        $properties = (array) ($schema['properties'] ?? []);

        foreach ((array) ($schema['required'] ?? []) as $required) {
            if (! array_key_exists($required, $arguments) || $arguments[$required] === null || $arguments[$required] === '') {
                $errors[] = ['field' => $required, 'code' => 'required', 'message' => sprintf('Das Argument "%s" ist erforderlich.', $required)];
            }
        }

        foreach ($arguments as $name => $value) {
            if (! isset($properties[$name])) {
                if (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = ['field' => (string) $name, 'code' => 'unknown', 'message' => sprintf('Das Argument "%s" ist für dieses Tool nicht definiert.', $name)];
                }

                continue;
            }

            if ($value === null) {
                continue;
            }

            foreach ($this->check((string) $name, (array) $properties[$name], $value) as $error) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $property
     * @return array<int, array{field: string, code: string, message: string}>
     */
    private function check(string $name, array $property, mixed $value): array
    {
        $type = $property['type'] ?? null;

        $typeOk = match ($type) {
            'integer' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            default => true,
        };

        if (! $typeOk) {
            return [['field' => $name, 'code' => 'type', 'message' => sprintf('Das Argument "%s" muss vom Typ %s sein.', $name, (string) $type)]];
        }

        $errors = [];

        if (isset($property['enum']) && ! in_array($value, (array) $property['enum'], true)) {
            $errors[] = ['field' => $name, 'code' => 'enum', 'message' => sprintf('Das Argument "%s" muss einen der Werte %s haben.', $name, implode(', ', array_map('strval', (array) $property['enum'])))];
        }

        if ($type === 'integer' || $type === 'number') {
            $number = is_string($value) ? (int) $value : $value;

            if (isset($property['minimum']) && $number < $property['minimum']) {
                $errors[] = ['field' => $name, 'code' => 'minimum', 'message' => sprintf('Das Argument "%s" muss mindestens %s sein.', $name, (string) $property['minimum'])];
            }

            if (isset($property['maximum']) && $number > $property['maximum']) {
                $errors[] = ['field' => $name, 'code' => 'maximum', 'message' => sprintf('Das Argument "%s" darf höchstens %s sein.', $name, (string) $property['maximum'])];
            }
        }

        if ($type === 'string' && is_string($value)) {
            if (isset($property['minLength']) && mb_strlen($value) < (int) $property['minLength']) {
                $errors[] = ['field' => $name, 'code' => 'min_length', 'message' => sprintf('Das Argument "%s" muss mindestens %d Zeichen haben.', $name, (int) $property['minLength'])];
            }

            if (isset($property['maxLength']) && mb_strlen($value) > (int) $property['maxLength']) {
                $errors[] = ['field' => $name, 'code' => 'max_length', 'message' => sprintf('Das Argument "%s" darf höchstens %d Zeichen haben.', $name, (int) $property['maxLength'])];
            }
        }

        if ($type === 'object' && is_array($value)) {
            if (isset($property['minProperties']) && count($value) < (int) $property['minProperties']) {
                $errors[] = ['field' => $name, 'code' => 'min_properties', 'message' => sprintf('Das Argument "%s" muss mindestens %d Einträge haben.', $name, (int) $property['minProperties'])];
            }

            if (isset($property['maxProperties']) && count($value) > (int) $property['maxProperties']) {
                $errors[] = ['field' => $name, 'code' => 'max_properties', 'message' => sprintf('Das Argument "%s" darf höchstens %d Einträge haben.', $name, (int) $property['maxProperties'])];
            }
        }

        return $errors;
    }
}
