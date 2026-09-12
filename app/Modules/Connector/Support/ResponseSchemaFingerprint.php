<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

/**
 * Bildet einen strukturellen Fingerabdruck einer Antwort (Elementnamen bei XML, Schlüsselpfade bei JSON,
 * sonst Content-Type). Inhalte und Werte gehen nicht ein, damit weder Secrets noch personenbezogene
 * Daten im Fingerprint landen. Dient der Erkennung von Formatänderungen des DAV-Servers.
 */
final class ResponseSchemaFingerprint
{
    private const int MAX_BODY_BYTES = 1048576;

    public function compute(?string $body, ?string $contentType = null): ?string
    {
        if ($body === null || trim($body) === '') {
            return $contentType !== null ? hash('sha256', 'content-type:'.strtolower(trim(explode(';', $contentType)[0]))) : null;
        }

        $body = substr($body, 0, self::MAX_BODY_BYTES);
        $trimmed = ltrim($body);

        if (str_starts_with($trimmed, '<')) {
            $names = $this->xmlElementNames($trimmed);

            if ($names !== []) {
                return hash('sha256', 'xml:'.implode(',', $names));
            }
        }

        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($trimmed, true);

            if (is_array($decoded)) {
                $paths = [];
                $this->collectJsonPaths($decoded, '', $paths);
                $paths = array_values(array_unique($paths));
                sort($paths);

                return hash('sha256', 'json:'.implode(',', $paths));
            }
        }

        return hash('sha256', 'content-type:'.strtolower(trim(explode(';', (string) $contentType)[0])));
    }

    /**
     * @return array<int, string>
     */
    private function xmlElementNames(string $xml): array
    {
        if (preg_match_all('/<(?!\/|\?|!)(?:[A-Za-z_][\w.\-]*:)?([A-Za-z_][\w.\-]*)/', $xml, $matches) === false) {
            return [];
        }

        $names = array_values(array_unique(array_map('strtolower', $matches[1])));
        sort($names);

        return $names;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<int, string>  $paths
     */
    private function collectJsonPaths(array $data, string $prefix, array &$paths): void
    {
        foreach ($data as $key => $value) {
            $segment = is_int($key) ? '[]' : (string) $key;
            $path = $prefix === '' ? $segment : $prefix.'.'.$segment;
            $paths[] = $path;

            if (is_array($value) && count($paths) < 2000) {
                $this->collectJsonPaths($value, $path, $paths);
            }
        }
    }
}
