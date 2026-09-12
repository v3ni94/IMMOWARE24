<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Modules\Imports\Services\CsvReader;
use App\Modules\Imports\Services\HeaderNormalizer;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    private function fixture(string $name): string
    {
        return dirname(__DIR__, 2).'/Fixtures/imports/'.$name;
    }

    public function test_windows_1252_with_semicolon_is_converted_to_utf8(): void
    {
        $reader = new CsvReader;
        $document = $reader->analyze($this->fixture('properties_win1252.csv'));

        $this->assertSame(CsvReader::ENCODING_WINDOWS_1252, $document->encoding);
        $this->assertSame(';', $document->delimiter);
        $this->assertFalse($document->hasBom);
        $this->assertSame(['Objektnummer', 'Bezeichnung', 'Verwaltungsart', 'Straße', 'Hausnummer', 'PLZ', 'Ort'], $document->headers);
        $this->assertSame('strasse', $document->normalizedHeaders[3]);

        $rows = iterator_to_array($reader->rows($document), false);
        $this->assertCount(3, $rows);
        $this->assertSame('WEG Königsallee', $rows[0]['bezeichnung']);
        $this->assertSame('Düsseldorf', $rows[0]['ort']);
        $this->assertSame(2, $rows[0]['_line']);
        $this->assertSame('', $rows[2]['objektnummer']);
    }

    public function test_utf8_bom_with_comma_is_detected_and_stripped(): void
    {
        $reader = new CsvReader;
        $document = $reader->analyze($this->fixture('units_bom.csv'));

        $this->assertTrue($document->hasBom);
        $this->assertSame(CsvReader::ENCODING_UTF8, $document->encoding);
        $this->assertSame(',', $document->delimiter);
        $this->assertSame('Objektnummer', $document->headers[0]);
        $this->assertSame(['objektnummer', 've_nummer', 'einheitentyp', 'etage', 'wohnflaeche'], $document->normalizedHeaders);

        $rows = iterator_to_array($reader->rows($document), false);
        $this->assertSame('65,50', $rows[0]['wohnflaeche']);
    }

    public function test_fingerprint_is_stable_and_independent_of_case_and_encoding(): void
    {
        $a = HeaderNormalizer::fingerprint(HeaderNormalizer::normalizeAll(['Objektnummer', 'Straße ', 'PLZ']));
        $b = HeaderNormalizer::fingerprint(HeaderNormalizer::normalizeAll(['objektnummer', 'strasse', 'plz']));
        $c = HeaderNormalizer::fingerprint(HeaderNormalizer::normalizeAll(['PLZ', 'Objektnummer', 'Straße']));

        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c, 'Reihenfolge ist Teil des Fingerprints.');
        $this->assertSame(64, strlen($a));
    }

    public function test_duplicate_and_empty_headers_are_made_unique(): void
    {
        $this->assertSame(['name', 'name_2', 'spalte_3'], HeaderNormalizer::normalizeAll(['Name', 'name', '']));
    }

    public function test_tab_delimiter_is_detected(): void
    {
        $this->assertSame("\t", (new CsvReader)->detectDelimiter("a\tb\tc"));
        $this->assertSame(';', (new CsvReader)->detectDelimiter('a;"b,c";d'));
    }
}
