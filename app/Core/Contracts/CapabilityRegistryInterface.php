<?php

declare(strict_types=1);

namespace App\Core\Contracts;

interface CapabilityRegistryInterface
{
    public function has(string $capability): bool;

    /**
     * @return array<string, mixed>
     */
    public function all(): array;

    public function refresh(int $connectionId): void;
}
