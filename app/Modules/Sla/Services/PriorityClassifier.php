<?php

declare(strict_types=1);

namespace App\Modules\Sla\Services;

use App\Modules\Cases\Enums\Priority;
use App\Modules\Sla\DTO\PriorityDecision;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Regelbasierte Prioritätsvorstufe P0 bis P3 (kein Modelltraining). Keyword-Regeln für Notfallverdacht
 * (Wasser tritt aus, Brand, Gasgeruch, akute Gefahr). Verneinte oder historische Formulierungen werden konservativ
 * als P1 mit Prüfhinweis behandelt, nie darunter. Eine KI-Einschätzung darf P0 nie herabstufen (merge()).
 */
final class PriorityClassifier
{
    public function __construct(private readonly Repository $config) {}

    public function classify(string $subject, string $body, ?CarbonImmutable $receivedAt = null, ?CarbonImmutable $importedAt = null): PriorityDecision
    {
        $text = $this->normalize($subject.' '.$body);
        $stale = $receivedAt !== null && $importedAt !== null
            && $receivedAt->diffInDays($importedAt) >= (int) $this->config->get('hub.sla.stale_after_days', 3);

        $p0 = $this->match($text, (array) $this->config->get('hub.sla.priority_rules.p0', []));

        if ($p0 !== null) {
            if ($stale) {
                return new PriorityDecision(Priority::P1, sprintf('Altmail mit Notfallbegriff "%s": P1 mit Hinweis an die Teamleitung statt Notfall-Eskalation.', $p0['keyword']), $p0['keyword'], false, 'Altmail mit Notfallmerkmalen, Teamleitung prüft.');
            }

            if ($p0['negated']) {
                return new PriorityDecision(Priority::P1, sprintf('Notfallbegriff "%s" in verneinter oder historischer Formulierung ("%s"), konservativ P1 mit Prüfung.', $p0['keyword'], $p0['marker']), $p0['keyword'], false, 'Notfallbegriff in verneinter oder historischer Formulierung, Prüfung erforderlich.');
            }

            return new PriorityDecision(Priority::P0, sprintf('Notfallverdacht durch Regel "%s".', $p0['keyword']), $p0['keyword'], true);
        }

        $p1 = $this->match($text, (array) $this->config->get('hub.sla.priority_rules.p1', []));

        if ($p1 !== null) {
            if ($p1['negated']) {
                return new PriorityDecision($stale ? Priority::P3 : Priority::P2, sprintf('Dringlichkeitsbegriff "%s" in verneinter oder historischer Formulierung.', $p1['keyword']), $p1['keyword'], false, 'Dringlichkeitsbegriff in verneinter oder historischer Formulierung, Prüfung empfohlen.');
            }

            return new PriorityDecision(Priority::P1, sprintf('Dringend durch Regel "%s".', $p1['keyword']), $p1['keyword']);
        }

        if ($stale) {
            return new PriorityDecision(Priority::P3, sprintf('Altmail: Empfang liegt mindestens %d Tage vor dem Import.', (int) $this->config->get('hub.sla.stale_after_days', 3)));
        }

        return new PriorityDecision(Priority::P2, 'Standardpriorität, keine Regel getroffen.');
    }

    /**
     * Zusammenführung mit einem KI-Vorschlag: P0 der Regel bleibt immer P0; sonst gilt die höhere Priorität, wenn der
     * KI-Vorschlag bestätigt ist (aiConfirmed), andernfalls die Regel.
     */
    public function merge(PriorityDecision $rule, ?Priority $aiSuggestion, bool $aiConfirmed = false): PriorityDecision
    {
        if ($aiSuggestion === null || $rule->priority === Priority::P0) {
            return $rule;
        }

        if (! $aiConfirmed) {
            return $rule;
        }

        if ($aiSuggestion === Priority::P0) {
            // KI darf einen Notfall nur mit Bestätigung durch einen Menschen hochstufen; die Regel bleibt Quelle der Wahrheit.
            return new PriorityDecision(Priority::P0, 'P0 durch bestätigten KI-Vorschlag.', $rule->matchedRule, true, null, 'ai_confirmed');
        }

        if ($aiSuggestion->isHigherThan($rule->priority)) {
            return new PriorityDecision($aiSuggestion, 'Höhere Priorität durch bestätigten KI-Vorschlag.', $rule->matchedRule, false, $rule->reviewHint, 'ai_confirmed');
        }

        return $rule;
    }

    /**
     * @param  array<int, string>  $keywords
     * @return array{keyword: string, negated: bool, marker: ?string}|null
     */
    private function match(string $text, array $keywords): ?array
    {
        $markers = array_map(fn (mixed $m): string => $this->normalize((string) $m), (array) $this->config->get('hub.sla.negation_markers', []));
        $window = (int) $this->config->get('hub.sla.negation_window_chars', 60);
        $best = null;

        foreach ($keywords as $keyword) {
            $needle = $this->normalize((string) $keyword);

            if ($needle === '') {
                continue;
            }

            $offset = 0;

            while (($pos = mb_strpos($text, $needle, $offset)) !== false) {
                $context = mb_substr($text, max(0, $pos - $window), $window + mb_strlen($needle) + intdiv($window, 3));
                $marker = null;

                foreach ($markers as $m) {
                    if ($m !== '' && mb_strpos($context, $m) !== false) {
                        $marker = trim($m);
                        break;
                    }
                }

                if ($marker === null) {
                    return ['keyword' => (string) $keyword, 'negated' => false, 'marker' => null];
                }

                $best ??= ['keyword' => (string) $keyword, 'negated' => true, 'marker' => $marker];
                $offset = $pos + mb_strlen($needle);
            }
        }

        return $best;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = str_replace(['ß'], ['ss'], $text);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
