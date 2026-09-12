<?php

declare(strict_types=1);

namespace App\Modules\Actions\Enums;

/**
 * Verifikationsstatus einer Ausführung. "unverified" ist der Zustand nach einem HTTP-Erfolg ohne Nachlesen.
 */
enum VerificationStatus: string
{
    case ApiVerified = 'api_verified';
    case ManuallyConfirmed = 'manually_confirmed';
    case Unverified = 'unverified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::ApiVerified => 'Per API verifiziert',
            self::ManuallyConfirmed => 'Manuell bestätigt',
            self::Unverified => 'Unverifiziert',
            self::Failed => 'Verifikation fehlgeschlagen',
        };
    }

    public function countsAsVerified(): bool
    {
        return in_array($this, [self::ApiVerified, self::ManuallyConfirmed], true);
    }
}
