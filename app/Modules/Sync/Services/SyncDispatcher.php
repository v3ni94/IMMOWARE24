<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Enums\SyncMode;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\FetchImmowareCalendarJob;
use App\Modules\Sync\Jobs\FetchImmowareContactsJob;
use App\Modules\Sync\Jobs\FetchImmowareDocumentsJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use Illuminate\Contracts\Bus\Dispatcher;
use InvalidArgumentException;

/**
 * Plant Sync-Jobs je Connection ein. Der Scheduler ruft dispatchForAll() über hub:sync:dispatch auf.
 */
final class SyncDispatcher
{
    public function __construct(
        private readonly Dispatcher $bus,
    ) {}

    public function job(int $connectionId, SyncEntity|string $entity, SyncMode $mode, string $triggerSource = 'schedule', ?int $startedBy = null): RunSyncJob
    {
        $entity = $entity instanceof SyncEntity ? $entity : SyncEntity::tryFrom($entity);

        if ($entity === null) {
            throw new InvalidArgumentException(sprintf('Unbekannte Entität. Erlaubt: %s.', implode(', ', SyncEntity::values())));
        }

        return match ($entity) {
            SyncEntity::Document => new FetchImmowareDocumentsJob($connectionId, $mode, triggerSource: $triggerSource, startedBy: $startedBy),
            SyncEntity::Contact => new FetchImmowareContactsJob($connectionId, $mode, triggerSource: $triggerSource, startedBy: $startedBy),
            SyncEntity::CalendarEvent => new FetchImmowareCalendarJob($connectionId, $mode, triggerSource: $triggerSource, startedBy: $startedBy),
        };
    }

    public function dispatch(int $connectionId, SyncEntity|string $entity, SyncMode $mode, string $triggerSource = 'manual', ?int $startedBy = null): RunSyncJob
    {
        $job = $this->job($connectionId, $entity, $mode, $triggerSource, $startedBy);
        $this->bus->dispatch($job);

        return $job;
    }

    /**
     * Plant den Lauf für alle aktiven bzw. degradierten Connections des passenden Adapters ein.
     *
     * @return int Anzahl eingeplanter Jobs
     */
    public function dispatchForAll(SyncEntity $entity, SyncMode $mode, string $triggerSource = 'schedule'): int
    {
        $count = 0;

        $connections = ImmowareConnection::query()
            ->withoutGlobalScopes()
            ->whereIn('status', RunSyncJob::RUNNABLE_CONNECTION_STATUSES)
            ->where('purpose', 'read')
            ->lazyById(100);

        foreach ($connections as $connection) {
            if (! $connection instanceof ImmowareConnection) {
                continue;
            }

            try {
                $adapter = ConnectorType::fromConnectorType((string) $connection->getAttribute('connector_type'));
            } catch (InvalidArgumentException) {
                continue;
            }

            if ($adapter->value !== $entity->connectorName()) {
                continue;
            }

            $this->dispatch((int) $connection->getKey(), $entity, $mode, $triggerSource);
            $count++;
        }

        return $count;
    }
}
