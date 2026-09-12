<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum CapabilityStatus: string
{
    case Verified = 'verified';
    case Documented = 'documented';
    case Tested = 'tested';
    case Assumed = 'assumed';
    case Unavailable = 'unavailable';
    case WaitingForVendorAccess = 'waiting_for_vendor_access';

    /**
     * Nur belegte oder getestete Fähigkeiten dürfen aktiviert werden.
     */
    public function allowsActivation(): bool
    {
        return match ($this) {
            self::Verified, self::Documented, self::Tested => true,
            default => false,
        };
    }
}
