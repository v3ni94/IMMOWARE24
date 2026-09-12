<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Rules;

use App\Modules\Webhooks\Services\WebhookUrlGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validierungsregel für Webhook-Ziele: https, öffentlich auflösbar, keine privaten oder lokalen Adressen.
 * Wiederverwendbar in API- und Admin-Requests.
 */
final class SafeWebhookUrl implements ValidationRule
{
    public function __construct(private readonly WebhookUrlGuard $guard) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Die Webhook-URL muss eine Zeichenkette sein.');

            return;
        }

        $reason = $this->guard->reason($value);

        if ($reason !== null) {
            $fail(sprintf('Die Webhook-URL ist nicht zulässig (%s): nur https zu öffentlich erreichbaren Zielen, keine privaten, lokalen oder Link-Local-Adressen.', $reason));
        }
    }
}
