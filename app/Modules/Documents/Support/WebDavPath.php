<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Normalizer;

/**
 * Normalisierung von WebDAV-Pfaden: URL-Dekodierung, NFC, Auflösung von Punktsegmenten,
 * Entfernen doppelter Schrägstriche. Ordner enden mit "/", Dateien nicht. Der normalisierte
 * Pfad relativ zur Freigabe-URL ist die external_id eines Dokuments bzw. Ordners.
 */
final class WebDavPath
{
    /**
     * Normalisiert einen bereits relativen Pfad. Punktsegmente werden aufgelöst; ".." oberhalb der Wurzel
     * führt nicht zum Fehler, sondern wird verworfen (der Aufrufer prüft Präfixe separat über isWithin()).
     */
    public static function normalize(string $path, ?bool $collection = null): string
    {
        $decoded = self::decode($path);
        $decoded = str_replace('\\', '/', $decoded);
        $endsWithSlash = str_ends_with($decoded, '/');

        $segments = [];

        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        $result = '/'.implode('/', $segments);

        $collection ??= $endsWithSlash || $segments === [];

        if ($collection && ! str_ends_with($result, '/')) {
            $result .= '/';
        }

        return $result;
    }

    /**
     * Wandelt einen href aus einer Multistatus-Antwort in den normalisierten Pfad relativ zur Freigabe um.
     * href kann absolut (https://host/share/Ordner/) oder pfadabsolut (/share/Ordner/) sein.
     */
    public static function fromHref(string $href, ?string $baseUrl, ?bool $collection = null): string
    {
        $href = trim($href);
        $hrefPath = $href;

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $href) === 1) {
            $parsed = parse_url($href, PHP_URL_PATH);
            $hrefPath = is_string($parsed) ? $parsed : '/';
        }

        $normalizedHref = self::normalize($hrefPath, $collection);
        $basePath = self::basePath($baseUrl);

        if ($basePath !== '/' && str_starts_with($normalizedHref, rtrim($basePath, '/').'/')) {
            $normalizedHref = substr($normalizedHref, strlen(rtrim($basePath, '/')));
        } elseif ($basePath !== '/' && $normalizedHref === rtrim($basePath, '/').'/') {
            $normalizedHref = '/';
        }

        return $normalizedHref === '' ? '/' : $normalizedHref;
    }

    /**
     * Normalisierter Pfadanteil der Freigabe-URL, immer mit abschließendem "/".
     */
    public static function basePath(?string $baseUrl): string
    {
        if ($baseUrl === null || $baseUrl === '') {
            return '/';
        }

        $path = parse_url($baseUrl, PHP_URL_PATH);

        if (! is_string($path) || $path === '') {
            return '/';
        }

        return self::normalize($path, true);
    }

    /**
     * Prüft, ob der Pfad unterhalb des Präfixes liegt (nach Normalisierung, ".." ist bereits aufgelöst).
     * Ein Pfad mit unaufgelöstem ".." im Original wird immer abgelehnt.
     */
    public static function isWithin(string $path, string $prefix): bool
    {
        if (str_contains($path, '..')) {
            return false;
        }

        $normalizedPrefix = self::normalize($prefix, true);
        $normalizedPath = self::normalize($path);

        if ($normalizedPrefix === '/') {
            return true;
        }

        return str_starts_with($normalizedPath, $normalizedPrefix) && $normalizedPath !== $normalizedPrefix;
    }

    public static function parent(string $path): ?string
    {
        $normalized = self::normalize($path);

        if ($normalized === '/') {
            return null;
        }

        $trimmed = rtrim($normalized, '/');
        $pos = strrpos($trimmed, '/');

        return $pos === false || $pos === 0 ? '/' : substr($trimmed, 0, $pos + 1);
    }

    public static function basename(string $path): string
    {
        $trimmed = rtrim(self::normalize($path), '/');
        $pos = strrpos($trimmed, '/');

        return $pos === false ? $trimmed : substr($trimmed, $pos + 1);
    }

    public static function depth(string $path): int
    {
        $trimmed = trim(self::normalize($path), '/');

        return $trimmed === '' ? 0 : count(explode('/', $trimmed));
    }

    /**
     * Ordnersegmente eines Pfads (ohne Dateiname bei Dateien).
     *
     * @return array<int, string>
     */
    public static function folderSegments(string $path): array
    {
        $normalized = self::normalize($path);
        $folder = str_ends_with($normalized, '/') ? $normalized : (self::parent($normalized) ?? '/');
        $trimmed = trim($folder, '/');

        return $trimmed === '' ? [] : explode('/', $trimmed);
    }

    /**
     * Kodiert einen normalisierten Pfad segmentweise für die Request-URL (Umlaute, Leerzeichen, Sonderzeichen).
     */
    public static function encode(string $path): string
    {
        $segments = explode('/', $path);

        return implode('/', array_map(static fn (string $s): string => rawurlencode($s), $segments));
    }

    private static function decode(string $path): string
    {
        // Mehrfach kodierte Pfade robust auflösen, maximal zwei Durchgänge.
        $decoded = rawurldecode($path);

        if (str_contains($decoded, '%') && preg_match('/%[0-9A-Fa-f]{2}/', $decoded) === 1) {
            $decoded = rawurldecode($decoded);
        }

        $normalized = Normalizer::normalize($decoded, Normalizer::FORM_C);

        return is_string($normalized) ? $normalized : $decoded;
    }
}
