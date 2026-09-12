<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

interface AuditLoggerInterface
{
    /**
     * Schreibt einen append-only Auditeintrag. Secrets sind vor dem Aufruf zu maskieren.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function log(
        string $action,
        ?Model $entity,
        array $before,
        array $after,
        string $source,
        ?string $correlationId = null,
    ): void;
}
