<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Maskiert personenbezogene Kennungen vor dem KI-Aufruf durch Platzhalter ([IBAN_1], [TEL_1], [EMAIL_1], [URL_1],
 * [ADRESSE_1], [NR_1], [NAME_1]) und hält die Zuordnung nur serverseitig für die Rückabbildung (unmask). Die
 * Zuordnung verlässt den Prozess nie und wird nicht persistiert. IBAN immer (auch in Kleinschreibung und mit
 * Bindestrich oder Punkt als Trenner), alle anderen Arten laut config hub.ai.masking (docs/mail/08 Abschnitt 4).
 * Personennamen werden aus den bekannten Absendern und Empfängern (withKnownNames) sowie aus Anreden und
 * Grußformeln erkannt; eine vollständige Namenserkennung ohne Wörterbuch ist nicht möglich und wird nicht behauptet.
 */
final class PromptMasker
{
    private const string IBAN_PATTERN = '/(?<![A-Za-z0-9])[A-Za-z]{2}\d{2}(?:[ \-.]?[A-Za-z0-9]{4}){2,7}(?:[ \-.]?[A-Za-z0-9]{1,4})?(?![A-Za-z0-9])/u';

    private const string PHONE_PATTERN = '/(?<![\w\/\-])(?:\+\d{1,3}[ \-]?)?(?:\(?0\d{1,4}\)?[ \-\/]?)\d{2,4}(?:[ \-\/]?\d{2,4}){1,3}(?![\w\/])/u';

    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    private const string URL_PATTERN = '/\b(?:https?:\/\/|www\.)[^\s<>"\']+/iu';

    // Straße mit Hausnummer, optional gefolgt von PLZ und Ort (z. B. "Musterstraße 12a, 40210 Düsseldorf").
    private const string ADDRESS_PATTERN = '/\b[A-ZÄÖÜ][\p{L}\-.]*(?:\s[\p{L}\-.]+)*(?:straße|strasse|str\.|weg|allee|platz|gasse|ring|damm|ufer|chaussee|promenade|markt|steig|pfad)\s\d{1,4}\s?[a-zA-Z]?(?:\s?[\/-]\s?\d{1,3})?(?:\s*,?\s*(?:in\s)?\d{5}\s[\p{Lu}][\p{L}\-. ]+?)?(?=[\s,.;:!?)]|$)/u';

    // Kunden-, Vertrags-, Objekt-, Mieter-, Eigentümer-, Rechnungs- und Aktennummern mit Kennwort davor.
    private const string NUMBER_PATTERN = '/\b((?:kunden|vertrags|objekt|mieter|eigentümer|eigentuemer|rechnungs|akten|wohnungs|einheiten|debitoren|kreditoren|buchungs|mitglieds|versicherungs|schadens?)-?(?:nummer|nr\.?|no\.?|id)|(?:az|aktenzeichen|zeichen))\s*[:.]?\s*([A-Z0-9][A-Z0-9\-\/. ]{2,30}[A-Z0-9])(?![\p{L}\d])/iu';

    // Anrede und Grußformel: "Sehr geehrte Frau Muster", "Hallo Herr Dr. Muster", "Mit freundlichen Grüßen\nMax Muster".
    private const string SALUTATION_PATTERN = '/((?:sehr\s+geehrte[rs]?|liebe[rs]?|guten\s+tag|hallo|moin|servus|werte[rs]?)\s+(?:herr|frau|familie|hr\.|fr\.)\s+(?:(?:dr\.|prof\.|dipl\.-ing\.|med\.)\s+)*)([\p{Lu}][\p{L}\'\-]+(?:\s+[\p{Lu}][\p{L}\'\-]+){0,3})/iu';

    private const string CLOSING_PATTERN = '/((?:mit\s+(?:freundlichen|besten|herzlichen|lieben)\s+gr(?:ü|ue)(?:ß|ss)en|freundliche\s+gr(?:ü|ue)(?:ß|ss)e|viele\s+gr(?:ü|ue)(?:ß|ss)e|beste\s+gr(?:ü|ue)(?:ß|ss)e|liebe\s+gr(?:ü|ue)(?:ß|ss)e|hochachtungsvoll|mfg|vg|lg)\s*,?\s*(?:i\.\s?a\.\s*|i\.\s?v\.\s*|ppa\.\s*)?\n?\s*)([\p{Lu}][\p{L}\'\-]+(?:\s+[\p{Lu}][\p{L}\'\-]+){1,3})(?=\s*$|\s*\n)/imu';

    /** @var array<string, string> Platzhalter → Original */
    private array $mapping = [];

    /** @var array<string, string> Original (normalisiert) → Platzhalter */
    private array $reverse = [];

    /** @var array<string, int> */
    private array $counters = ['IBAN' => 0, 'TEL' => 0, 'EMAIL' => 0, 'URL' => 0, 'ADRESSE' => 0, 'NR' => 0, 'NAME' => 0];

    /** @var array<int, string> Bekannte Personennamen (Absender, Empfänger), längste zuerst */
    private array $knownNames = [];

    public function __construct(private readonly Repository $config) {}

