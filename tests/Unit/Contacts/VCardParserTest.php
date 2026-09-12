<?php

declare(strict_types=1);

namespace Tests\Unit\Contacts;

use App\Modules\Contacts\VCard\VCardParser;
use PHPUnit\Framework\TestCase;

final class VCardParserTest extends TestCase
{
    private VCardParser $parser;

    protected function setUp(): void
    {
        $this->parser = new VCardParser;
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
    }

    public function test_parses_vcard_30_with_umlauts_escapes_and_multiple_tel_types(): void
    {
        $card = $this->parser->parse($this->fixture('umlaute.vcf'));

        $this->assertNotNull($card);
        $this->assertSame('3.0', $card->version);
        $this->assertSame('c1-mueller', $card->uid);
        $this->assertSame('Jürgen Müller-Lüdenscheidt', $card->formattedName);
        $this->assertSame('Müller-Lüdenscheidt', $card->name['family'] ?? null);
        $this->assertSame('Jürgen', $card->name['given'] ?? null);
        $this->assertSame('Herr', $card->name['prefix'] ?? null);
        $this->assertSame('Hausverwaltung Müller GmbH', $card->organization);
        $this->assertSame('Buchhaltung', $card->organizationUnit);
        $this->assertSame('Geschäftsführer', $card->title);

        $this->assertCount(2, $card->emails);
        $this->assertSame('juergen.mueller@Example.DE', $card->emails[0]['value']);
        $this->assertContains('work', $card->emails[0]['types']);
        $this->assertContains('pref', $card->emails[0]['types']);
        $this->assertNotContains('internet', $card->emails[0]['types']);

        $this->assertCount(3, $card->phones);
        $this->assertSame(['work', 'voice'], $card->phones[0]['types']);
        $this->assertSame(['cell'], $card->phones[1]['types']);
        $this->assertSame(['home'], $card->phones[2]['types']);

        $this->assertCount(1, $card->addresses);
        $this->assertSame('Hauptstraße 12, Hinterhaus', $card->addresses[0]['street']);
        $this->assertSame('40721', $card->addresses[0]['postal_code']);
        $this->assertSame('Hilden', $card->addresses[0]['city']);
        $this->assertSame('Deutschland', $card->addresses[0]['country']);

        $this->assertSame("Zeile eins\nZeile zwei; mit Semikolon, Komma und Backslash \\ am Ende", $card->note);
        $this->assertSame(['Eigentümer', 'Mieter'], $card->categories);
        $this->assertSame(['K-4711'], $card->extra['X-IMMOWARE-KUNDENNUMMER']);
        $this->assertSame('2026-09-01T10:00:00Z', $card->rev);
    }

    public function test_decodes_quoted_printable_with_charset_and_soft_line_breaks(): void
    {
        $card = $this->parser->parse($this->fixture('quoted-printable.vcf'));

        $this->assertNotNull($card);
        $this->assertSame('Schönberger', $card->name['family'] ?? null);
        $this->assertSame('René', $card->name['given'] ?? null);
        $this->assertSame('René Schönberger', $card->formattedName);
        $this->assertSame('Erste Zeile mit überlangem Text der fortgesetzt wird auf der nächsten Zeile', $card->note);
        $this->assertSame(['work', 'voice'], $card->phones[0]['types']);
        $this->assertSame('rene@example.com', $card->emails[0]['value']);
    }

    public function test_unfolds_lines_and_parses_vcard_40_parameters(): void
    {
        $card = $this->parser->parse($this->fixture('folded-v4.vcf'));

        $this->assertNotNull($card);
        $this->assertSame('4.0', $card->version);
        $this->assertSame('c3-folded', $card->uid, 'urn:uuid: Präfix wird entfernt');
        $this->assertSame(['work', 'pref'], $card->emails[0]['types']);
        $this->assertSame('+49-172-555-0001', $card->phones[0]['value'], 'tel: Präfix wird entfernt');
        $this->assertSame(['cell'], $card->phones[0]['types']);
        $this->assertSame('Dies ist eine sehr lange Notiz die vom Server gefaltet wurde weil sie mehr als fünfundsiebzig Zeichen enthält und deshalb auf mehrere physische Zeilen verteilt ist.', $card->note);
        $this->assertSame('Düsseldorf', $card->addresses[0]['city']);
        $this->assertSame(['wert1', 'wert2'], $card->extra['X-HUB-TEST']);
        $this->assertSame(['Beirat'], $card->categories);
    }

    public function test_missing_uid_is_reported(): void
    {
        $card = $this->parser->parse($this->fixture('missing-uid.vcf'));

        $this->assertNotNull($card);
        $this->assertFalse($card->hasUid());
        $this->assertSame('Ohne Uid', $card->formattedName);
    }

    public function test_base64_binary_is_not_treated_as_text_and_multiple_cards_are_split(): void
    {
        $text = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:a\r\nFN:A\r\nPHOTO;ENCODING=b;TYPE=JPEG:/9j/4AAQ\r\nX-BIN;ENCODING=BASE64:SGFsbG8=\r\nEND:VCARD\r\nBEGIN:VCARD\r\nVERSION:3.0\r\nUID:b\r\nFN:B\r\nEND:VCARD\r\n";

        $cards = $this->parser->parseAll($text);

        $this->assertCount(2, $cards);
        $this->assertSame('a', $cards[0]->uid);
        $this->assertSame('b', $cards[1]->uid);
        $this->assertSame(['[binary]'], $cards[0]->extra['X-BIN']);
        $this->assertArrayNotHasKey('PHOTO', $cards[0]->extra);
    }

    public function test_returns_null_without_vcard(): void
    {
        $this->assertNull($this->parser->parse('kein vcard'));
    }
}
