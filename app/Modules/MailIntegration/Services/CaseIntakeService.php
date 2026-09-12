<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Modules\Actions\Support\IbanValidator;
use App\Modules\Cases\DTO\CaseItemSpec;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Estate\Models\BankAccount;
use App\Modules\Gmail\Models\MailMessage;
use Illuminate\Contracts\Config\Repository;

/**
 * Leitet aus einer eingehenden Nachricht die Teilanliegen eines neuen Vorgangs ab. Regelbasiert und nachvollziehbar
 * (Schlüsselwörter aus config/hub/cases.php intake), keine KI. Eine Nachricht kann mehrere Anliegen enthalten
 * (zum Beispiel Adressänderung und Wasserschaden); ohne Treffer entsteht ein Teilanliegen anfrage_allgemein.
 * Erkannte IBANs (nur mit gültiger Prüfziffer) werden maskiert in der Beschreibung vermerkt, nie im Klartext.
 */
final class CaseIntakeService
{
    public function __construct(
        private readonly Repository $config,
        private readonly IbanValidator $ibans,
    ) {}

    /**
     * @return array<int, CaseItemSpec>
     */
    public function specsFor(MailMessage $message): array
    {
        $subject = (string) $message->getAttribute('subject');
        $body = (string) ($message->getAttribute('body_text') ?? $message->getAttribute('snippet') ?? '');
        $text = $this->normalize($subject.' '.$body);
        $rules = (array) $this->config->get('hub.cases.intake.rules', []);
        $specs = [];

        foreach ($rules as $itemType => $rule) {
            $keywords = (array) ($rule['keywords'] ?? []);
            $hit = $this->firstHit($text, $keywords);

            if ($hit === null) {
                continue;
            }

            $priority = isset($rule['priority']) ? Priority::tryFrom((string) $rule['priority']) : null;
            $description = sprintf('Erkannt über Schlüsselwort "%s" (Regel %s).', $hit, (string) $itemType);

            if (($rule['extract_iban'] ?? false) === true) {
                $found = $this->ibans->extract($subject.' '.$body);
                $description .= $found === []
                    ? ' Keine IBAN mit gültiger Prüfziffer im Text.'
                    : ' IBAN erkannt (gültige Prüfziffer): '.implode(', ', array_map(static fn (string $iban): string => BankAccount::maskIban($iban), $found)).'.';
            }

            $specs[] = new CaseItemSpec(
                itemType: (string) $itemType,
                title: (string) ($rule['title'] ?? $itemType),
                description: $description,
                priority: $priority,
            );
        }

        // Notfall und Schaden schließen sich aus: der Notfall gewinnt.
        $types = array_map(static fn (CaseItemSpec $s): string => $s->itemType, $specs);

        if (in_array('schaden_notfall', $types, true)) {
            $specs = array_values(array_filter($specs, static fn (CaseItemSpec $s): bool => $s->itemType !== 'schaden'));
        }

        if ($specs === []) {
            $fallback = (array) $this->config->get('hub.cases.intake.fallback', ['item_type' => 'anfrage_allgemein', 'title' => 'Allgemeine Anfrage']);
            $specs[] = new CaseItemSpec((string) $fallback['item_type'], (string) $fallback['title'], 'Kein Regeltreffer, Kategorie durch Sachbearbeitung zu prüfen.');
        }

        return $specs;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    private function firstHit(string $text, array $keywords): ?string
    {
        foreach ($keywords as $keyword) {
            $needle = $this->normalize((string) $keyword);

            if ($needle !== '' && str_contains($text, $needle)) {
                return (string) $keyword;
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);

        return (string) preg_replace('/\s+/u', ' ', $text);
    }
}
