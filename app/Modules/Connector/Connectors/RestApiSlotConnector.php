<?php

declare(strict_types=1);

namespace App\Modules\Connector\Connectors;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Enums\CapabilityStatus;
use App\Core\Exceptions\CapabilityMissingException;
use App\Modules\Connector\Support\ConnectorContext;

/**
 * Platzhalter für eine spätere Immoware24-API. Es gibt keine belegte REST-API, keine Endpunkte und
 * keine Zugangsdaten. Jede Operation wirft CapabilityMissingException mit Status
 * WAITING_FOR_VENDOR_ACCESS. Der Slot darf nie Netzwerkzugriffe ausführen.
 */
final class RestApiSlotConnector implements ImmowareConnectorInterface
{
    public const string NAME = 'rest_api_slot';

    public const string STATUS = 'WAITING_FOR_VENDOR_ACCESS';

    /** @var array<int, string> */
    public const array CAPABILITY_KEYS = ['cases.read', 'cases.write'];

    public function __construct(private readonly ?ConnectorContext $context = null) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function status(): CapabilityStatus
    {
        return CapabilityStatus::WaitingForVendorAccess;
    }

    public function authenticate(): bool
    {
        throw $this->missing('rest_api.authenticate');
    }

    public function testConnection(): ConnectionResult
    {
        throw $this->missing('rest_api.test_connection');
    }

    /**
     * Der Slot bietet keine Fähigkeit an: leere Liste.
     *
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        return [];
    }

    /**
     * Alle fachlichen Capability-Keys des Slots, jeweils false.
     *
     * @return array<string, bool>
     */
    public function capabilityFlags(): array
    {
        return array_fill_keys(self::CAPABILITY_KEYS, false);
    }

    public function pull(SyncRequest $request): SyncResult
    {
        throw $this->missing('rest_api.pull');
    }

    public function push(SyncRequest $request): SyncResult
    {
        throw $this->missing('rest_api.push');
    }

    private function missing(string $capability): CapabilityMissingException
    {
        return new CapabilityMissingException($capability, sprintf(
            'Keine Immoware24-API verfügbar (Status %s). Connection %s. Belegte Zugangswege sind ausschließlich WebDAV, CardDAV, CalDAV und Dateiexporte.',
            self::STATUS,
            $this->context !== null ? (string) $this->context->connectionId : 'unbekannt',
        ));
    }
}
