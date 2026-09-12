<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Enums\ImportFormatStatus;
use App\Modules\Imports\Models\ExportSchedule;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Models\ImportFormat;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ImportsTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    private function quarantinedFile(Organization $organization): ImportFile
    {
        $format = ImportFormat::query()->create([
            'format_key' => ExportType::Properties->value,
            'version' => 1,
            'status' => ImportFormatStatus::Unknown->value,
            'header_fingerprint' => hash('sha256', 'objektnummer|bezeichnung|ort'),
            'header_columns' => ['Objektnummer', 'Bezeichnung', 'Ort'],
            'delimiter' => ';',
            'charset' => 'UTF-8',
            'column_mapping' => [],
            'key_schema' => [],
        ]);

        return ImportFile::query()->create([
            'organization_id' => $organization->getKey(),
            'import_format_id' => $format->getKey(),
            'original_filename' => 'objekte_2026-09.csv',
            'file_type' => 'csv',
            'export_type' => ExportType::Properties->value,
            'content_hash' => hash('sha256', 'x'),
            'header_fingerprint' => $format->header_fingerprint,
            'size_bytes' => 1234,
            'status' => ImportFileStatus::Quarantined->value,
            'quarantine_reason' => 'format_unknown',
            'errors' => [['line' => null, 'reason' => 'Header-Fingerprint nicht bestätigt.']],
            'error_summary' => 'Header-Fingerprint nicht bestätigt.',
            'received_at' => now(),
        ]);
    }

    public function test_index_and_detail_render_for_operator(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Operator)->for($organization)->create();
        $file = $this->quarantinedFile($organization);

        $this->login($user)->get('/admin/imports')->assertOk()->assertSee('objekte_2026-09.csv')->assertSee('Quarantäne');
        $this->login($user)->get('/admin/imports?status=quarantined')->assertOk()->assertSee('objekte_2026-09.csv');
        $this->login($user)->get('/admin/imports?status=imported')->assertOk()->assertDontSee('objekte_2026-09.csv');
        $this->login($user)->get('/admin/imports/'.$file->getKey())->assertOk()->assertSee('Header-Fingerprint nicht bestätigt.')->assertSee('Fehlerliste (1)');
    }

    public function test_file_of_other_organization_is_not_visible(): void
    {
        $user = User::factory()->role(Role::Administrator)->create();
        $file = $this->quarantinedFile(Organization::factory()->create());

        $this->login($user)->get('/admin/imports/'.$file->getKey())->assertNotFound();
    }

    public function test_read_only_cannot_confirm_mapping(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::ReadOnly)->for($organization)->create();
        $file = $this->quarantinedFile($organization);

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->get('/admin/imports/'.$file->getKey().'/quarantine')->assertForbidden();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->post('/admin/imports/'.$file->getKey().'/confirm-format', [
            'mapping' => ['object_number' => 'objektnummer'],
            'key_schema' => ['object_number'],
            'confirmation' => 'BESTÄTIGEN',
        ])->assertForbidden();
    }

    public function test_administrator_confirms_mapping_and_audit_entry_is_written(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        $file = $this->quarantinedFile($organization);

        $this->login($user)->get('/admin/imports/'.$file->getKey().'/quarantine')
            ->assertOk()
            ->assertSee($file->header_fingerprint)
            ->assertSee('mapping[object_number]', false)
            ->assertSee('Objektnummer');

        $this->login($user)->post('/admin/imports/'.$file->getKey().'/confirm-format', [
            'mapping' => ['object_number' => 'objektnummer', 'name' => 'bezeichnung', 'city' => 'ort', 'street' => ''],
            'key_schema' => ['object_number'],
            'confirmation' => 'BESTÄTIGEN',
        ])->assertRedirect('/admin/imports/'.$file->getKey());

        $format = ImportFormat::query()->findOrFail($file->import_format_id);
        $this->assertSame(ImportFormatStatus::Confirmed->value, $format->status);
        $this->assertSame(['object_number' => 'objektnummer', 'name' => 'bezeichnung', 'city' => 'ort'], $format->column_mapping);
        $this->assertSame((int) $user->getKey(), (int) $format->confirmed_by);

        $log = AuditLog::query()->where('action', 'admin.imports.format_confirmed')->first();
        $this->assertNotNull($log);
        $this->assertSame('ImportFormat', $log->entity_type);
        $this->assertSame((int) $user->getKey(), (int) $log->actor_id);
    }

    public function test_confirmation_without_confirm_word_is_rejected(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        $file = $this->quarantinedFile($organization);

        $this->login($user)->post('/admin/imports/'.$file->getKey().'/confirm-format', [
            'mapping' => ['object_number' => 'objektnummer'],
            'key_schema' => ['object_number'],
            'confirmation' => 'nein',
        ])->assertStatus(422);

        $this->assertSame(ImportFormatStatus::Unknown->value, ImportFormat::query()->findOrFail($file->import_format_id)->status);
    }

    public function test_invalid_mapping_source_returns_validation_error(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Administrator)->for($organization)->create();
        $file = $this->quarantinedFile($organization);

        $this->login($user)->from('/admin/imports/'.$file->getKey().'/quarantine')->post('/admin/imports/'.$file->getKey().'/confirm-format', [
            'mapping' => ['object_number' => 'gibt_es_nicht'],
            'key_schema' => ['object_number'],
            'confirmation' => 'BESTÄTIGEN',
        ])->assertRedirect('/admin/imports/'.$file->getKey().'/quarantine')->assertSessionHasErrors('mapping');
    }

    public function test_schedules_highlight_overdue_and_can_be_managed(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Operator)->for($organization)->create();
        $connection = ImmowareConnection::factory()->for($organization)->create(['name' => 'Datei-Import HVM']);

        $overdue = ExportSchedule::query()->create([
            'connection_id' => $connection->getKey(),
            'export_type' => ExportType::Units->value,
            'interval_days' => 7,
            'next_due_at' => now()->subDays(3),
        ]);

        $response = $this->login($user)->get('/admin/imports/schedules')->assertOk();
        $response->assertSee('Überfällig');
        $response->assertSee('is-overdue');

        $this->login($user)->post('/admin/imports/schedules', [
            'connection_id' => $connection->getKey(),
            'export_type' => ExportType::Properties->value,
            'interval_days' => 14,
        ])->assertRedirect('/admin/imports/schedules');

        $this->assertDatabaseHas('export_schedules', ['connection_id' => $connection->getKey(), 'export_type' => 'properties', 'interval_days' => 14]);
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.imports.schedule_created')->first());

        $this->login($user)->put('/admin/imports/schedules/'.$overdue->getKey(), ['interval_days' => 30])->assertRedirect('/admin/imports/schedules');
        $this->assertSame(30, (int) $overdue->fresh()->interval_days);

        $this->login($user)->delete('/admin/imports/schedules/'.$overdue->getKey(), ['confirmation' => 'BESTÄTIGEN'])->assertRedirect('/admin/imports/schedules');
        $this->assertDatabaseMissing('export_schedules', ['id' => $overdue->getKey()]);
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.imports.schedule_deleted')->first());
    }

    public function test_read_only_cannot_create_schedule(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::ReadOnly)->for($organization)->create();
        $connection = ImmowareConnection::factory()->for($organization)->create();

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->get('/admin/imports/schedules')->assertOk();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->post('/admin/imports/schedules', [
            'connection_id' => $connection->getKey(),
            'export_type' => ExportType::Properties->value,
            'interval_days' => 14,
        ])->assertForbidden();
    }
}
