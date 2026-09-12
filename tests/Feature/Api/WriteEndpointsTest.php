<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Estate\Models\Property;
use Illuminate\Http\UploadedFile;

final class WriteEndpointsTest extends ApiTestCase
{
    public function test_post_case_requires_idempotency_key_and_validates(): void
    {
        $this->issueKey(['cases:write', 'cases:read']);

        $this->postJson('/api/v1/cases', ['title' => 'Wasserschaden Keller'], $this->authHeaders())
            ->assertStatus(400)
            ->assertJson(['code' => 'idempotency_key_required']);

        $this->postJson('/api/v1/cases', ['title' => 'ab'], $this->writeHeaders('idem-validation-1'))
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['code' => 'validation_failed', 'type' => 'https://immoware.muellerhv.de/errors/validation_failed'])
            ->assertJsonPath('errors.0.field', 'title');
    }

    public function test_post_case_creates_hub_case_and_replays_with_idempotency_key(): void
    {
        $this->issueKey(['cases:write', 'cases:read']);
        $property = Property::factory()->for($this->organization)->create();

        $payload = ['title' => 'Heizungsausfall Haus 3', 'property_id' => $property->getKey(), 'status' => 'open'];

        $first = $this->postJson('/api/v1/cases', $payload, $this->writeHeaders('idem-case-1'));
        $first->assertStatus(201)
            ->assertHeader('Idempotent-Replayed', 'false')
            ->assertJsonPath('data.title', 'Heizungsausfall Haus 3')
            ->assertJsonPath('data.provenance.source_system', 'hub')
            ->assertJsonPath('data.provenance.stale', false);

        $caseId = $first->json('data.id');
        $this->assertDatabaseHas('cases', ['id' => $caseId, 'organization_id' => $this->organization->getKey(), 'source_system' => 'hub']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'api.case.created', 'entity_id' => $caseId]);

        $replay = $this->postJson('/api/v1/cases', $payload, $this->writeHeaders('idem-case-1'));
        $replay->assertStatus(201)->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $replay->json('data.id'));
        $this->assertSame(1, CaseFile::query()->count());

        $this->postJson('/api/v1/cases', ['title' => 'Anderer Inhalt'], $this->writeHeaders('idem-case-1'))
            ->assertStatus(409)
            ->assertJson(['code' => 'idempotency_mismatch']);

        $this->patchJson('/api/v1/cases/'.$caseId, ['status' => 'closed'], $this->writeHeaders('idem-case-2'))
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->patchJson('/api/v1/cases/'.$caseId, ['status' => 'unbekannt'], $this->writeHeaders('idem-case-3'))
            ->assertStatus(422);

        $this->getJson('/api/v1/cases/'.$caseId, $this->authHeaders())->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_patch_contact_creates_proposed_change_only(): void
    {
        $this->issueKey(['contacts:write']);
        $contact = Contact::factory()->for($this->organization)->create(['last_name' => 'Alt']);

        $response = $this->patchJson('/api/v1/contacts/'.$contact->getKey(), [
            'changes' => ['last_name' => 'Neu'],
            'reason' => 'Namensänderung laut Schreiben vom 01.09.2026',
        ], $this->writeHeaders('idem-contact-1'));

        $response->assertStatus(202)
            ->assertJsonPath('data.effect', 'hub')
            ->assertJsonPath('data.proposals.0.field', 'last_name')
            ->assertJsonPath('data.proposals.0.new_value', 'Neu')
            ->assertJsonPath('data.proposals.0.status', 'open');

        $this->assertSame('Alt', $contact->refresh()->getAttribute('last_name'));
        $this->assertDatabaseHas('proposed_changes', ['entity_type' => 'contact', 'entity_id' => $contact->getKey(), 'field' => 'last_name', 'status' => 'open']);

        $this->patchJson('/api/v1/contacts/'.$contact->getKey(), ['changes' => ['iban' => 'DE00']], $this->writeHeaders('idem-contact-2'))
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed']);
    }

    public function test_document_upload_is_blocked_while_write_flag_is_false(): void
    {
        $this->issueKey(['documents:write']);

        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('rechnung.pdf', 12, 'application/pdf'),
            'filename' => 'rechnung.pdf',
        ], $this->writeHeaders('idem-upload-1'));

        $response->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['code' => 'write_disabled']);

        $this->assertDatabaseCount('write_operations', 0);
    }
}
