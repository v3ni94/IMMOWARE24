<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

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

        $this->dropFixture('open_items_snapshot_1.csv', $organization, ExportType::OpenItems, ['as_of_date' => '31.08.2026']);
        $first = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $first->status);
        $this->assertSame(3, $first->rows_imported);

        $op3 = OpenItem::query()->withoutGlobalScope('organization')->where('external_id', 'open_item:OP-3')->firstOrFail();
        $this->assertSame(120050, $op3->amount_cents);
        $this->assertSame('2026-08-31', $op3->as_of_date?->toDateString());
        $this->assertNull($op3->settled_at);
        $this->assertNotNull($op3->unit_id);

        $this->dropFixture('open_items_snapshot_2.csv', $organization, ExportType::OpenItems, ['as_of_date' => '15.09.2026']);
        $second = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $second->status);

        $items = OpenItem::query()->withoutGlobalScope('organization')->withTrashed()->get()->keyBy('external_id');
        $this->assertCount(4, $items, 'OP werden nie gelöscht');
        $this->assertNull($items['open_item:OP-1']->settled_at);
        $this->assertSame('2026-09-15', $items['open_item:OP-1']->as_of_date?->toDateString());
        $this->assertSame('2026-09-15', substr((string) $items['open_item:OP-2']->settled_at, 0, 10));
        $this->assertSame('2026-09-15', substr((string) $items['open_item:OP-3']->settled_at, 0, 10));
        $this->assertSame(0, $items['open_item:OP-3']->open_cents);
        $this->assertNull($items['open_item:OP-4']->settled_at);
        $this->assertNull($items['open_item:OP-3']->deleted_at);
    }
}
