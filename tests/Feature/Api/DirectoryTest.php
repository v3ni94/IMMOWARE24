<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactRole;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;

final class DirectoryTest extends ApiTestCase
{
    private function seedContact(): Contact
    {
        $property = Property::factory()->for($this->organization)->create(['name' => 'WEG Lindenstraße 5']);
        $unit = Unit::factory()->for($property)->create(['organization_id' => $this->organization->getKey(), 'unit_number' => 'WE 07']);

        $contact = Contact::factory()->for($this->organization)->create([
            'first_name' => 'Anna',
            'last_name' => 'Beispiel, Dr.',
            'company_name' => 'Beispiel & Partner',
            'phones' => [['type' => 'work', 'value' => '021155512'], ['type' => 'cell', 'value' => '01709998877']],
            'emails' => [['type' => 'work', 'value' => 'anna@example.test']],
            'notes' => 'Vertrauliche Notiz',
        ]);

        ContactRole::query()->create([
            'organization_id' => $this->organization->getKey(),
            'contact_id' => $contact->getKey(),
            'role' => 'owner',
            'property_id' => $property->getKey(),
            'unit_id' => $unit->getKey(),
            'source_system' => 'immoware24',
            'external_id' => 'role-1',
        ]);

        return $contact;
    }

    public function test_directory_requires_scope_and_returns_minimized_entries(): void
    {
        $this->seedContact();
        $this->issueKey(['contacts:read']);
        $this->getJson('/api/v1/directory', $this->authHeaders())->assertStatus(403);

        $this->issueKey(['directory:read']);
        $response = $this->getJson('/api/v1/directory', $this->authHeaders());

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.display_name', 'Anna Beispiel, Dr.')
            ->assertJsonPath('data.0.mobiles.0', '01709998877')
            ->assertJsonPath('data.0.phones.0', '021155512')
            ->assertJsonPath('data.0.roles.0.role', 'owner')
            ->assertJsonPath('data.0.roles.0.property', 'WEG Lindenstraße 5')
            ->assertJsonPath('data.0.roles.0.unit', 'WE 07');

        $json = $response->getContent();
        $this->assertStringNotContainsString('Vertrauliche Notiz', (string) $json);
        $this->assertStringNotContainsString('iban', strtolower((string) $json));
        $this->assertStringNotContainsString('birth_date', (string) $json);
        $this->assertArrayNotHasKey('notes', $response->json('data.0'));
    }

    public function test_directory_search_matches_phone_property_and_unit(): void
    {
        $this->seedContact();
        Contact::factory()->for($this->organization)->create(['first_name' => 'Bernd', 'last_name' => 'Zufall', 'phones' => []]);
        $this->issueKey(['directory:read']);

        $this->getJson('/api/v1/directory/search?q=0170%20999', $this->authHeaders())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/directory/search?q=Lindenstra', $this->authHeaders())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/directory/search?q=WE%2007', $this->authHeaders())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/directory/search?q=owner', $this->authHeaders())->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/directory/search?q=Zufall', $this->authHeaders())->assertOk()->assertJsonPath('data.0.last_name', 'Zufall');
        $this->getJson('/api/v1/directory/search?q=x', $this->authHeaders())->assertStatus(400);
    }

    public function test_vcf_output_is_valid_and_xml_output_is_well_formed(): void
    {
        $this->seedContact();
        $this->issueKey(['directory:read']);

        $vcf = $this->get('/api/v1/directory?format=vcf', $this->authHeaders());
        $vcf->assertOk();
        $this->assertStringStartsWith('text/vcard', (string) $vcf->headers->get('Content-Type'));

        $body = (string) $vcf->getContent();
        $this->assertStringStartsWith("BEGIN:VCARD\r\nVERSION:3.0\r\n", $body);
        $this->assertStringEndsWith("END:VCARD\r\n", $body);
        $this->assertStringContainsString("FN:Anna Beispiel\\, Dr.\r\n", $body);
        $this->assertStringContainsString("N:Beispiel\\, Dr.;Anna;;;\r\n", $body);
        $this->assertStringContainsString('TEL;TYPE=CELL:01709998877', $body);
        $this->assertStringContainsString('TEL;TYPE=WORK,VOICE:021155512', $body);
        $this->assertStringContainsString('ORG:Beispiel & Partner', $body);
        $this->assertStringNotContainsString('Vertrauliche Notiz', $body);

        foreach (explode("\r\n", rtrim($body)) as $line) {
            $this->assertLessThanOrEqual(76, strlen($line), 'vCard-Zeilen müssen auf 75 Oktette gefaltet sein.');
        }

        $xml = $this->get('/api/v1/directory?format=xml', $this->authHeaders());
        $xml->assertOk();
        $document = simplexml_load_string((string) $xml->getContent());
        $this->assertNotFalse($document);
        $this->assertSame('phonebook', $document->getName());
        $this->assertSame('mobile', (string) $document->entry[0]->phones->phone[1]['type']);
        $this->assertSame('WEG Lindenstraße 5', (string) $document->entry[0]->roles->role[0]['property']);
    }
}
