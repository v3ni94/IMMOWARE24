<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Aktueller Mandantenkontext. Nur wenn gesetzt, greift der Global Scope aus BelongsToOrganization.
 */
final class OrganizationContext
{
    private ?int $organizationId = null;

    public function set(?int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    public function get(): ?int
    {
        return $this->organizationId;
    }

    public function has(): bool
    {
        return $this->organizationId !== null;
    }

    public function clear(): void
    {
        $this->organizationId = null;
    }

    /**
     * Führt einen Callback ohne Mandantenkontext aus (z. B. Sync-Jobs über alle Mandanten).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withoutContext(callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = null;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function within(int $organizationId, callable $callback): mixed
    {
        $previous = $this->organizationId;
        $this->organizationId = $organizationId;

        try {
            return $callback();
        } finally {
            $this->organizationId = $previous;
        }
    }
}
