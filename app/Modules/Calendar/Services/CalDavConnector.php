<?php

declare(strict_types=1);

namespace App\Modules\Calendar\Services;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\DTO\CheckResult;
use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Exceptions\ConnectorException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Calendar\ICal\ICalendarParser;
use App\Modules\Calendar\Mapping\ICalEventMapper;
use App\Modules\Contacts\Services\CollectionStateStore;
use App\Modules\Contacts\Services\DavClientFactory;
use App\Modules\Contacts\Services\DavPullRunner;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CalDAV-Konnektor (nur lesend, niedrige Priorität). Spiegelt VEVENTs in calendar_events; push() ist hart gesperrt.
 */
final class CalDavConnector implements ImmowareConnectorInterface
{
    public const string NAME = 'caldav';

    public const string ENTITY_TYPE = 'calendar_event';

    private ?int $connectionId = null;

    public function __construct(
        private readonly DavClientFactory $clients,
        private readonly DavPullRunner $runner,
        private readonly ICalendarParser $parser,
        private readonly ICalEventMapper $mapper,
        private readonly CalendarMirrorService $mirror,
        private readonly CollectionStateStore $states,
    ) {}

    public function forConnection(int $connectionId): self
    {
        $clone = clone $this;
        $clone->connectionId = $connectionId;

        return $clone;
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function authenticate(): bool
    {
        return $this->testConnection()->ok;
    }

    public function testConnection(): ConnectionResult
    {
        if ($this->connectionId === null) {
            return ConnectionResult::fromChecks(['caldav.propfind' => CheckResult::skipped('Keine Connection gebunden.')]);
        }

        $start = hrtime(true);

        try {
            $connection = $this->clients->connection($this->connectionId);
            $info = $this->clients->caldav($connection)->propfindCollection();
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return ConnectionResult::fromChecks([
                'caldav.propfind' => CheckResult::ok('Kalender erreichbar.', $latency),
                'caldav.ctag' => $info->ctag !== null ? CheckResult::ok('CTag vorhanden.') : CheckResult::unknown('Kein CTag gemeldet.'),
                'caldav.sync_token' => $info->supportsSyncCollection() && $info->syncToken !== null ? CheckResult::ok('sync-collection unterstützt.') : CheckResult::skipped('Kein sync-token.'),
            ]);
        } catch (Throwable $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return ConnectionResult::fromChecks(['caldav.propfind' => CheckResult::failed($e->getMessage(), $latency)]);
        }
    }

    public function capabilities(): array
    {
        return ['calendar.read'];
    }

    public function pull(SyncRequest $request): SyncResult
    {
        $connection = $this->clients->connection($request->connectionId);

        if ($connection->getAttribute('connector_type') !== 'caldav_calendar') {
            throw new ConnectorException(sprintf('Connection %d ist keine CalDAV-Connection.', $request->connectionId));
        }

        $client = $this->clients->caldav($connection);
        $handler = new CalendarMirrorHandler(
            organizationId: (int) $connection->getAttribute('organization_id'),
            connectionId: (int) $connection->getKey(),
            collectionPath: $client->collectionPath(),
            parser: $this->parser,
            mapper: $this->mapper,
            mirror: $this->mirror,
            states: $this->states,
        );

        Log::withContext(['connection_id' => $request->connectionId, 'connector' => self::NAME]);

        return $this->runner->run($connection, $client, $handler, $request);
    }

    public function push(SyncRequest $request): SyncResult
    {
        throw new WriteBlockedException('CalDAV-Schreibpfad ist hart gesperrt (caldav.write).', 'caldav.write');
    }
}
