<?php

declare(strict_types=1);

namespace App\Modules\Ai\Services;

use Illuminate\Contracts\Config\Repository;

/**
 * Maskiert personenbezogene Kennungen vor dem KI-Aufruf durch Platzhalter ([IBAN_1], [TEL_1], [EMAIL_1]) und
 * hält die Zuordnung nur serverseitig für die Rückabbildung (unmask). Die Zuordnung verlässt den Prozess nie und
 * wird nicht persistiert. IBAN immer, Telefon und fremde E-Mail-Adressen laut config hub.ai.masking.
 */
final class PromptMasker
{
    private const string IBAN_PATTERN = '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?\b/u';

    private const string PHONE_PATTERN = '/(?<![\w\/\-])(?:\+\d{1,3}[ \-]?)?(?:\(?0\d{1,4}\)?[ \-\/]?)\d{2,4}(?:[ \-\/]?\d{2,4}){1,3}(?![\w\/])/u';

    private const string EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /** @var array<string, string> Platzhalter → Original */
    private array $mapping = [];

    /** @var array<string, string> Original → Platzhalter */
    private array $reverse = [];

    /** @var array<string, int> */
    private array $counters = ['IBAN' => 0, 'TEL' => 0, 'EMAIL' => 0];

    public function __construct(private readonly Repository $config) {}

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

        if ((bool) $this->config->get('hub.ai.masking.phone', true)) {
            $text = $this->replaceAll($text, self::PHONE_PATTERN, 'TEL', static fn (string $m): bool => strlen(preg_replace('/\D/', '', $m) ?? '') >= 7);
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

    /**
     * @param  callable(string): bool  $accept
     */
    private function replaceAll(string $text, string $pattern, string $kind, callable $accept): string
    {
        $result = preg_replace_callback($pattern, function (array $match) use ($kind, $accept): string {
            $original = $match[0];

            if (! $accept($original)) {
                return $original;
            }

            $normalized = $kind === 'IBAN' ? str_replace(' ', '', $original) : $original;

            if (isset($this->reverse[$normalized])) {
                return $this->reverse[$normalized];
            }

            $placeholder = sprintf('[%s_%d]', $kind, ++$this->counters[$kind]);
            $this->mapping[$placeholder] = $normalized;
            $this->reverse[$normalized] = $placeholder;

            return $placeholder;
        }, $text);

        return $result ?? $text;
    }

    private static function looksLikeIban(string $candidate): bool
    {
        $compact = str_replace(' ', '', $candidate);
        $length = strlen($compact);

        // IBAN-Längen 15 bis 34, Prüfziffer nach ISO 7064 Mod 97-10.
        if ($length < 15 || $length > 34) {
            return false;
        }

        $rearranged = substr($compact, 4).substr($compact, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord(strtoupper($char)) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = ((int) ($remainder.$chunk)) % 97;
        }

        return $remainder === 1;
    }
}
