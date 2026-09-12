<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;

/**
 * Ein Konnektor kapselt genau einen belegten Zugangsweg zu Immoware24
 * (WebDAV, CardDAV, CalDAV oder Dateiimport). Es gibt keine REST-API.
 */
interface ImmowareConnectorInterface
{
    public function name(): string;

    public function authenticate(): bool;

    public function testConnection(): ConnectionResult;

    /**
     * Liste der Capability-Keys, die dieser Konnektor grundsätzlich anbietet (z. B. webdav.list).
     *
     * @return array<int, string>
     */
    public function capabilities(): array;

    public function pull(SyncRequest $request): SyncResult;

    /**
     * Schreibpfad. Darf nur create-only Operationen ausführen und muss bei fehlender
     * Capability oder gesperrtem Schreibpfad eine WriteBlockedException werfen.
     */
    public function push(SyncRequest $request): SyncResult;
}
