<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Connector\Models\Organization;
use App\Modules\Estate\Models\Property;
use App\Modules\Imports\Enums\HubExportStatus;
use App\Modules\Imports\Jobs\ExportJob;
use App\Modules\Imports\Models\HubExport;
use App\Modules\Imports\Services\HubExportService;
use App\Modules\Imports\Services\ImportStorage;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExportTest extends TestCase
{
    use RefreshDatabase;

    private function login(User $user): static
    {
        return $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
    }

    public function test_index_renders_form_and_list(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::Operator)->for($organization)->create();
        HubExport::query()->create(['organization_id' => $organization->getKey(), 'entity' => 'contacts', 'format' => 'json', 'status' => 'pending', 'requested_by' => $user->getKey()]);
        HubExport::query()->create(['organization_id' => Organization::factory()->create()->getKey(), 'entity' => 'units', 'format' => 'csv', 'status' => 'pending']);

        $this->login($user)->get('/admin/export')->assertOk()->assertSee('Export starten')->assertSee('contacts')->assertSee('pending')->assertDontSee('<td>units</td>', false);
    }

    public function test_store_dispatches_job_and_audits(): void
    {
        Queue::fake();
        $organization = Organization::factory()->create();
        $user = User::factory()->role(Role::ReadOnly)->for($organization)->create();

        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])->post('/admin/export', ['entity' => 'properties', 'format' => 'csv', 'filter_column' => 'city', 'filter_value' => 'Hilden', 'include_deleted' => '1'])->assertRedirect('/admin/export');

        $export = HubExport::query()->firstOrFail();
        $this->assertSame('properties', $export->entity);
        $this->assertSame(['city' => 'Hilden', 'include_deleted' => true], $export->filter);
        $this->assertSame((int) $user->getKey(), (int) $export->requested_by);
        Queue::assertPushed(ExportJob::class, static fn (ExportJob $job): bool => $job->exportId === (int) $export->getKey());
        $this->assertNotNull(AuditLog::query()->where('action', 'admin.export.requested')->first());
    }

    public function test_invalid_filter_column_is_rejected(): void
    {
        $user = User::factory()->role(Role::Operator)->create();

        $this->login($user)->from('/admin/export')->post('/admin/export', ['entity' => 'properties', 'format' => 'csv', 'filter_column' => 'iban', 'filter_value' => 'x'])->assertRedirect('/admin/export')->assertSessionHasErrors('filter_column');
        $this->assertSame(0, HubExport::query()->count());
    }

    public function test_download_is_limited_to_requester_or_administrator(): void
    {
        Storage::fake('local');
        config()->set('hub.imports.exports.disk', 'local');
        $organization = Organization::factory()->create();
        $requester = User::factory()->role(Role::Operator)->for($organization)->create();
        $other = User::factory()->role(Role::Operator)->for($organization)->create();
        $admin = User::factory()->role(Role::Administrator)->for($organization)->create();
        Property::factory()->for($organization)->create(['name' => 'Haus Hilden']);

        $export = HubExport::query()->create(['organization_id' => $organization->getKey(), 'entity' => 'properties', 'format' => 'csv', 'status' => 'pending', 'requested_by' => $requester->getKey()]);
        (new ExportJob((int) $export->getKey()))->handle($this->app->make(HubExportService::class), $this->app->make(ImportStorage::class));
        $this->assertSame(HubExportStatus::Completed, $export->fresh()->status);

        $this->login($other)->get('/admin/export/'.$export->getKey().'/download')->assertForbidden();

        $response = $this->login($requester)->get('/admin/export/'.$export->getKey().'/download');
        $response->assertOk()->assertHeader('content-disposition');
        $this->assertStringContainsString('Haus Hilden', $response->streamedContent());

        $this->login($admin)->get('/admin/export/'.$export->getKey().'/download')->assertOk();
        $this->assertSame(2, AuditLog::query()->where('action', 'admin.export.downloaded')->count());
    }
}
