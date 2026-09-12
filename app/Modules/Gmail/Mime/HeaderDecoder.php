<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Mime;

/**
 * Dekodiert MIME-Header: RFC 2047 (encoded-words, Q und B), RFC 2231 (Parameter-Fortsetzung und Zeichensatz),
 * Adresslisten (RFC 5322 Kurzform) und Zeichensatzumwandlung nach UTF-8 (mb_convert_encoding, iconv als Fallback).
 * Eigene Implementierung ohne Paket, nur PHP-Standardbibliothek.
 */
final class HeaderDecoder
{
    /**
     * RFC 2047: =?charset?Q?text?= und =?charset?B?text?=, angrenzende encoded-words ohne Leerzeichen dazwischen.
     */
    public function decodeText(string $value): string
    {
        $value = $this->unfold($value);

        // Leerraum zwischen zwei encoded-words wird laut RFC 2047 entfernt.
        $value = (string) preg_replace('/(\?=)\s+(=\?)/', '$1$2', $value);

        $decoded = preg_replace_callback(
            '/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
            function (array $match): string {
                $charset = $match[1];
                $language = strpos($charset, '*');

                if ($language !== false) {
                    $charset = substr($charset, 0, $language);
                }

                $encoding = strtoupper($match[2]);
                $payload = $match[3];

                if ($encoding === 'B') {
                    $raw = base64_decode($payload, true);
                    $raw = $raw === false ? '' : $raw;
                } else {
                    $raw = quoted_printable_decode(str_replace('_', ' ', $payload));
                }

                return $this->toUtf8($raw, $charset);
            },
            $value,
        );

        return trim($this->ensureUtf8((string) $decoded));
    }

    /**
     * Zerlegt einen strukturierten Header (Content-Type, Content-Disposition) in Wert und Parameter. Parameter nach
     * RFC 2231 (name*0*=, name*1*=, name*=charset'lang'wert) werden zusammengesetzt und dekodiert, RFC 2047 in
     * Parametern (verbreitete Praxis) ebenfalls.
     *
     * @return array{value: string, params: array<string, string>}
     */
    public function parseParameterized(string $header): array
    {
        $header = $this->unfold($header);
        $parts = $this->splitOnUnquoted($header, ';');
        $value = strtolower(trim((string) array_shift($parts)));

        /** @var array<string, array<int, array{value: string, encoded: bool}>> $continuations */
        $continuations = [];
        $params = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || ! str_contains($part, '=')) {
                continue;
            }

            [$name, $raw] = explode('=', $part, 2);
            $name = strtolower(trim($name));
            $raw = trim($raw);

            if ($raw !== '' && $raw[0] === '"') {
                $raw = $this->unquote($raw);
            }

            if (preg_match('/^([^*]+)\*(\d+)(\*?)$/', $name, $m) === 1) {
                $continuations[$m[1]][(int) $m[2]] = ['value' => $raw, 'encoded' => $m[3] === '*'];

                continue;
            }

            if (str_ends_with($name, '*')) {
                $params[substr($name, 0, -1)] = $this->decodeRfc2231Value($raw);

                continue;
            }

            $params[$name] = $this->decodeText($raw);
        }

        foreach ($continuations as $name => $segments) {
            ksort($segments);
            $charset = 'utf-8';
            $joined = '';

            foreach ($segments as $index => $segment) {
                if ($segment['encoded']) {
                    if ($index === 0 && preg_match("/^([^']*)'[^']*'(.*)$/s", $segment['value'], $m) === 1) {
                        $charset = $m[1] !== '' ? $m[1] : 'utf-8';
                        $joined .= rawurldecode($m[2]);
                    } else {
                        $joined .= rawurldecode($segment['value']);
                    }
                } else {
                    $joined .= $segment['value'];
                }
            }

            $params[$name] = $this->toUtf8($joined, $charset);
        }

        return ['value' => $value, 'params' => $params];
    }

    /**
     * Adressliste (From, To, Cc, Reply-To) in normalisierte Einträge. Ohne Paket, deshalb bewusst tolerant.
     *
     * @return array<int, array{email: string, name: ?string}>
     */
    public function parseAddressList(string $header): array
    {
        $header = $this->decodeText($header);
        $result = [];

        foreach ($this->splitOnUnquoted($header, ',') as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if (preg_match('/^(.*?)<([^>]+)>\s*$/s', $item, $m) === 1) {
                $name = trim($m[1]);
                $name = $name !== '' ? $this->unquote($name) : null;
                $email = strtolower(trim($m[2]));
            } else {
                $name = null;
                $email = strtolower(trim($item, " \t\"'"));
            }

            if ($email === '' || ! str_contains($email, '@')) {
                continue;
            }

            $result[] = ['email' => $email, 'name' => $name !== '' ? $name : null];
        }

        return $result;
    }

    /**
     * Message-IDs aus Message-ID, In-Reply-To, References (jede in spitzen Klammern).
     *
     * @return array<int, string>
     */
    public function parseMessageIds(string $header): array
    {
        preg_match_all('/<[^<>\s]+>/', $this->unfold($header), $matches);

        return array_values(array_unique($matches[0]));
    }

    public function toUtf8(string $raw, string $charset): string
    {
        $charset = strtolower(trim($charset, " \t\"'"));
        $charset = match ($charset) {
            '', 'us-ascii', 'ascii', 'utf8', 'utf-8' => 'UTF-8',
            'latin1', 'iso8859-1' => 'ISO-8859-1',
            'cp1252', 'win-1252' => 'Windows-1252',
            default => $charset,
        };

        if ($charset === 'UTF-8') {
            return $this->ensureUtf8($raw);
        }

        $converted = false;

        try {
            $encodings = array_map('strtolower', mb_list_encodings());

            if (in_array(strtolower($charset), $encodings, true) || in_array(strtolower(str_replace('_', '-', $charset)), $encodings, true)) {
                $converted = mb_convert_encoding($raw, 'UTF-8', $charset);
            }
        } catch (\ValueError) {
            $converted = false;
        }

        if ($converted === false && function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $raw);
        }

        return $this->ensureUtf8($converted === false ? $raw : $converted);
    }

    public function unfold(string $value): string
    {
        return (string) preg_replace('/\r?\n[ \t]+/', ' ', $value);
    }

    /**
     * Ungültige UTF-8-Sequenzen werden ersetzt statt weitergereicht.
     */
    public function ensureUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function decodeRfc2231Value(string $raw): string
    {
        if (preg_match("/^([^']*)'[^']*'(.*)$/s", $raw, $m) === 1) {
            return $this->toUtf8(rawurldecode($m[2]), $m[1] !== '' ? $m[1] : 'utf-8');
        }

        return $this->decodeText($raw);
    }

    private function unquote(string $value): string
    {
        $value = trim($value);

        if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return (string) preg_replace('/\\\\(.)/', '$1', $value);
    }

    /**
     * @return array<int, string>
     */
    private function splitOnUnquoted(string $value, string $separator): array
    {
        $result = [];
        $current = '';
        $inQuotes = false;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($char === '\\' && $inQuotes && $i + 1 < $length) {
                $current .= $char.$value[++$i];

                continue;
            }

            if ($char === '"') {
                $inQuotes = ! $inQuotes;
            }

            if ($char === $separator && ! $inQuotes) {
                $result[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $result[] = $current;

        return $result;
    }
}
