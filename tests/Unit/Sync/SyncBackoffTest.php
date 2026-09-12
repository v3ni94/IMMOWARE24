<?php

declare(strict_types=1);

namespace Tests\Unit\Sync;

use App\Core\Enums\SyncMode;
use App\Modules\Sync\Jobs\FetchImmowareCalendarJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Support\SyncBackoff;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

final class SyncBackoffTest extends TestCase
{
    public function test_backoff_without_jitter_matches_concept_stages(): void
    {
        $this->assertSame([30, 120, 600, 1800], SyncBackoff::seconds(withJitter: false));
    }

    public function test_backoff_with_jitter_stays_within_stage_bounds(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $values = SyncBackoff::seconds();
            $this->assertCount(4, $values);

            foreach ($values as $stage => $seconds) {
                $this->assertTrue(SyncBackoff::isWithinStage($stage, $seconds), sprintf('Stufe %d: %d s außerhalb [Basis, Basis + Jitter]', $stage + 1, $seconds));
            }
        }
    }

    public function test_job_retry_parameters(): void
    {
        config()->set('hub.sync.jobs.jitter_enabled', false);
        $job = new RunSyncJob(1, 'document', SyncMode::Incremental);

        $this->assertSame(5, $job->tries, 'Fünfter Versuch endet in der DLQ.');
        $this->assertSame(900, $job->timeout);
        $this->assertSame('sync', $job->queue);
        $this->assertSame([30, 120, 600, 1800], $job->backoff());

        $middleware = $job->middleware();
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('immoware:sync:1:document', SyncLockManager::key(1, 'document'));

        $wrapper = new FetchImmowareCalendarJob(3, SyncMode::Full);
        $this->assertSame('calendar_event', $wrapper->entityType);
        $this->assertSame(5, $wrapper->tries);
    }

    public function test_dlq_arguments_rebuild_job(): void
    {
        $job = new RunSyncJob(4, 'contact', SyncMode::Full, 'c9', limit: 1, singleRecord: true);
        $rebuilt = RunSyncJob::fromDlqArguments($job->dlqArguments());

        $this->assertSame(4, $rebuilt->connectionId);
        $this->assertSame('contact', $rebuilt->entityType);
        $this->assertSame(SyncMode::Full, $rebuilt->mode);
        $this->assertSame('c9', $rebuilt->cursor);
        $this->assertSame(1, $rebuilt->limit);
        $this->assertTrue($rebuilt->singleRecord);
        $this->assertSame('recovery', $rebuilt->triggerSource);
    }
}
