<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Exceptions\NotImplementedException;
use App\Modules\Imports\Services\Camt053Parser;
use App\Modules\Imports\Services\Mt940Parser;
use PHPUnit\Framework\TestCase;

final class Camt053ParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/imports/'.$name);
    }

    public function test_parses_v02_entries_with_masked_iban(): void
    {
        $parser = new Camt053Parser;
        $xml = $this->fixture('camt053_v02.xml');

        $this->assertSame('v02', $parser->detectVersion($xml));
        $entries = $parser->parse($xml);

        $this->assertCount(2, $entries);
        $this->assertSame(85000, $entries[0]->amountCents);
        $this->assertSame('EUR', $entries[0]->currency);
        $this->assertSame('2026-08-03', $entries[0]->bookingDate?->toDateString());
        $this->assertSame('Miete August VE-01', $entries[0]->remittanceInfo);
        $this->assertSame('Max Mustermann', $entries[0]->counterpartyName);
        $this->assertSame('E2E-001', $entries[0]->endToEndId);
        $this->assertSame('REF-001', $entries[0]->acctSvcrRef);
        $this->assertSame('DE****************3000', $entries[0]->accountIbanMasked);
        $this->assertSame('DE****************2051', $entries[0]->counterpartyIbanMasked);
        $this->assertStringNotContainsString('370400440532013000', $entries[0]->accountIbanMasked);

        $this->assertSame(-12050, $entries[1]->amountCents);
        $this->assertSame('Stadtwerke', $entries[1]->counterpartyName);
    }

    public function test_parses_v08_entries_with_party_wrapper_and_multiple_ustrd(): void
    {
        $parser = new Camt053Parser;
        $xml = $this->fixture('camt053_v08.xml');

        $this->assertSame('v08', $parser->detectVersion($xml));
        $entries = $parser->parse($xml);

        $this->assertCount(1, $entries);
        $this->assertSame(32000, $entries[0]->amountCents);
        $this->assertSame('Erika Musterfrau', $entries[0]->counterpartyName);
        $this->assertSame('Hausgeld September', $entries[0]->remittanceInfo);
        $this->assertSame('2026-08-11', $entries[0]->valueDate?->toDateString());
        $this->assertSame('BOOK', $entries[0]->status);
    }

    public function test_rejects_unknown_namespace_and_doctype(): void
    {
        $parser = new Camt053Parser;

        try {
            $parser->parse('<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.052.001.02"/>');
            $this->fail('Exception erwartet.');
        } catch (ImportException $e) {
            $this->assertStringContainsString('Namespace', $e->getMessage());
        }

        $this->expectException(ImportException::class);
        $parser->parse('<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02"/>');
    }

    public function test_mt940_is_not_implemented(): void
    {
        $this->expectException(NotImplementedException::class);
        $this->expectExceptionMessage('MT940');
        (new Mt940Parser)->parse(':20:STARTUMS');
    }
}
