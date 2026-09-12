<?php

declare(strict_types=1);

namespace App\Modules\Actions\Exceptions;

use RuntimeException;

/**
 * Verstoß gegen Allowlist oder Freigabe-Policy (Selbstfreigabe, fehlende Identitätsprüfung, fehlende zweite Freigabe).
 * Wird im Service geworfen, damit auch direkte Service- oder API-Aufrufe gesperrt sind.
 */
final class ActionPolicyException extends RuntimeException
{
    public function __construct(string $message, public readonly string $code_key = 'policy_violation')
    {
        parent::__construct($message);
    }
}
