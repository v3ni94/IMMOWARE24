<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Connector\Models\Organization;
use App\Modules\Estate\Models\OpenItem;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenItemsSnapshotTest extends TestCase
{
    use ImportsTestSupport;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDropFolder();
    }

    protected function tearDown(): void
    {
        $this->tearDownDropFolder();
        parent::tearDown();
    }

    public function test_missing_open_items_are_settled_at_snapshot_date_and_never_deleted(): void
    {
        $organization = $this->createOrganization();
        $this->confirmOpenItemsFormat();
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-01']);

        $this->dropFixture('open_items_snapshot_1.csv', $organization, ExportType::OpenItems, ['as_of_date' => '31.08.2026', 'is_full_export' => true]);
        $first = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $first->status);
        $this->assertSame(3, $first->rows_imported);

        $op3 = OpenItem::query()->withoutGlobalScope('organization')->where('external_id', 'open_item:OP-3')->firstOrFail();
        $this->assertSame(120050, $op3->amount_cents);
        $this->assertSame('2026-08-31', $op3->as_of_date?->toDateString());
        $this->assertNull($op3->settled_at);
        $this->assertNotNull($op3->unit_id);

        // OP eines anderen Objekts derselben Quelle: bleibt vom Vollexport für OBJ-001 unberührt.
        $otherProperty = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-002']);
        $foreign = $this->openItem($organization, $otherProperty, 'open_item:OP-FREMD');

        $this->dropFixture('open_items_snapshot_2.csv', $organization, ExportType::OpenItems, ['as_of_date' => '15.09.2026', 'is_full_export' => true]);
        $second = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $second->status);

        $items = OpenItem::query()->withoutGlobalScope('organization')->withTrashed()->get()->keyBy('external_id');
        $this->assertCount(5, $items, 'OP werden nie gelöscht');
        $this->assertNull($items['open_item:OP-FREMD']->settled_at, 'OP anderer Objekte werden nicht erledigt');
        $this->assertSame(50000, $items['open_item:OP-FREMD']->open_cents);
        $this->assertNotNull($foreign);
        $this->assertNull($items['open_item:OP-1']->settled_at);
        $this->assertSame('2026-09-15', $items['open_item:OP-1']->as_of_date?->toDateString());
        $this->assertSame('2026-09-15', substr((string) $items['open_item:OP-2']->settled_at, 0, 10));
        $this->assertSame('2026-09-15', substr((string) $items['open_item:OP-3']->settled_at, 0, 10));
        $this->assertSame(0, $items['open_item:OP-3']->open_cents);
        $this->assertNull($items['open_item:OP-4']->settled_at);
        $this->assertNull($items['open_item:OP-3']->deleted_at);
    }

    public function test_partial_export_never_settles_open_items(): void
    {
        $organization = $this->createOrganization();
        $this->confirmOpenItemsFormat();
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-01']);

        $this->dropFixture('open_items_snapshot_1.csv', $organization, ExportType::OpenItems, ['as_of_date' => '31.08.2026', 'is_full_export' => true]);
        $this->scanAndProcess();

        // Teilexport (kein is_full_export): OP-2 und OP-3 fehlen, dürfen aber nicht erledigt werden.
        $this->dropFixture('open_items_snapshot_2.csv', $organization, ExportType::OpenItems, ['as_of_date' => '15.09.2026']);
        $file = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $file->status);

        $items = OpenItem::query()->withoutGlobalScope('organization')->get()->keyBy('external_id');
        $this->assertNull($items['open_item:OP-2']->settled_at, 'Teilexport erledigt keine OP');
        $this->assertNull($items['open_item:OP-3']->settled_at);
        $this->assertSame(120050, $items['open_item:OP-3']->open_cents);
    }

    public function test_full_export_settles_only_open_items_of_same_connection(): void
    {
        $organization = $this->createOrganization();
        $this->confirmOpenItemsFormat();
        $property = Property::factory()->for($organization)->create(['immoware_object_number' => 'OBJ-001']);
        Unit::factory()->for($property)->create(['organization_id' => $organization->getKey(), 'unit_number' => 'VE-01']);
        $fileConnection = $this->createConnection($organization, ['connector_type' => 'file_import']);
        $otherConnection = $this->createConnection($organization, ['connector_type' => 'file_import']);
        $sameSource = $this->openItem($organization, $property, 'open_item:OP-GLEICHE-QUELLE', (int) $fileConnection->getKey());
        $otherSource = $this->openItem($organization, $property, 'open_item:OP-ANDERE-QUELLE', (int) $otherConnection->getKey());

        $this->dropFixture('open_items_snapshot_1.csv', $organization, ExportType::OpenItems, ['as_of_date' => '31.08.2026', 'is_full_export' => true, 'connection' => (string) $fileConnection->getKey()]);
        $file = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $file->status);
        $this->assertSame((int) $fileConnection->getKey(), (int) $file->connection_id);

        $this->assertNotNull($sameSource->refresh()->settled_at, 'OP derselben Connection und desselben Objekts wird erledigt');
        $this->assertNull($otherSource->refresh()->settled_at, 'OP einer anderen Connection bleiben offen');
    }

    private function openItem(Organization $organization, Property $property, string $externalId, ?int $connectionId = null): OpenItem
    {
        $item = new OpenItem;
        $item->forceFill([
            'organization_id' => $organization->getKey(),
            'connection_id' => $connectionId,
            'property_id' => $property->getKey(),
            'kind' => 'Miete',
            'amount_cents' => 50000,
            'open_cents' => 50000,
            'currency' => 'EUR',
            'as_of_date' => '2026-08-31',
            'source_system' => 'immoware24',
            'external_id' => $externalId,
            'first_synced_at' => now(),
            'last_synced_at' => now(),
        ]);
        $item->save();

        return $item;
    }
}
