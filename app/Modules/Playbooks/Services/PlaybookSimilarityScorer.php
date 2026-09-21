<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Services;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Enums\PlaybookStatus;
use App\Modules\Playbooks\Models\Playbook;
use Illuminate\Support\Collection;

/**
 * Regelbasierter, kostenloser Vergleich eines Vorgangs mit vorhandenen aktiven Prozessvorlagen. Eine Vorlage
 * kommt nur infrage, wenn die Kategorie (case_type) übereinstimmt; die Punktzahl (0 bis 100) setzt sich aus der
 * Stichwortähnlichkeit von Titel und Kategorie sowie einem Zuschlag für gleiches Objekt oder gleiche Einheit
 * zusammen.
 */
final class PlaybookSimilarityScorer
{
    private const int OBJECT_MATCH_BONUS = 15;

    public function __construct(private readonly KeywordExtractor $keywords) {}

    /**
     * @return Collection<int, Playbook>
     */
    public function candidatesFor(MailCase $case): Collection
    {
        $query = Playbook::query()->where('case_type', (string) $case->getAttribute('case_type'));
        $query->where('status', PlaybookStatus::Active->value);
        $query->orderByDesc('times_accepted');

        return $query->get();
    }

    /**
     * @return array<int, string>
     */
    public function keywordsFor(MailCase $case): array
    {
        $tags = (array) $case->getAttribute('tags_json');
        $text = trim((string) $case->getAttribute('title')).' '.implode(' ', array_map('strval', $tags));

        return $this->keywords->extract($text);
    }

    /**
     * @param  array<int, string>  $caseKeywords
     */
    public function score(MailCase $case, Playbook $playbook, array $caseKeywords): int
    {
        if ((string) $playbook->getAttribute('case_type') !== (string) $case->getAttribute('case_type')) {
            return 0;
        }

        $keywordScore = $this->keywords->similarityPercent($caseKeywords, $playbook->keywords());
        $bonus = $this->hasSameObjectScope($case, $playbook) ? self::OBJECT_MATCH_BONUS : 0;

        return min(100, $keywordScore + $bonus);
    }

    private function hasSameObjectScope(MailCase $case, Playbook $playbook): bool
    {
        $signature = (array) $playbook->getAttribute('match_signature_json');
        $propertyId = $signature['property_id'] ?? null;

        return $propertyId !== null && (int) $propertyId === (int) $case->getAttribute('property_id');
    }
}
