<?php

declare(strict_types=1);

namespace App\Modules\Security\DTO;

use App\Modules\Security\Models\ApiKey;

/**
 * Ergebnis der Key-Erzeugung. plainTextKey ist nur hier sichtbar und wird nirgends gespeichert.
 */
final readonly class CreatedApiKey
{
    public function __construct(
        public ApiKey $apiKey,
        public string $plainTextKey,
    ) {}
}
