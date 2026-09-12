<?php

declare(strict_types=1);

namespace App\Modules\Admin\Rules;

use App\Core\Support\WritePrefixGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validierungsregel für allowed_write_prefix, Logik in App\Core\Support\WritePrefixGuard.
 */
final class AllowedWritePrefix implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value)) {
            $fail('Der erlaubte Schreibpfad muss eine Zeichenkette sein.');

            return;
        }

        $reason = WritePrefixGuard::reason($value);

        if ($reason !== null) {
            $fail($reason);
        }
    }
}
