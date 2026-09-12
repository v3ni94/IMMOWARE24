<?php

declare(strict_types=1);

namespace Tests\Unit\Connector;

use App\Modules\Connector\Support\ConnectorProvenance;
use PHPUnit\Framework\TestCase;

final class ConnectorProvenanceTest extends TestCase
{
    public function test_connector_type_resolves_to_adapter_name(): void
    {
        $this->assertSame('webdav', ConnectorProvenance::connectorName('webdav_documents'));
        $this->assertSame('webdav', ConnectorProvenance::connectorName('webdav_inbox'));
        $this->assertSame('carddav', ConnectorProvenance::connectorName('carddav_contacts'));
        $this->assertSame('caldav', ConnectorProvenance::connectorName('caldav_calendar'));
        $this->assertSame('file_import', ConnectorProvenance::connectorName('csv_export'));
        $this->assertSame('file_import', ConnectorProvenance::connectorName('datev_export'));
        $this->assertSame('rest_api_slot', ConnectorProvenance::connectorName('rest_api_slot'));
        $this->assertNull(ConnectorProvenance::connectorName('unbekannt'));
    }

    public function test_entity_type_resolves_to_adapter_name(): void
    {
        $this->assertSame('webdav', ConnectorProvenance::forEntityType('document'));
        $this->assertSame('carddav', ConnectorProvenance::forEntityType('contact'));
        $this->assertSame('caldav', ConnectorProvenance::forEntityType('calendar_event'));
        $this->assertSame('file_import', ConnectorProvenance::forEntityType('property'));
        $this->assertNull(ConnectorProvenance::forEntityType('note'));
        $this->assertSame(['webdav', 'carddav', 'caldav', 'file_import', 'rest_api_slot'], ConnectorProvenance::adapterNames());
    }
}
