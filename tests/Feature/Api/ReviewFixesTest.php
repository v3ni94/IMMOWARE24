<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Api\Http\Middleware\IdempotencyMiddleware;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Estate\Models\Property;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\ProposedChange;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Documents\DocumentsTestHelpers;

/**
 * Review-Findings 12.09.2026 im Bereich Api: Idempotenz-Race, Feature-Flag HUB_API_KEYS_ENABLED,
 * Mandantenprüfung der Fremdschlüssel im Upload, Upload-Status per operation_uuid, Cursor-Pagination,
 * Rate Limit auf allen API-Routen, lesende Endpunkte proposals und conflicts, connector als Adaptername.
 */
final class ReviewFixesTest extends ApiTestCase
{
    use DocumentsTestHelpers;

    public function test_parallel_request_with_same_idempotency_key_gets_409_in_progress(): void
    {
        $this->issueKey(['cases:write']);
        $apiKeyId = (int) ApiKey::query()->firstOrFail()->getKey();

        // Ein erster Request hält den In-Progress-Marker (Controller läuft noch).
        Cache::add(IdempotencyMiddleware::inProgressKey($apiKeyId, 'POST', '/api/v1/cases', 'idem-race-1'), 'x', 60);

        $this->postJson('/api/v1/cases', ['title' => 'Wasserschaden Keller'], $this->writeHeaders('idem-race-1'))
            ->assertStatus(409)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJson(['code' => 'idempotency_in_progress']);

        $this->assertDatabaseCount('cases', 0);
        $this->assertDatabaseCount('api_idempotency_keys', 0);

        // Nach Abschluss des ersten Requests ist der Marker frei: normaler Ablauf, Marker wieder gelöscht.
        Cache::forget(IdempotencyMiddleware::inProgressKey($apiKeyId, 'POST', '/api/v1/cases', 'idem-race-1'));
        $this->postJson('/api/v1/cases', ['title' => 'Wasserschaden Keller'], $this->writeHeaders('idem-race-1'))->assertStatus(201);
        $this->assertFalse(Cache::has(IdempotencyMiddleware::inProgressKey($apiKeyId, 'POST', '/api/v1/cases', 'idem-race-1')));
        $this->assertDatabaseCount('api_idempotency_keys', 1);
    }

    public function test_api_keys_disabled_flag_answers_503_problem_json(): void
    {
        $this->issueKey(['properties:read', 'admin']);
        config()->set('hub.core.api_keys.enabled', false);

        $this->getJson('/api/v1/properties', $this->authHeaders())
            ->assertStatus(503)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('Retry-After')
            ->assertJson(['code' => 'api_keys_disabled']);

        $this->postJson('/api/v1/mcp/tools', [], $this->authHeaders())->assertStatus(503)->assertJson(['code' => 'api_keys_disabled']);
        $this->getJson('/api/v1/webhook-endpoints', $this->authHeaders())->assertStatus(503)->assertJson(['code' => 'api_keys_disabled']);

        config()->set('hub.core.api_keys.enabled', true);
        $this->getJson('/api/v1/properties', $this->authHeaders())->assertOk();
    }

