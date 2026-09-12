<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Estate\Models\Transaction;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Services\CsvReader;
use App\Modules\Imports\Services\Importers\DatevCsvImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatevImportTest extends TestCase
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

    public function test_extf_header_is_parsed(): void
    {
        $header = (new DatevCsvImporter(new CsvReader))->readExtfHeader($this->fixturePath('datev_extf.csv'));

        $this->assertSame('2026-01-01', $header['fiscal_year_start']?->toDateString());
        $this->assertSame('2026-08-01', $header['date_from']?->toDateString());
        $this->assertSame('2026-08-31', $header['date_to']?->toDateString());
    }

    public function test_buchungsstapel_creates_ledger_transactions_and_marks_duplicates(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('datev_extf.csv', $organization, ExportType::DatevBuchungsstapel);

        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Imported->value, $file->status);
        $this->assertSame(3, $file->rows_total);
        $this->assertSame(3, $file->rows_imported, 'Duplikate werden gespeichert, nicht verworfen');
        $this->assertSame(1, $file->rows_duplicate);

        $transactions = Transaction::query()->withoutGlobalScope('organization')->orderBy('id')->get();
        $this->assertCount(3, $transactions);

        $first = $transactions[0];
        $this->assertSame('ledger', $first->kind);
        $this->assertSame(85000, $first->amount_cents);
        $this->assertSame('2026-08-01', $first->booking_date?->toDateString());
        $this->assertSame('1200', $first->debit_account);
        $this->assertSame('8400', $first->credit_account);
        $this->assertSame('RE-2026-001', $first->document_field);
        $this->assertSame('Miete Müller VE-01', $first->text);
        $this->assertSame('OBJ-001', $first->cost_center);
        $this->assertSame(1, $first->occurrence_no);
        $this->assertFalse((bool) $first->is_duplicate);
        $this->assertSame((int) $file->getKey(), (int) $first->import_file_id);

        $second = $transactions[1];
        $this->assertSame(-123456, $second->amount_cents, 'Haben negativ');
        $this->assertSame('2026-08-15', $second->booking_date?->toDateString());

        $duplicate = $transactions[2];
        $this->assertSame($first->row_hash, $duplicate->row_hash);
        $this->assertSame(2, $duplicate->occurrence_no);
        $this->assertTrue((bool) $duplicate->is_duplicate);
        $this->assertNotSame($first->external_id, $duplicate->external_id);
    }

    public function test_reexport_of_same_bookings_is_idempotent(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('datev_extf.csv', $organization, ExportType::DatevBuchungsstapel);
        $this->scanAndProcess();

        // Byteidentische Datei wäre Duplikat auf Dateiebene; abweichender Header-Kommentar simuliert neuen Export.
        $content = (string) file_get_contents($this->fixturePath('datev_extf.csv'));
        $content = str_replace('Export August', 'Export August Korrektur', $content);
        file_put_contents($this->dropPath.'/datev_2.csv', $content);
        file_put_contents($this->dropPath.'/datev_2.csv.json', json_encode([
            'organization' => $organization->legal_entity_code, 'export_type' => 'datev_buchungsstapel',
            'exported_at' => '2026-09-02T08:00:00+02:00', 'exported_by' => 'T. Müller',
        ], JSON_THROW_ON_ERROR));

        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Imported->value, $file->status);
        $this->assertSame(1, $file->rows_duplicate);
        $this->assertSame(3, Transaction::query()->withoutGlobalScope('organization')->count(), 'Gleiche external_id wird aktualisiert, nicht doppelt angelegt');
        $this->assertSame(3, Transaction::query()->withoutGlobalScope('organization')->where('import_file_id', $file->getKey())->count());
    }

    public function test_file_without_extf_marker_fails(): void
    {
        $organization = $this->createOrganization();
        $this->dropFixture('datev_not_extf.csv', $organization, ExportType::DatevBuchungsstapel);

        $file = $this->scanAndProcess();

        $this->assertSame(ImportFileStatus::Failed->value, $file->status);
        $this->assertStringContainsString('EXTF', (string) $file->error_summary);
    }
}
