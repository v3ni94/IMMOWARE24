<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

/**
 * Dokumenttyp-Heuristik über eine konfigurierbare Regex-Map (config hub.documents.type_rules).
 * Erste passende Regel gewinnt; geprüft werden Dateiname und anschließend der Ordnerpfad.
 */
final class DocumentTypeClassifier
{
    /**
     * @param  array<int, array{type: string, pattern: string}>  $rules
     */
    public function __construct(private readonly array $rules) {}

    /**
     * @return array{type: string|null, rule: string|null}
     */
    public function classify(string $filename, string $folderPath): array
    {
        foreach ($this->rules as $rule) {
            $pattern = (string) ($rule['pattern'] ?? '');
            $type = (string) ($rule['type'] ?? '');

            if ($pattern === '' || $type === '') {
                continue;
            }

            if (@preg_match($pattern, $filename) === 1) {
                return ['type' => $type, 'rule' => 'filename:'.$type];
            }

            if (@preg_match($pattern, $folderPath) === 1) {
                return ['type' => $type, 'rule' => 'folder:'.$type];
            }
        }

        return ['type' => null, 'rule' => null];
    }
}
