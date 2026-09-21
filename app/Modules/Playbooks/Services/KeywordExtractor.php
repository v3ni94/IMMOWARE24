<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Gewinnt Stichwörter aus kurzen Texten (Betreff, Titel) für den regelbasierten Vergleich von Vorgängen mit
 * Prozessvorlagen. Rein lokal, kein KI-Aufruf, damit der erste Abgleich kostenlos und sofort verfügbar ist.
 */
final class KeywordExtractor
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<int, string>
     */
    public function extract(string $text): array
    {
        $minLength = (int) $this->config->get('hub.playbooks.keywords.min_length', 4);
        $maxKeywords = (int) $this->config->get('hub.playbooks.keywords.max_keywords', 12);
        $stopwords = array_map('mb_strtolower', (array) $this->config->get('hub.playbooks.keywords.stopwords', []));

        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? '';
        $words = preg_split('/\s+/u', trim($normalized)) ?: [];

        $keywords = [];

        foreach ($words as $word) {
            if (mb_strlen($word) < $minLength || in_array($word, $stopwords, true)) {
                continue;
            }

            if (! in_array($word, $keywords, true)) {
                $keywords[] = $word;
            }

            if (count($keywords) >= $maxKeywords) {
                break;
            }
        }

        return $keywords;
    }

    /**
     * Jaccard-Ähnlichkeit zweier Stichwortlisten in Prozent (0 bis 100).
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    public function similarityPercent(array $a, array $b): int
    {
        if ($a === [] || $b === []) {
            return 0;
        }

        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? (int) round(($intersection / $union) * 100) : 0;
    }
}