    /**
     * Personennamen aus Kopfzeilen (From, To, Cc) registrieren; sie werden im Text vollständig ersetzt.
     *
     * @param  array<int, string|null>  $names
     */
    public function withKnownNames(array $names): self
    {
        foreach ($names as $name) {
            $name = trim((string) $name, " \t\"'<>");

            if (mb_strlen($name) < 3 || str_contains($name, '@') || preg_match('/\p{L}/u', $name) !== 1) {
                continue;
            }

            $this->knownNames[] = $name;
        }

        $this->knownNames = array_values(array_unique($this->knownNames));
        usort($this->knownNames, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return $this;
    }

    public function mask(string $text): string
    {
        if ((bool) $this->config->get('hub.ai.masking.iban', true)) {
            $text = $this->replaceAll($text, self::IBAN_PATTERN, 'IBAN', static fn (string $m): bool => self::looksLikeIban($m));
        }

        if ((bool) $this->config->get('hub.ai.masking.email', true)) {
            $own = array_map('strtolower', (array) $this->config->get('hub.ai.masking.own_domains', []));
            $text = $this->replaceAll($text, self::EMAIL_PATTERN, 'EMAIL', static function (string $m) use ($own): bool {
                $domain = strtolower((string) substr($m, (int) strrpos($m, '@') + 1));

                return ! in_array($domain, $own, true);
            });
        }

        if ((bool) $this->config->get('hub.ai.masking.url', true)) {
            $text = $this->replaceAll($text, self::URL_PATTERN, 'URL', static fn (string $m): bool => true);
        }

        if ((bool) $this->config->get('hub.ai.masking.address', true)) {
            $text = $this->replaceAll($text, self::ADDRESS_PATTERN, 'ADRESSE', static fn (string $m): bool => true);
        }

        if ((bool) $this->config->get('hub.ai.masking.numbers', true)) {
            $text = $this->replaceAll($text, self::NUMBER_PATTERN, 'NR', static fn (string $m): bool => preg_match('/\d/', $m) === 1, group: 2);
        }

        if ((bool) $this->config->get('hub.ai.masking.phone', true)) {
            $text = $this->replaceAll($text, self::PHONE_PATTERN, 'TEL', static fn (string $m): bool => strlen(preg_replace('/\D/', '', $m) ?? '') >= 7);
        }

        if ((bool) $this->config->get('hub.ai.masking.names', true)) {
            $text = $this->maskNames($text);
        }

        return $text;
    }

    /**
     * Maskiert rekursiv alle Zeichenketten einer Eingabestruktur.
     *
     * @param  array<mixed, mixed>  $input
     * @return array<mixed, mixed>
     */
    public function maskArray(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_string($value)) {
                $input[$key] = $this->mask($value);
            } elseif (is_array($value)) {
                $input[$key] = $this->maskArray($value);
            }
        }

        return $input;
    }

    /**
     * Rückabbildung nur serverseitig. Unbekannte Platzhalter bleiben stehen (die KI darf keine erfinden).
     */
    public function unmask(string $text): string
    {
        if ($this->mapping === []) {
            return $text;
        }

        return strtr($text, $this->mapping);
    }

    /**
     * @param  array<mixed, mixed>  $data
     * @return array<mixed, mixed>
     */
    public function unmaskArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = $this->unmask($value);
            } elseif (is_array($value)) {
                $data[$key] = $this->unmaskArray($value);
            }
        }

        return $data;
    }

    /**
     * @return array<string, string> Platzhalter → Original (nur für Tests und serverseitige Anzeige)
     */
    public function mapping(): array
    {
        return $this->mapping;
    }

    public function placeholderCount(): int
    {
        return count($this->mapping);
    }

    private function maskNames(string $text): string
    {
        foreach ($this->knownNames as $name) {
            $pattern = '/(?<!\p{L})'.preg_quote($name, '/').'(?!\p{L})/u';
            $text = $this->replaceAll($text, $pattern, 'NAME', static fn (string $m): bool => true);
        }

        $text = $this->replaceAll($text, self::SALUTATION_PATTERN, 'NAME', static fn (string $m): bool => true, group: 2);

        return $this->replaceAll($text, self::CLOSING_PATTERN, 'NAME', static fn (string $m): bool => true, group: 2);
    }

    /**
     * @param  callable(string): bool  $accept
     * @param  int  $group  Gruppe des Treffers, die ersetzt wird (0 = gesamter Treffer); der Rest bleibt stehen
     */
    private function replaceAll(string $text, string $pattern, string $kind, callable $accept, int $group = 0): string
    {
        $result = preg_replace_callback($pattern, function (array $match) use ($kind, $accept, $group): string {
            $original = $match[$group] ?? $match[0];

            if ($original === '' || ! $accept($original)) {
                return $match[0];
            }

            $normalized = $this->normalize($kind, $original);

            if (! isset($this->reverse[$normalized])) {
                $placeholder = sprintf('[%s_%d]', $kind, ++$this->counters[$kind]);
                $this->mapping[$placeholder] = $normalized;
                $this->reverse[$normalized] = $placeholder;
            }

            $placeholder = $this->reverse[$normalized];

            if ($group === 0) {
                return $placeholder;
            }

            $offset = strpos($match[0], $original);

            return $offset === false ? $match[0] : substr_replace($match[0], $placeholder, $offset, strlen($original));
        }, $text);

        return $result ?? $text;
    }

    private function normalize(string $kind, string $original): string
    {
        return match ($kind) {
            'IBAN' => strtoupper(str_replace([' ', '-', '.'], '', $original)),
            'NAME', 'ADRESSE' => trim((string) preg_replace('/\s+/u', ' ', $original)),
            default => $original,
        };
    }

    private static function looksLikeIban(string $candidate): bool
    {
        $compact = strtoupper(str_replace([' ', '-', '.'], '', $candidate));
        $length = strlen($compact);

        // IBAN-Längen 15 bis 34, Prüfziffer nach ISO 7064 Mod 97-10.
        if ($length < 15 || $length > 34 || ! ctype_alnum($compact)) {
            return false;
        }

        $rearranged = substr($compact, 4).substr($compact, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = ((int) ($remainder.$chunk)) % 97;
        }

        return $remainder === 1;
    }
}
