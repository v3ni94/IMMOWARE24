<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Console;

use App\Modules\Api\Support\Provenance;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Webhooks\Models\WebhookOutbox;
use App\Modules\Webhooks\Services\WebhookDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * hub:webhooks:emit-stale löst je Collection-Zustand mit gesetztem stale_since genau einmal das Ereignis sync.stale
 * aus (Deduplikation über die Outbox: kein zweites Ereignis für dieselbe stale_since-Phase). stale_since selbst
 * setzt hub:sync:stale (Sync-Modul). Payload: connection_id, entity_type, stale_since, keine Feldwerte.
 */
final class EmitStaleSyncEventsCommand extends Command
{
    public const string EVENT = 'sync.stale';

    protected $signature = 'hub:webhooks:emit-stale {--limit=500}';

    protected $description = 'Löst sync.stale-Webhooks für Sync-Zustände mit überschrittenem Datenalter aus.';

    public function handle(WebhookDispatcher $dispatcher): int
    {
        if (! $dispatcher->enabled()) {
            $this->info('Webhooks deaktiviert, nichts zu tun.');

            return self::SUCCESS;
        }

        $emitted = 0;
        $limit = max(1, (int) $this->option('limit'));

        SyncState::query()->collections()->whereNotNull('stale_since')->orderBy('id')->limit($limit)->lazyById(100)
            ->each(function (SyncState $state) use ($dispatcher, &$emitted): void {
                $staleSince = $state->getAttribute('stale_since');

                if (! $staleSince instanceof \DateTimeInterface) {
                    return;
                }

                $staleSince = CarbonImmutable::instance($staleSince);

                $alreadyEmitted = WebhookOutbox::query()
                    ->where('event_type', self::EVENT)
                    ->where('entity_type', 'sync_state')
                    ->where('entity_id', (int) $state->getKey())
                    ->where('occurred_at', '>=', $staleSince)
                    ->exists();

                if ($alreadyEmitted) {
                    return;
                }

                $connection = ImmowareConnection::query()->withoutGlobalScopes()->find((int) $state->getAttribute('connection_id'), ['id', 'organization_id', 'connector_type']);

                if ($connection === null) {
                    return;
                }

                $dispatcher->dispatchWithSource(
                    self::EVENT,
                    [
                        'id' => (int) $state->getKey(),
                        'type' => 'sync_state',
                        'connection_id' => (int) $connection->getKey(),
                        'entity_type' => $state->getAttribute('entity_type'),
                        'stale_since' => $staleSince->utc()->toIso8601ZuluString('millisecond'),
                        'last_success_at' => $state->getAttribute('last_success_at')?->utc()->toIso8601ZuluString('millisecond'),
                        'href' => '/api/'.config('hub.api.version', 'v1').'/sync/status',
                    ],
                    (int) $connection->getAttribute('organization_id'),
                    ['connector' => Provenance::adapterName((string) $connection->getAttribute('connector_type')), 'connection_id' => (int) $connection->getKey()],
                    'sync_state',
                    (int) $state->getKey(),
                );
                $emitted++;
            });

        $this->info(sprintf('%d sync.stale-Ereignisse ausgelöst.', $emitted));

        return self::SUCCESS;
    }
}
