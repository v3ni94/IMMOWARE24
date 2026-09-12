<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Support;

/**
 * Kategorien (mail_cases.case_type) der Oberfläche. Konfigurierbar über hub.mailui.case_types.
 */
final class CaseTypes
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $types = config('hub.mailui.case_types', []);

        return is_array($types) && $types !== [] ? array_map('strval', $types) : ['sonstiges' => 'Sonstiges'];
    }

    public static function label(?string $type): string
    {
        return self::all()[(string) $type] ?? ((string) $type !== '' ? (string) $type : 'Sonstiges');
    }

    public static function isValid(string $type): bool
    {
        return array_key_exists($type, self::all());
    }
}
