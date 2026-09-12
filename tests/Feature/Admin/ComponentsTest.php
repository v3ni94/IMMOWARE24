<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Admin\Http\Controllers\AdminController;
use App\Modules\Estate\Models\Property;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ComponentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_view_masks_secrets_and_pretty_prints(): void
    {
        $html = Blade::render('<x-admin.json-view :data="$data" title="Payload" />', [
            'data' => ['username' => 'hub-read', 'password' => 'geheim123', 'nested' => ['api_key' => 'abc', 'url' => 'https://user:pw@host/dav']],
        ]);

        $this->assertStringContainsString('hub-read', $html);
        $this->assertStringNotContainsString('geheim123', $html);
        $this->assertStringNotContainsString('"abc"', $html);
        $this->assertStringNotContainsString(':pw@', $html);
        $this->assertStringContainsString('***', $html);
        $this->assertStringContainsString('class="hub-mono hub-pre"', $html);
    }

    public function test_json_view_masks_secrets_in_raw_json_strings(): void
    {
        $html = Blade::render('<x-admin.json-view :data="$data" />', ['data' => '{"token":"s3cret-token","ok":true}']);

        $this->assertStringNotContainsString('s3cret-token', $html);
        $this->assertStringContainsString('&quot;ok&quot;: true', $html);
    }

    public function test_status_badge_falls_back_to_unknown_for_invalid_level(): void
    {
        $this->assertStringContainsString('hub-badge-ok', Blade::render('<x-admin.status-badge status="ok" />'));
        $this->assertStringContainsString('Deaktiviert', Blade::render('<x-admin.status-badge status="disabled" />'));

        $html = Blade::render('<x-admin.status-badge status="irgendwas" label="Frei" />');
        $this->assertStringContainsString('hub-badge-unknown', $html);
        $this->assertStringContainsString('Frei', $html);
    }

    public function test_confirm_form_requires_confirmation_word_and_spoofs_method(): void
    {
        config()->set('hub.admin.confirm_word', 'LOESCHEN');

        $html = Blade::render('<x-admin.confirm-form action="/admin/x" method="DELETE" label="Verbindung löschen" note-field="reason" />');

        $this->assertStringContainsString('method="POST"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('name="_token"', $html);
        $this->assertStringContainsString('name="confirmation"', $html);
        $this->assertStringContainsString('data-hub-confirm-word="LOESCHEN"', $html);
        $this->assertStringContainsString('name="reason"', $html);
        $this->assertStringContainsString('Verbindung löschen bestätigen', $html);
    }

    public function test_provenance_reads_external_identity_from_model(): void
    {
        $property = Property::factory()->create(['external_id' => 'OBJ-EXT-4711', 'source_system' => 'immoware24']);

        $html = Blade::render('<x-admin.provenance :model="$model" connector="WebDAV Dokumente" mapping-version="3" />', ['model' => $property]);

        $this->assertStringContainsString('OBJ-EXT-4711', $html);
        $this->assertStringContainsString('immoware24', $html);
        $this->assertStringContainsString('WebDAV Dokumente', $html);
        $this->assertStringContainsString('v3', $html);
        $this->assertStringContainsString('Letzter Sync', $html);
    }

    public function test_data_table_renders_pagination_and_empty_state(): void
    {
        $paginator = new LengthAwarePaginator([['a'], ['b']], 120, 2, 3, ['path' => '/admin/liste']);

        $html = Blade::render('<x-admin.data-table :columns="[\'Spalte\']" :rows="$rows"><tr><td>Zeile</td></tr></x-admin.data-table>', ['rows' => $paginator]);

        $this->assertStringContainsString('Seite 3', $html);
        $this->assertStringContainsString('von 60', $html);
        $this->assertStringContainsString('/admin/liste?page=2', $html);
        $this->assertStringContainsString('/admin/liste?page=4', $html);
        $this->assertStringContainsString('Zeile', $html);

        $empty = Blade::render('<x-admin.data-table :columns="[\'A\', \'B\']" :rows="[]" empty="Nichts da." />');
        $this->assertStringContainsString('Nichts da.', $empty);
        $this->assertStringContainsString('colspan="2"', $empty);
    }

    public function test_key_value_and_stat_card_format_values(): void
    {
        $html = Blade::render('<x-admin.key-value :items="$items" />', ['items' => ['Aktiv' => true, 'Leer' => null, 'Zahl' => 42]]);
        $this->assertStringContainsString('<dt>Aktiv</dt>', $html);
        $this->assertStringContainsString('ja', $html);
        $this->assertStringContainsString('keine Angabe', $html);

        $stat = Blade::render('<x-admin.stat-card label="Kontakte" :value="4600" status="ok" />');
        $this->assertStringContainsString('4.600', $stat);
        $this->assertStringContainsString('hub-stat-ok', $stat);
    }

    public function test_admin_controller_audit_helper_writes_append_only_entry_with_masking(): void
    {
        Route::middleware('admin')->prefix('admin')->name('admin.')->post('/test-mutation', MutationTestController::class)->name('test.mutate');

        $admin = User::factory()->role(Role::Administrator)->create();
        $session = [LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()];

        $this->actingAs($admin)->withSession($session)->post('/admin/test-mutation', ['confirmation' => 'falsch'])->assertStatus(422);
        $this->assertDatabaseCount('audit_logs', 0);

        $this->actingAs($admin)->withSession($session)->post('/admin/test-mutation', ['confirmation' => 'BESTÄTIGEN'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('status', 'Erledigt.');

        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.test.mutated', 'actor_type' => 'user', 'actor_id' => $admin->getKey(), 'source' => 'user']);
        $row = DB::table('audit_logs')->where('action', 'admin.test.mutated')->first();
        $this->assertIsObject($row);
        $this->assertStringNotContainsString('klartext', (string) $row->after_json);
        $this->assertStringContainsString('active', (string) $row->after_json);

        $operator = User::factory()->role(Role::Operator)->create();
        $this->actingAs($operator)->withSession($session)->post('/admin/test-mutation', ['confirmation' => 'BESTÄTIGEN'])->assertForbidden();
    }
}

/**
 * Testcontroller für die Helfer des Basis-Controllers.
 */
final class MutationTestController extends AdminController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $this->requirePermission('connections.manage');
        $this->requireConfirmation($request);
        $this->audit('test.mutated', null, ['status' => 'paused'], ['status' => 'active', 'password' => 'klartext']);

        return $this->redirectWithStatus('admin.dashboard', 'Erledigt.');
    }
}
