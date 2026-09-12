<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Concerns;

use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Enums\SyncEntity;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Hilfsmethoden für Admin-Seiten, deren Daten über connection_id an eine Connection hängen.
 * Der Mandantenkontext wird über den Global Scope von ImmowareConnection erzwungen; Tabellen ohne
 * organization_id (sync_runs, conflicts, dlq_items) werden über whereIn(connection_id) begrenzt.
 */
trait ResolvesOrganizationConnections
{
    /**
     * Unterabfrage der Connection-IDs des Mandanten (Global Scope angewendet), für whereIn(connection_id).
     */
    protected function connectionIdsQuery(): QueryBuilder
    {
        return ImmowareConnection::query()->toBase()->select('immoware_connections.id');
    }

    /**
     * Connection des eigenen Mandanten oder 404.
     */
    protected function findConnection(int $id): ImmowareConnection
    {
        /** @var ImmowareConnection $connection */
        $connection = ImmowareConnection::query()->findOrFail($id);

        return $connection;
    }

    /**
     * Auswahlliste id => Name aller Connections des Mandanten (für Filter). Begrenzt auf 200 Einträge.
     *
     * @return array<int, string>
     */
    protected function connectionOptions(): array
    {
        $options = [];

        foreach (ImmowareConnection::query()->oldest('name')->lazy(200)->take(200) as $connection) {
            $options[(int) $connection->getKey()] = (string) $connection->getAttribute('name');
        }

        return $options;
    }

    /**
     * Sync-Entitäten, die der Adapter der Connection bedient (Dateiimport und REST-Slot: keine).
     *
     * @return array<int, SyncEntity>
     */
    protected function entitiesFor(ImmowareConnection $connection): array
    {
        try {
            $adapter = ConnectorType::fromConnectorType((string) $connection->getAttribute('connector_type'));
        } catch (InvalidArgumentException) {
            return [];
        }

        return array_values(array_filter(
            SyncEntity::cases(),
            static fn (SyncEntity $entity): bool => $entity->connectorName() === $adapter->value,
        ));
    }

    /**
     * @param  Collection<int, ImmowareConnection>|array<int, ImmowareConnection>  $connections
     * @return array<int, string>
     */
    protected function namesById(iterable $connections): array
    {
        $names = [];

        foreach ($connections as $connection) {
            $names[(int) $connection->getKey()] = (string) $connection->getAttribute('name');
        }

        return $names;
    }
}
