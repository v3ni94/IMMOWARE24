<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RecordsTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    public function test_contact_detail_shows_provenance_and_masked_payload(): void
    {
        $organization = Organization::factory()->create();
        $viewer = User::factory()->role(Role::ReadOnly)->for($organization)->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        $connection = ImmowareConnection::factory()->carddav()->for($organization)->create(['name' => 'CardDAV Adressbuch']);
        $contact = Contact::factory()->for($organization)->create(['connection_id' => $connection->getKey(), 'external_id' => 'uid-4711', 'last_name' => 'Beispiel']);

        /** @var ExternalPayloadArchiver $archiver */
        $archiver = $this->app->make(ExternalPayloadArchiver::class);
        $archiver->archive((int) $connection->getKey(), 'vcard', "BEGIN:VCARD\nUID:uid-4711\nFN:Beispiel\nX-TOKEN:abcdefgh12345\nEND:VCARD", 'uid-4711', containsPersonalData: true);

        // Read Only sieht die Detailseite (records.view), aber keinen Link auf die Rohnutzlast (08-security.md Abschnitt 4).
        $page = $this->login($viewer)->get('/admin/records/contacts/'.$contact->getKey())->assertOk();
        $page->assertSee('Herkunft')->assertSee('uid-4711')->assertSee('immoware24')->assertSee('CardDAV Adressbuch')->assertSee('Rohpayload (1)')->assertSee('kein Zugriff')->assertSee('Beispiel');
        $page->assertDontSee('/admin/records/contacts/'.$contact->getKey().'/payload');
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.records.viewed', 'entity_type' => 'Contact', 'entity_id' => $contact->getKey(), 'actor_id' => $viewer->getKey()]);

        $this->login($viewer)->get('/admin/records/contacts/'.$contact->getKey().'/payload')->assertForbidden();
        $this->assertDatabaseMissing('audit_logs', ['action' => 'admin.records.payload_viewed']);

        // Administrator sieht die Rohnutzlast maskiert; der Abruf wird auditiert (08-security.md Abschnitt 6).
        $payload = $this->login($admin)->get('/admin/records/contacts/'.$contact->getKey().'/payload')->assertOk();
        $payload->assertSee('vcard')->assertSee('FN:Beispiel')->assertDontSee('abcdefgh12345');

        $entry = AuditLog::query()->where('action', 'admin.records.payload_viewed')->firstOrFail();
        $this->assertSame((int) $admin->getKey(), (int) $entry->actor_id);
        $this->assertSame((int) $contact->getKey(), (int) $entry->entity_id);
        $this->assertSame((int) $connection->getKey(), (int) $entry->connection_id);
        $this->assertSame('vcard', $entry->after_json['payload_type']);
        $this->assertTrue($entry->after_json['contains_personal_data']);
    }

    public function test_read_only_without_two_factor_is_sent_to_setup_and_payload_needs_permission(): void
    {
        $organization = Organization::factory()->create();
        $withoutTotp = User::factory()->role(Role::ReadOnly)->withoutTotp()->for($organization)->create();
        $operator = User::factory()->role(Role::Operator)->for($organization)->create();
        $developer = User::factory()->role(Role::Developer)->for($organization)->create();
        $unit = Unit::factory()->for(Property::factory()->for($organization))->create();

        // 2FA-Pflicht gilt für alle Rollen (08-security.md 3.1): ohne eingerichtete 2FA keine Detailseite.
        $this->actingAs($withoutTotp)->get('/admin/records/units/'.$unit->getKey())->assertRedirect(route('security.two-factor.setup'));

        $this->login($operator)->get('/admin/records/units/'.$unit->getKey())->assertOk();
        $this->login($operator)->get('/admin/records/units/'.$unit->getKey().'/payload')->assertForbidden();
        $this->login($developer)->get('/admin/records/units/'.$unit->getKey().'/payload')->assertOk();
    }

    public function test_document_property_and_unit_details_render(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Operator)->for($organization)->create();
        $connection = ImmowareConnection::factory()->for($organization)->create(['name' => 'WebDAV Dokumente']);
        $document = Document::factory()->for($connection, 'connection')->create(['filename' => 'Mietvertrag.pdf']);
        $property = Property::factory()->for($organization)->create(['name' => 'Objekt Musterstraße', 'external_id' => 'OBJ-1']);
        $unit = Unit::factory()->for($property)->create(['unit_number' => 'WE 07']);

        $this->login($user)->get('/admin/records/documents/'.$document->getKey())->assertOk()->assertSee('Mietvertrag.pdf')->assertSee('WebDAV Dokumente')->assertSee('Herkunft');
        $this->login($user)->get('/admin/records/properties/'.$property->getKey())->assertOk()->assertSee('Objekt Musterstraße')->assertSee('OBJ-1');
        $this->login($user)->get('/admin/records/units/'.$unit->getKey())->assertOk()->assertSee('WE 07')->assertSee('Herkunft');
        // Operator hat records.view, aber kein payloads.view; Rohnutzlasten sehen nur Owner, Administrator, Developer.
        $this->login($user)->get('/admin/records/units/'.$unit->getKey().'/payload')->assertForbidden();
        $developer = User::factory()->role(Role::Developer)->for($organization)->create();
        $this->login($developer)->get('/admin/records/units/'.$unit->getKey().'/payload')->assertOk()->assertSee('Keine archivierten Nutzlasten');
    }

    public function test_foreign_or_unknown_records_are_not_found_and_no_mutation_routes_exist(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();
        $foreign = Property::factory()->create();

        $this->login($user)->get('/admin/records/properties/'.$foreign->getKey())->assertNotFound();
        $this->login($user)->get('/admin/records/invoices/1')->assertNotFound();
        $this->login($user)->delete('/admin/records/properties/'.$foreign->getKey())->assertStatus(405);
    }
}
