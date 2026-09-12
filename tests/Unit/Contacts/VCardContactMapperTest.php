<?php

declare(strict_types=1);

namespace Tests\Unit\Contacts;

use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Contacts\Mapping\VCardContactMapper;
use App\Modules\Contacts\VCard\VCardParser;
use PHPUnit\Framework\TestCase;

final class VCardContactMapperTest extends TestCase
{
    public function test_maps_vcard_to_contact_attributes(): void
    {
        $card = (new VCardParser)->parse((string) file_get_contents(__DIR__.'/Fixtures/umlaute.vcf'));
        $mapper = new VCardContactMapper;

        $local = $mapper->toLocal(['vcard' => $card, 'href' => '/dav/ab/c1.vcf', 'etag' => '"1"']);

        $this->assertSame('contact', $mapper->entityType());
        $this->assertSame(1, $mapper->version());
        $this->assertSame('c1-mueller', $local['external_id']);
        $this->assertFalse($local['uid_missing']);
        $this->assertSame('Jürgen', $local['first_name']);
        $this->assertSame('Müller-Lüdenscheidt', $local['last_name']);
        $this->assertSame('Hausverwaltung Müller GmbH', $local['company']);
        $this->assertSame('juergen.mueller@Example.DE', $local['email']);
        $this->assertSame('+4902103123456', $local['phone']);
        $this->assertSame('01719876543', $local['mobile']);
        $this->assertSame('Hauptstraße 12, Hinterhaus', $local['street']);
        $this->assertSame('40721', $local['postal_code']);
        $this->assertSame('Hilden', $local['city']);
        $this->assertSame('Deutschland', $local['country']);
        $this->assertSame(['Eigentümer', 'Mieter'], $local['categories']);
        $this->assertSame('cell', $local['phones'][1]['type']);
    }

    public function test_falls_back_to_href_when_uid_missing(): void
    {
        $card = (new VCardParser)->parse((string) file_get_contents(__DIR__.'/Fixtures/missing-uid.vcf'));

        $local = (new VCardContactMapper)->toLocal(['vcard' => $card, 'href' => '/dav/ab/x.vcf']);

        $this->assertSame('href:/dav/ab/x.vcf', $local['external_id']);
        $this->assertTrue($local['uid_missing']);
    }

    public function test_company_card_becomes_kind_company(): void
    {
        $card = (new VCardParser)->parse((string) file_get_contents(__DIR__.'/Fixtures/company.vcf'));

        $local = (new VCardContactMapper)->toLocal(['vcard' => $card, 'href' => '/dav/ab/f.vcf']);

        $this->assertSame('company', $local['kind']);
        $this->assertSame('Dachdecker Schmidt GmbH', $local['last_name']);
    }

    public function test_to_external_is_blocked(): void
    {
        $this->expectException(WriteBlockedException::class);

        (new VCardContactMapper)->toExternal([]);
    }
}
