<?php

declare(strict_types=1);

namespace App\Modules\Actions\Enums;

/**
 * Zielsysteme von Aktionen. Gmail ist Mailsystem, Immoware24 Fachsystem, Lexware Office Rechnungsprogramm,
 * Google Drive Dokumentenquelle. "manual" ist die manuelle Aufgabe mit Alt/Neu und Bestätigung.
 */
enum TargetSystem: string
{
    case Immoware24 = 'immoware24';
    case Lexware = 'lexware';
    case Gmail = 'gmail';
    case Drive = 'drive';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Immoware24 => 'Immoware24',
            self::Lexware => 'Lexware Office',
            self::Gmail => 'Gmail',
            self::Drive => 'Google Drive',
            self::Manual => 'Manuell',
        };
    }

    /**
     * Konfigurationsschlüssel des zugehörigen Feature-Flags (config hub.mail.flags), null bei manuell.
     */
    public function writeFlag(): ?string
    {
        return match ($this) {
            self::Immoware24 => 'immoware_write',
            self::Lexware => 'lexware_write',
            self::Gmail => 'gmail_send',
            self::Drive, self::Manual => null,
        };
    }
}
