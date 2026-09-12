<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\DTO\SyncResult;
use App\Core\Enums\Role;
use App\Core\Enums\SyncMode;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Enums\ProposedChangeStatus;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\FetchImmowareContactsJob;
use App\Modules\Sync\Jobs\FetchImmowareDocumentsJob;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Schedule\SyncSchedule;
use App\Modules\Sync\Services\BootstrapService;
use App\Modules\Sync\Services\ProposedChangeService;
use App\Modules\Sync\Services\SyncDispatcher;
use App\Modules\Sync\Services\SyncMetrics;
use App\Modules\Sync\Services\SyncRunService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

final class BootstrapAndScheduleTest extends SyncTestCase
{
    public function test_bootstrap_aborts_when_error_rate_exceeds_threshold(): void
    {
        $connection = $this->activeConnection();
        $this->connector->always(new SyncResult(processed: 10, created: 8, failed: 2, cursor: null));

        $report = $this->app->make(BootstrapService::class)->run($connection, SyncEntity::Document->value, [1, 10, 100], 0.05);

        $this->assertFalse($report['completed']);
        $this->assertSame(1, $report['aborted_at_stage']);
        $this->assertCount(1, $report['stages']);
        $this->assertSame(1, $this->connector->lastRequest()?->limit);
        $this->assertSame(SyncRun::TYPE_BOOTSTRAP, SyncRun::query()->firstOrFail()->getAttribute('run_type'));
        $this->assertSame('manual', SyncRun::query()->firstOrFail()->getAttribute('trigger_source'));
    }

    public function test_bootstrap_passes_all_stages_via_command(): void
    {
        $connection = $this->activeConnection();
        $this->connector->always(new SyncResult(processed: 10, created: 10));

        $this->artisan('hub:sync:bootstrap', ['connection' => $connection->getKey(), 'entity' => 'document', '--stages' => '1,10,alle'])
            ->expectsOutputToContain('Bootstrap abgeschlossen.')
            ->assertSuccessful();

        $this->assertSame(3, SyncRun::query()->count());
        $this->assertSame([1, 10, 500], array_map(static fn ($r): int => $r->limit, $this->connector->requests));
    }

    public function test_schedule_registers_configured_intervals(): void
    {
        config()->set('hub.sync.schedule.contact', '*/7 * * * *');
        $schedule = new Schedule;
        (new SyncSchedule)->register($schedule);

        $events = collect($schedule->events());
        $this->assertCount(6, $events, 'drei Incremental-Läufe, Full, Stale-Check, Payload-Prune');

        $contacts = $events->first(fn ($e): bool => $e->description === SyncSchedule::DESCRIPTION_PREFIX.'incremental contact');
        $this->assertNotNull($contacts);
        $this->assertSame('*/7 * * * *', $contacts->expression);
        $this->assertSame('Europe/Berlin', $contacts->timezone);
        $this->assertTrue($contacts->withoutOverlapping);
        $this->assertTrue($contacts->onOneServer);
        $this->assertTrue($contacts->runInBackground);
        $this->assertStringContainsString('hub:sync:dispatch', $contacts->command);

        $full = $events->first(fn ($e): bool => $e->description === SyncSchedule::DESCRIPTION_PREFIX.'full all');
        $this->assertSame('30 2 * * *', $full->expression);
    }

    public function test_dispatch_for_all_targets_only_matching_active_connections(): void
    {
        Queue::fake();
        $organization = $this->createOrganization();
        $this->createConnection($organization, ['status' => 'active', 'connector_type' => 'carddav_contacts']);
        $this->createConnection($organization, ['status' => 'paused', 'connector_type' => 'carddav_contacts']);
        $this->createConnection($organization, ['status' => 'active', 'connector_type' => 'webdav_documents']);

        $this->artisan('hub:sync:dispatch', ['entity' => 'contact'])->assertSuccessful();

        Queue::assertPushed(FetchImmowareContactsJob::class, 1);
        Queue::assertNotPushed(FetchImmowareDocumentsJob::class);

        $this->assertSame(1, $this->app->make(SyncDispatcher::class)->dispatchForAll(SyncEntity::Document, SyncMode::Full));
        Queue::assertPushed(FetchImmowareDocumentsJob::class, 1);
    }

    public function test_proposed_change_lifecycle_with_audit(): void
    {
        $connection = $this->activeConnection();
        $user = $this->actingAsRole(Role::Operator, $connection->organization);
        $service = $this->app->make(ProposedChangeService::class);

        $change = $service->propose('contact', 12, 'emails.0.value', 'alt@example.test', 'neu@example.test', $user, (int) $connection->getKey(), null, 'Mieter hat neue Adresse gemeldet');
        $this->assertSame(ProposedChangeStatus::Open, $change->getAttribute('status'));
        $this->assertSame((int) $connection->organization->getKey(), (int) $change->getAttribute('organization_id'));

        $service->markTransferred((int) $change->getKey(), $user);
        $this->assertSame(ProposedChangeStatus::Transferred, $change->fresh()->getAttribute('status'));
        $this->assertNotNull($change->fresh()->getAttribute('transferred_at'));

        $run = $this->app->make(SyncRunService::class)->start((int) $connection->getKey(), 'contact', SyncMode::Incremental);
        $service->confirm((int) $change->getKey(), $run);
        $this->assertSame(ProposedChangeStatus::Confirmed, $change->fresh()->getAttribute('status'));
        $this->assertSame((int) $run->getKey(), (int) $change->fresh()->getAttribute('confirmed_by_sync_run_id'));

        $this->assertSame(3, AuditLog::query()->where('action', 'like', 'proposed_change.%')->count());
    }

    public function test_metrics_export_summary(): void
    {
        $metrics = $this->app->make(SyncMetrics::class);
        $metrics->reset();
        $metrics->increment(SyncMetrics::REMOTE_REQUESTS, 3, ['connection' => 1]);
        $metrics->increment(SyncMetrics::REMOTE_REQUESTS, 2, ['connection' => 2]);
        $metrics->increment(SyncMetrics::RATE_LIMITED);
        $metrics->observeDuration(1500, ['entity' => 'contact']);
        $metrics->observeDuration(500, ['entity' => 'contact']);
        $metrics->gauge(SyncMetrics::QUEUE_DEPTH, 7);

        $summary = $metrics->summary();
        $this->assertSame(5, $summary[SyncMetrics::REMOTE_REQUESTS]);
        $this->assertSame(1, $summary[SyncMetrics::RATE_LIMITED]);
        $this->assertSame(7, $summary[SyncMetrics::QUEUE_DEPTH]);
        $this->assertSame(2000, $summary['sync_duration_sum_ms']);
        $this->assertSame(1500, $summary['sync_duration_max_ms']);
        $this->assertSame(0, $summary[SyncMetrics::FAILED_JOBS]);

        $export = $metrics->export();
        $this->assertCount(2, $export[SyncMetrics::REMOTE_REQUESTS]);
    }
}
