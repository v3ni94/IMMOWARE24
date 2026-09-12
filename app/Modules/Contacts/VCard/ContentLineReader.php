<?php

declare(strict_types=1);

namespace App\Modules\Contacts\VCard;

/**
 * Zerlegt vCard- oder iCalendar-Text in Content-Lines: Zeilenenden normalisieren, Line-Unfolding
 * (RFC 6350 Abschnitt 3.2, RFC 5545 Abschnitt 3.1), Quoted-Printable-Soft-Breaks (vCard 2.1/3.0 Praxis),
 * Parameter mit Anführungszeichen und RFC-6868-Caret-Escapes, Transportkodierung (QUOTED-PRINTABLE, BASE64, b)
 * und CHARSET nach UTF-8. Eigene Implementierung ohne Fremdpakete.
 */
final class ContentLineReader
{
    /**
     * @return array<int, ContentLine>
     */
    public function read(string $text): array
    {
        $lines = $this->unfold($text);
        $result = [];

        foreach ($lines as $line) {
            $parsed = $this->parseLine($line);

            if ($parsed !== null) {
                $result[] = $parsed;
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function unfold(string $text): array
    {
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
        }

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $raw = explode("\n", $text);
        $lines = [];
        $index = -1;

        foreach ($raw as $line) {
            if ($line === '' && $index >= 0 && ! $this->isQuotedPrintableContinuation($lines[$index])) {
                continue;
            }

            if ($index >= 0 && ($line !== '' && ($line[0] === ' ' || $line[0] === "\t"))) {
                $lines[$index] .= substr($line, 1);

                continue;
            }

            if ($index >= 0 && $this->isQuotedPrintableContinuation($lines[$index])) {
                $lines[$index] = substr($lines[$index], 0, -1).$line;

                continue;
            }

            if ($line === '') {
                continue;
            }

            $lines[] = $line;
            $index++;
        }

        return $lines;
    }

    public function parseLine(string $line): ?ContentLine
    {
        $colon = $this->findUnquoted($line, ':');

        if ($colon === null) {
            return null;
        }

        $head = substr($line, 0, $colon);
        $rawValue = substr($line, $colon + 1);

        $headParts = $this->splitUnquoted($head, ';');
        $nameToken = array_shift($headParts) ?? '';
        $group = null;
        $name = strtoupper(trim($nameToken));

        if (str_contains($name, '.')) {
            [$group, $name] = explode('.', $name, 2);
            $group = trim($group);
        }

        if ($name === '') {
            return null;
        }

        $parameters = [];

        foreach ($headParts as $param) {
            if ($param === '') {
                continue;
            }

            $eq = strpos($param, '=');

            if ($eq === false) {
                // vCard 2.1: TEL;WORK;VOICE ohne TYPE=
                $parameters['TYPE'][] = $this->decodeParameterValue($param);

                continue;
            }

            $key = strtoupper(trim(substr($param, 0, $eq)));
            $valuePart = substr($param, $eq + 1);

            foreach ($this->splitUnquoted($valuePart, ',') as $value) {
                $decoded = $this->decodeParameterValue($value);

                // TYPE="work,pref" (RFC 6350 erlaubt Listen in Anführungszeichen) als Liste behandeln
                if ($key === 'TYPE' && str_contains($decoded, ',')) {
                    foreach (explode(',', $decoded) as $type) {
                        $parameters[$key][] = trim($type);
                    }

                    continue;
                }

                $parameters[$key][] = $decoded;
            }
        }

        [$value, $binary] = $this->decodeValue($rawValue, $parameters);

        return new ContentLine($name, $parameters, $value, $group, $binary);
    }

    /**
     * @param  array<string, array<int, string>>  $parameters
     * @return array{0: string, 1: bool}
     */
    private function decodeValue(string $value, array $parameters): array
    {
        $encoding = strtoupper($parameters['ENCODING'][0] ?? '');
        $binary = false;

        if ($encoding === 'QUOTED-PRINTABLE') {
            $value = quoted_printable_decode($value);
        } elseif ($encoding === 'BASE64' || $encoding === 'B') {
            $decoded = base64_decode(preg_replace('/\s+/', '', $value) ?? '', true);
            $value = $decoded === false ? $value : $decoded;
            $binary = true;
        }

        $charset = strtoupper($parameters['CHARSET'][0] ?? '');

        if (! $binary && $charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8') {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
            $value = is_string($converted) ? $converted : $value;
        } elseif (! $binary && ! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
        }

        return [$value, $binary];
    }

    /**
     * RFC 6868: ^n Zeilenumbruch, ^^ Caret, ^' Anführungszeichen. Umschließende Anführungszeichen entfernen.
     */
    private function decodeParameterValue(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return str_replace(['^n', '^N', "^'", '^^'], ["\n", "\n", '"', '^'], $value);
    }

    private function isQuotedPrintableContinuation(string $line): bool
    {
        return str_ends_with($line, '=') && preg_match('/;ENCODING=QUOTED-PRINTABLE[;:]/i', $line) === 1;
    }

    private function findUnquoted(string $text, string $char): ?int
    {
        $inQuotes = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            if ($text[$i] === '"') {
                $inQuotes = ! $inQuotes;
            } elseif ($text[$i] === $char && ! $inQuotes) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function splitUnquoted(string $text, string $delimiter): array
    {
        $parts = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '"') {
                $inQuotes = ! $inQuotes;
                $current .= $char;

                continue;
            }

            if ($char === $delimiter && ! $inQuotes) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }
}
