<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Estate\Models\Transaction;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class Camt053ImportTest extends TestCase
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

    public function test_camt_v02_and_v08_create_bank_transactions_with_masked_iban(): void
    {
        $organization = $this->createOrganization();

        $this->dropFixture('camt053_v02.xml', $organization, ExportType::Camt053);
        $v02 = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $v02->status);
        $this->assertSame('camt053_xml', $v02->file_type);
        $this->assertSame(2, $v02->rows_imported);

        $this->dropFixture('camt053_v08.xml', $organization, ExportType::Camt053);
        $v08 = $this->scanAndProcess();
        $this->assertSame(ImportFileStatus::Imported->value, $v08->status);
        $this->assertSame(1, $v08->rows_imported);

        $transactions = Transaction::query()->withoutGlobalScope('organization')->orderBy('id')->get();
        $this->assertCount(3, $transactions);
        $this->assertSame('bank', $transactions[0]->kind);
        $this->assertSame(85000, $transactions[0]->amount_cents);
        $this->assertSame('E2E-001', $transactions[0]->end_to_end_id);
        $this->assertSame('REF-001', $transactions[0]->acct_svcr_ref);
        $this->assertSame('Max Mustermann: Miete August VE-01', $transactions[0]->text);
        $this->assertSame('DE****************2051', $transactions[0]->document_field);
        $this->assertSame('DE****************3000', $transactions[0]->external_parent_id);
        $this->assertSame(-12050, $transactions[1]->amount_cents);
        $this->assertSame(32000, $transactions[2]->amount_cents);
        $this->assertSame('2026-08-11', $transactions[2]->value_date?->toDateString());

        foreach ($transactions as $transaction) {
            $this->assertStringNotContainsString('370400440532013000', json_encode($transaction->getAttributes(), JSON_THROW_ON_ERROR));
        }
    }
}
