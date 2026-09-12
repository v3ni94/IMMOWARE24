<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Services\ImportFormatService;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use App\Modules\Webhooks\Models\WebhookOutbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Importe lösen die Ereignisse des Webhook-Katalogs aus (property.updated, unit.updated, open_item.created,
 * open_item.paid). Payload ohne personenbezogene Feldwerte, Quelle connector = file_import.
 */
final class ImportWebhookEventsTest extends TestCase
{
    use ImportsTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDropFolder();
        config()->set('hub.webhooks.enabled', true);
        Bus::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownDropFolder();
        parent::tearDown();
    }

    public function test_property_and_unit_imports_emit_updated_events_only_on_change(): void
    {
        $organization = $this->createOrganization();
        WebhookEndpoint::factory()->for($organization)->events(['property.updated', 'unit.updated'])->create();
        $this->confirmPropertiesFormat();
        $this->confirmUnitsFormat();

        $this->dropFixture('properties_win1252.csv', $organization, ExportType::Properties);
        $file = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $file->status);

        $propertyEvents = WebhookOutbox::query()->where('event_type', 'property.updated')->get();
        $this->assertCount((int) $file->rows_imported, $propertyEvents);
        $payload = $propertyEvents->first()?->getAttribute('payload_json');
        $this->assertSame('file_import', $payload['source']['connector']);
        $this->assertSame('properties', $payload['source']['export_type']);
        $this->assertSame(['id', 'type', 'href'], array_keys($payload['data']), 'Nur IDs, Typ und Link, keine Feldwerte');
        $this->assertStringStartsWith('/api/v1/properties/', $payload['data']['href']);

        // Unveränderter Re-Import: kein weiteres Ereignis.
        $this->dropFixtureVariant('properties_win1252.csv', $organization, ExportType::Properties, [], 'properties_again.csv');
        $this->scanAndProcess();
        $this->assertSame($propertyEvents->count(), WebhookOutbox::query()->where('event_type', 'property.updated')->count());

        Property::query()->withoutGlobalScope('organization')->where('organization_id', $organization->getKey())->update(['immoware_object_number' => 'OBJ-001']);
        $this->dropFixture('units_bom.csv', $organization, ExportType::Units);
        $unitFile = $this->scanAndProcess();
        $this->assertSame(2, WebhookOutbox::query()->where('event_type', 'unit.updated')->count());
        $this->assertSame(2, Unit::query()->withoutGlobalScope('organization')->count());
        $this->assertSame(2, $unitFile->rows_imported);
    }

    public function test_contract_import_emits_created_and_terminated_events_without_personal_data(): void
    {
        $organization = $this->createOrganization();
        WebhookEndpoint::factory()->for($organization)->events(['contract.created', 'contract.terminated', 'contact.created', 'contact.updated'])->create();
        $this->app->make(ImportFormatService::class)->registerConfirmed(
            ExportType::TenantsContracts,
            ['Objektnummer', 'VE-Nummer', 'Mieternummer', 'Nachname', 'Mietbeginn', 'Mietende', 'Kaltmiete'],
            ['object_number' => 'objektnummer', 'unit_number' => 've_nummer', 'tenant_number' => 'mieternummer', 'tenant_last_name' => 'nachname', 'start_date' => 'mietbeginn', 'end_date' => 'mietende', 'net_rent' => 'kaltmiete'],
            ['object_number', 'unit_number', 'tenant_number'],
        );
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-01']);
        Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-02']);

        $this->dropFixture('tenants_contracts_1.csv', $organization, ExportType::TenantsContracts);
        $file = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $file->status);
        $this->assertSame(2, $file->rows_imported);

        $this->assertSame(2, WebhookOutbox::query()->where('event_type', 'contract.created')->count());
        $this->assertSame(0, WebhookOutbox::query()->where('event_type', 'contract.terminated')->count());
        $this->assertSame(2, WebhookOutbox::query()->where('event_type', 'contact.created')->count(), 'Mieterkontakte tenant:*');
        $encoded = json_encode(WebhookOutbox::query()->pluck('payload_json'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Mustermann', $encoded, 'Keine personenbezogenen Feldwerte in der Payload');
        $this->assertStringNotContainsString('850', $encoded, 'Keine Beträge in der Payload');

        $this->dropFixture('tenants_contracts_2.csv', $organization, ExportType::TenantsContracts);
        $this->scanAndProcess();
        $this->assertSame(2, WebhookOutbox::query()->where('event_type', 'contract.created')->count(), 'Kein zweites created');
        $terminated = WebhookOutbox::query()->where('event_type', 'contract.terminated')->get();
        $this->assertCount(1, $terminated, 'Nur der Vertrag mit neu gesetztem Mietende');
        $this->assertStringStartsWith('/api/v1/contracts/', $terminated->first()?->getAttribute('payload_json')['data']['href']);
    }

    public function test_open_item_import_emits_created_and_paid_events(): void
    {
        $organization = $this->createOrganization();
        WebhookEndpoint::factory()->for($organization)->events(['open_item.created', 'open_item.paid'])->create();
        $this->confirmOpenItemsFormat();
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-01']);

        $this->dropFixture('open_items_snapshot_1.csv', $organization, ExportType::OpenItems, ['as_of_date' => '31.08.2026', 'is_full_export' => true]);
        $this->scanAndProcess();
        $this->assertSame(3, WebhookOutbox::query()->where('event_type', 'open_item.created')->count());
        $this->assertSame(0, WebhookOutbox::query()->where('event_type', 'open_item.paid')->count());

        $this->dropFixture('open_items_snapshot_2.csv', $organization, ExportType::OpenItems, ['as_of_date' => '15.09.2026', 'is_full_export' => true]);
        $this->scanAndProcess();
        $this->assertSame(4, WebhookOutbox::query()->where('event_type', 'open_item.created')->count(), 'OP-4 neu');
        $paid = WebhookOutbox::query()->where('event_type', 'open_item.paid')->get();
        $this->assertCount(2, $paid, 'OP-2 und OP-3 zum Stichtag erledigt');
        $this->assertSame('2026-09-15', $paid->first()?->getAttribute('payload_json')['data']['as_of_date']);
        $this->assertSame('snapshot', $paid->first()?->getAttribute('payload_json')['data']['settled_by']);
    }
}
