<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Services;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\DTO\CheckResult;
use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Exceptions\ConnectorException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Contacts\Mapping\VCardContactMapper;
use App\Modules\Contacts\VCard\VCardParser;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * CardDAV-Konnektor (nur lesend). pull() spiegelt das Adressbuch einer Connection in contacts,
 * push() ist hart gesperrt (carddav.write). Für authenticate() und testConnection() muss der
 * Konnektor mit forConnection() an eine Connection gebunden sein.
 */
final class CardDavConnector implements ImmowareConnectorInterface
{
    public const string NAME = 'carddav';

    public const string ENTITY_TYPE = 'contact';

    private ?int $connectionId = null;

    public function __construct(
        private readonly DavClientFactory $clients,
        private readonly DavPullRunner $runner,
        private readonly VCardParser $parser,
        private readonly VCardContactMapper $mapper,
        private readonly ContactMirrorService $mirror,
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
            return ConnectionResult::fromChecks(['carddav.propfind' => CheckResult::skipped('Keine Connection gebunden.')]);
        }

        $start = hrtime(true);

        try {
            $connection = $this->clients->connection($this->connectionId);
            $info = $this->clients->carddav($connection)->propfindCollection();
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return ConnectionResult::fromChecks([
                'carddav.propfind' => CheckResult::ok('Adressbuch erreichbar.', $latency),
                'carddav.ctag' => $info->ctag !== null ? CheckResult::ok('CTag vorhanden.') : CheckResult::unknown('Kein CTag gemeldet.'),
                'carddav.sync_token' => $info->supportsSyncCollection() && $info->syncToken !== null ? CheckResult::ok('sync-collection unterstützt.') : CheckResult::skipped('Kein sync-token.'),
            ]);
        } catch (Throwable $e) {
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            return ConnectionResult::fromChecks(['carddav.propfind' => CheckResult::failed($e->getMessage(), $latency)]);
        }
    }

    public function capabilities(): array
    {
        return ['contacts.read'];
    }

    public function pull(SyncRequest $request): SyncResult
    {
        $connection = $this->clients->connection($request->connectionId);

        if ($connection->getAttribute('connector_type') !== 'carddav_contacts') {
            throw new ConnectorException(sprintf('Connection %d ist keine CardDAV-Connection.', $request->connectionId));
        }

        $client = $this->clients->carddav($connection);
        $handler = new ContactMirrorHandler(
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
        throw new WriteBlockedException('CardDAV-Schreibpfad ist hart gesperrt (carddav.write).', 'carddav.write');
    }
}
