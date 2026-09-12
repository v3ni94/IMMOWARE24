<?php

declare(strict_types=1);

namespace App\Modules\Security\DTO;

final readonly class ChainVerificationResult
{
    public function __construct(
        public bool $valid,
        public int $checked,
        public ?int $firstBrokenId = null,
        public ?string $lastHash = null,
        public ?string $message = null,
    ) {}
}