    public function test_upload_rejects_case_and_source_document_of_other_organization(): void
    {
        $this->issueKey(['documents:write']);
        $foreignCase = $this->caseFor((int) $this->createOrganization()->getKey());
        $this->assertNotSame((int) $this->organization->getKey(), (int) $foreignCase->organization_id);

        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->create('rechnung.pdf', 12, 'application/pdf'),
            'filename' => 'rechnung.pdf',
            'case_id' => $foreignCase->getKey(),
            'source_document_id' => 999999,
        ], $this->writeHeaders('idem-upload-fk-1'));

        $response->assertStatus(422)
            ->assertJson(['code' => 'validation_failed']);

        $fields = array_column((array) $response->json('errors'), 'field');
        $this->assertContains('case_id', $fields);
        $this->assertContains('source_document_id', $fields);
        $this->assertDatabaseCount('write_operations', 0);
    }

    public function test_upload_returns_202_with_operation_uuid_and_status_endpoint_is_tenant_scoped(): void
    {
        Http::fake();
        $this->enableWriteFlags();
        $read = $this->readConnection($this->organization);
        $this->writeConnection($read);
        $this->issueKey(['documents:write']);
        $case = $this->caseFor((int) $this->organization->getKey());

        $response = $this->post('/api/v1/documents', [
            'file' => UploadedFile::fake()->createWithContent('HUBTEST_rechnung.pdf', '%PDF-1.4 Testinhalt HUBTEST'),
            'filename' => 'HUBTEST_rechnung.pdf',
            'case_id' => $case->getKey(),
        ], $this->writeHeaders('idem-upload-uuid-1'));

        $response->assertStatus(202)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.approval_required', true)
            ->assertJsonPath('data.outcome', 'pending_approval')
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.case_id', (int) $case->getKey());

        // Der Request selbst löst nie ein PUT aus (Documents::submit, Freigabe durch einen Menschen).
        Http::assertNothingSent();

        $uuid = (string) $response->json('data.operation_uuid');
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $uuid);
        $this->assertSame('/api/v1/documents/uploads/'.$uuid, $response->json('data.status_url'));
        $response->assertHeader('Location', '/api/v1/documents/uploads/'.$uuid);

        $this->getJson('/api/v1/documents/uploads/'.$uuid, $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('data.operation_uuid', $uuid)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.requested_via', 'api_key');

        // sync:read genügt zum Lesen des Status.
        $this->issueKey(['sync:read']);
        $this->getJson('/api/v1/documents/uploads/'.$uuid, $this->authHeaders())->assertOk();

        // Fremder Mandant sieht den Antrag nicht.
        $this->issueKey(['documents:write', 'sync:read'], $this->createOrganization());
        $this->getJson('/api/v1/documents/uploads/'.$uuid, $this->authHeaders())->assertStatus(404);
        $this->getJson('/api/v1/documents/uploads/00000000-0000-0000-0000-000000000000', $this->authHeaders())->assertStatus(404);
    }

    public function test_cursor_pagination_walks_all_pages_without_duplicates(): void
    {
        $this->issueKey(['properties:read']);
        Property::factory()->count(7)->for($this->organization)->create();
        Property::factory()->count(2)->create();

        $seen = [];
        $cursor = '';
        $pages = 0;

        do {
            $response = $this->getJson('/api/v1/properties?per_page=3&sort=name&cursor='.$cursor, $this->authHeaders());
            $response->assertOk()->assertJsonPath('meta.per_page', 3)->assertJsonStructure(['meta' => ['next_cursor', 'prev_cursor', 'count'], 'links' => ['self', 'next', 'prev']]);
            $this->assertNull($response->json('meta.total'), 'Cursor-Modus liefert kein total');

            foreach ($response->json('data') as $row) {
                $seen[] = $row['id'];
            }

            $cursor = (string) $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== '' && $pages < 10);

        $this->assertSame(3, $pages);
        $this->assertCount(7, $seen);
        $this->assertSame($seen, array_values(array_unique($seen)), 'Keine Duplikate über die Seiten');
        $this->assertNull($response->json('links.next'));

        $this->getJson('/api/v1/properties?cursor=nicht-base64', $this->authHeaders())
            ->assertStatus(400)
            ->assertJson(['code' => 'invalid_cursor']);

        // Offset-Modus unverändert.
        $this->getJson('/api/v1/properties?per_page=5', $this->authHeaders())->assertOk()->assertJsonPath('meta.total', 7);
    }

    public function test_proposals_and_conflicts_are_readable_and_tenant_scoped(): void
    {
        $this->issueKey(['conflicts:read']);
        $ownConnection = $this->createConnection($this->organization);
        $foreignConnection = $this->createConnection();

        $own = new ProposedChange;
        $own->forceFill(['organization_id' => $this->organization->getKey(), 'connection_id' => $ownConnection->getKey(), 'entity_type' => 'contact', 'entity_id' => 1, 'field' => 'phones', 'old_value' => 'a', 'new_value' => 'b', 'status' => 'open'])->save();
        $foreign = new ProposedChange;
        $foreign->forceFill(['organization_id' => $foreignConnection->organization_id, 'connection_id' => $foreignConnection->getKey(), 'entity_type' => 'contact', 'entity_id' => 2, 'field' => 'phones', 'old_value' => 'a', 'new_value' => 'b', 'status' => 'open'])->save();

        $ownConflict = new Conflict;
        $ownConflict->forceFill(['connection_id' => $ownConnection->getKey(), 'entity_type' => 'contact', 'entity_id' => 1, 'conflict_type' => 'duplicate_external', 'status' => 'open'])->save();
        $foreignConflict = new Conflict;
        $foreignConflict->forceFill(['connection_id' => $foreignConnection->getKey(), 'entity_type' => 'contact', 'entity_id' => 2, 'conflict_type' => 'duplicate_external', 'status' => 'open'])->save();

        $this->getJson('/api/v1/proposals', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (int) $own->getKey())
            ->assertJsonPath('data.0.provenance.source_system', 'hub');
        $this->getJson('/api/v1/proposals/'.$foreign->getKey(), $this->authHeaders())->assertStatus(404);

        $this->getJson('/api/v1/conflicts?status=open', $this->authHeaders())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', (int) $ownConflict->getKey())
            ->assertJsonPath('data.0.conflict_type', 'duplicate_external');
        $this->getJson('/api/v1/conflicts/'.$foreignConflict->getKey(), $this->authHeaders())->assertStatus(404);

        $this->issueKey(['properties:read']);
        $this->getJson('/api/v1/conflicts', $this->authHeaders())->assertStatus(403);
    }

    private function caseFor(int $organizationId): CaseFile
    {
        $case = new CaseFile;
        $case->forceFill(['organization_id' => $organizationId, 'title' => 'Vorgang '.$organizationId, 'status' => 'open', 'source_system' => 'hub'])->save();

        return $case;
    }

    public function test_every_api_route_carries_auth_and_throttle_middleware(): void
    {
        $checked = 0;

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $middleware = array_values(array_filter($route->gatherMiddleware(), 'is_string'));
            $hasThrottle = array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'api.throttle'));

            $this->assertContains('api.auth', $middleware, sprintf('Route %s ohne api.auth', $route->uri()));
            $this->assertNotEmpty($hasThrottle, sprintf('Route %s ohne api.throttle', $route->uri()));
            $checked++;
        }

        $this->assertGreaterThan(10, $checked);
        $this->assertTrue(Route::has('api.v1.mcp.call'));
        $this->assertTrue(Route::has('api.v1.webhook-endpoints.store'));
    }
}
