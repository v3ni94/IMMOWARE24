<?php

declare(strict_types=1);

namespace App\Modules\Actions\Enums;

/**
 * Risikoklasse eines Aktionsplans. Steuert Freigabepflicht und benötigtes Recht (mail.approve.standard, mail.approve.bank).
 */
enum RiskClass: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Gering',
            self::Medium => 'Mittel',
            self::High => 'Hoch',
            self::Bank => 'Bankdaten',
        };
    }

    public function requiresApproval(): bool
    {
        return $this !== self::Low;
    }

    public function approvalPermission(): string
    {
        return $this === self::Bank ? 'mail.approve.bank' : 'mail.approve.standard';
    }
}
