<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;

/**
 * Zuordnung eines Dokuments zu property, unit oder contact ausschließlich über explizite Mapping-Regeln
 * (config hub.documents.assignment_rules). Ohne passende Regel wird nichts zugeordnet, es wird nie geraten.
 * Ergebnis trägt immer eine Konfidenz (exact, derived, uncertain) und den Namen der Regel.
 */
final class DocumentAssignmentResolver
{
    /**
     * @param  array<int, array{entity: string, pattern: string, column: string, confidence?: string}>  $rules
     */
    public function __construct(private readonly array $rules) {}

    /**
     * @return array{property_id: int|null, unit_id: int|null, contact_id: int|null, assignment_confidence: string|null, assignment_rule: string|null}
     */
    public function resolve(int $organizationId, string $documentPath): array
    {
        $empty = ['property_id' => null, 'unit_id' => null, 'contact_id' => null, 'assignment_confidence' => null, 'assignment_rule' => null];

        if ($this->rules === []) {
            return $empty;
        }

        $segments = WebDavPath::folderSegments($documentPath);

        foreach ($this->rules as $index => $rule) {
            $entity = (string) ($rule['entity'] ?? '');
            $pattern = (string) ($rule['pattern'] ?? '');
            $column = (string) ($rule['column'] ?? '');

            if ($entity === '' || $pattern === '' || $column === '' || preg_match('/^[a-z_]+$/', $column) !== 1) {
                continue;
            }

            foreach ($segments as $segment) {
                if (@preg_match($pattern, $segment, $m) !== 1 || ! isset($m['key']) || trim((string) $m['key']) === '') {
                    continue;
                }

                $key = trim((string) $m['key']);
                $matches = $this->lookup($entity, $organizationId, $column, $key);

                if ($matches === null || count($matches) !== 1) {
                    // Kein oder mehrdeutiger Treffer: keine Zuordnung, kein Raten.
                    continue;
                }

                $confidence = (string) ($rule['confidence'] ?? 'derived');
                $ruleName = (string) ($rule['name'] ?? ($entity.'#'.$index));

                return [
                    'property_id' => $entity === 'property' ? $matches[0] : null,
                    'unit_id' => $entity === 'unit' ? $matches[0] : null,
                    'contact_id' => $entity === 'contact' ? $matches[0] : null,
                    'assignment_confidence' => in_array($confidence, ['exact', 'derived', 'uncertain'], true) ? $confidence : 'derived',
                    'assignment_rule' => substr($ruleName, 0, 80),
                ];
            }
        }

        return $empty;
    }

    /**
     * @return array<int, int>|null
     */
    private function lookup(string $entity, int $organizationId, string $column, string $key): ?array
    {
        $query = match ($entity) {
            'property' => Property::query(),
            'unit' => Unit::query(),
            'contact' => Contact::query(),
            default => null,
        };

        if ($query === null) {
            return null;
        }

        $ids = $query
            ->where('organization_id', $organizationId)
            ->where($column, $key)
            ->limit(2)
            ->pluck('id')
            ->all();

        return array_map('intval', $ids);
    }
}
