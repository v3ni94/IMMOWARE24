<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Regel für den erlaubten Schreibpfad einer Connection (allowed_write_prefix): absoluter Ordnerpfad mit führendem
 * und abschließendem Schrägstrich, nie die Wurzel, kein Pfad unter /Dokumente/ (05-write-capabilities.md
 * Abschnitt 1), keine Punktsegmente, keine Steuerzeichen. Verhindert, dass ein Prefix wie "/" jede Zielprüfung
 * aushebelt. Gemeinsam genutzt von Admin-Validierung und API-WriteGuard (Änderungsvermerk 12.09.2026).
 */
final class WritePrefixGuard
{
    /** @var array<int, string> Ordner, in die der Hub nie schreibt (kleingeschrieben verglichen). */
    public const array FORBIDDEN_ROOTS = ['/dokumente/'];

    public static function reason(?string $prefix): ?string
    {
        if ($prefix === null || $prefix === '/' || trim($prefix) === '') {
            return 'Der erlaubte Schreibpfad darf nicht leer oder die Wurzel der Freigabe sein.';
        }

        if (! str_starts_with($prefix, '/') || ! str_ends_with($prefix, '/')) {
            return 'Der erlaubte Schreibpfad muss mit / beginnen und mit / enden, z. B. /Posteingang/.';
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1 || str_contains($prefix, '\\') || str_contains($prefix, '//')) {
            return 'Der erlaubte Schreibpfad enthält unzulässige Zeichen.';
        }

        foreach (explode('/', trim($prefix, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return 'Der erlaubte Schreibpfad darf keine Punktsegmente (. oder ..) enthalten.';
            }
        }

        foreach (self::FORBIDDEN_ROOTS as $root) {
            if (str_starts_with(strtolower($prefix), $root)) {
                return 'Der Ordner Dokumente ist für den Schreibpfad gesperrt; zulässig ist ausschließlich der Posteingang.';
            }
        }

        return null;
    }

    public static function allows(?string $prefix): bool
    {
        return self::reason($prefix) === null;
    }
}
