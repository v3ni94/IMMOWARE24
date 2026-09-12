<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\FetchImmowareContactsJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Services\SyncMetrics;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

final class RunSyncJobTest extends SyncTestCase
{
    public function test_chunk_boundary_redispatches_with_next_cursor_and_same_run(): void
    {
        Queue::fake();
        config()->set('hub.sync.chunks.max_per_run', 2);
        $connection = $this->activeConnection();

        $this->connector
            ->page(null, new SyncResult(processed: 5, created: 5, cursor: 'c1'))
            ->page('c1', new SyncResult(processed: 5, updated: 5, cursor: 'c2'))
            ->page('c2', new SyncResult(processed: 1));

        $this->runJob(new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value, SyncMode::Incremental));

        $this->assertSame(2, $this->connector->requestCount(), 'Nach max_per_run Chunks muss der Job stoppen.');
        $run = SyncRun::query()->firstOrFail();

        Queue::assertPushed(RunSyncJob::class, function (RunSyncJob $job) use ($run, $connection): bool {
            return $job->cursor === 'c2'
                && $job->runId === (int) $run->getKey()
                && $job->connectionId === (int) $connection->getKey()
                && $job->entityType === SyncEntity::Document->value;
        });

        $this->assertSame(SyncStatus::Running, $run->getAttribute('status'), 'Lauf bleibt offen bis der letzte Chunk verarbeitet ist.');
        $this->assertSame(10, $run->getAttribute('counters')['processed']);
        $this->assertSame(2, $run->getAttribute('chunks'));
        $this->assertNull(SyncState::query()->firstOrFail()->getAttribute('last_success_at'), 'Cursor-Commit erst in finalize.');
    }

    public function test_final_chunk_finishes_run_and_commits_state(): void
    {
        Queue::fake();
        $connection = $this->activeConnection();
        $this->connector->page(null, new SyncResult(processed: 3, created: 2, updated: 1));

        $this->runJob(new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value, SyncMode::Incremental));

        Queue::assertNothingPushed();
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncStatus::Succeeded, $run->getAttribute('status'));
        $this->assertNotNull($run->getAttribute('finished_at'));
        $this->assertNotNull($run->getAttribute('duration_ms'));
        $this->assertSame(['processed' => 3, 'created' => 2, 'updated' => 1, 'deleted' => 0, 'failed' => 0, 'requests' => 1, 'errors' => 0], $run->getAttribute('counters'));

        $state = SyncState::query()->firstOrFail();
        $this->assertNotNull($state->getAttribute('last_success_at'));
        $this->assertNull($state->getAttribute('stale_since'));
        $this->assertSame((int) $run->getKey(), (int) $state->getAttribute('last_run_id'));
    }

    public function test_no_second_full_sync_while_lock_is_held(): void
    {
        Queue::fake();
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $lock = Cache::lock(SyncLockManager::key($connectionId, SyncEntity::Document->value), 600);
        $this->assertTrue($lock->get());

        $this->runJob(new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Full));

        $this->assertSame(0, $this->connector->requestCount(), 'Bei gehaltenem Lock darf pull() nicht aufgerufen werden.');
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncStatus::Skipped, $run->getAttribute('status'));
        $this->assertStringContainsString('Lock', (string) $run->getAttribute('error_summary'));
        $this->assertSame(1, (int) $this->app->make(SyncMetrics::class)->value(SyncMetrics::SKIPPED_LOCKED, ['connection' => $connectionId, 'entity' => 'document', 'mode' => 'full']));

        $lock->release();

        $this->runJob(new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Full));
        $this->assertSame(1, $this->connector->requestCount());
        $this->assertFalse($this->app->make(SyncLockManager::class)->isLocked($connectionId, SyncEntity::Document->value), 'Lock wird nach Abschluss freigegeben.');
    }

    public function test_full_sync_lock_is_kept_across_chunks(): void
    {
        Queue::fake();
        config()->set('hub.sync.chunks.max_per_run', 1);
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $this->connector->page(null, new SyncResult(processed: 1, cursor: 'next'));

        $this->runJob(new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Full));

        $locks = $this->app->make(SyncLockManager::class);
        $this->assertTrue($locks->isLocked($connectionId, SyncEntity::Document->value), 'Lock bleibt bis zum letzten Chunk bestehen.');
        Queue::assertPushed(RunSyncJob::class, fn (RunSyncJob $job): bool => $job->lockOwner !== null && $job->cursor === 'next');
    }

    public function test_paused_connection_is_skipped(): void
    {
        Queue::fake();
        $connection = $this->createConnection(null, ['status' => 'paused']);

        $this->runJob(new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value));

        $this->assertSame(0, $this->connector->requestCount());
        $this->assertSame(SyncStatus::Skipped, SyncRun::query()->firstOrFail()->getAttribute('status'));
    }

    public function test_record_failures_land_in_dlq_without_stopping_run(): void
    {
        Queue::fake();
        $connection = $this->activeConnection();
        $this->connector->page(null, new SyncResult(processed: 3, created: 2, failed: 1, errors: [
            ['external_id' => 'doc-42', 'cursor' => 'rec:doc-42', 'message' => 'Download fehlgeschlagen, password=geheim123'],
        ]));

        $this->runJob(new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value));

        $this->assertSame(SyncStatus::Succeeded, SyncRun::query()->firstOrFail()->getAttribute('status'));
        $item = DlqItem::query()->firstOrFail();
        $this->assertSame(RunSyncJob::class, $item->getAttribute('job_class'));
        $this->assertSame('rec:doc-42', $item->getAttribute('payload_json')['arguments']['cursor']);
        $this->assertTrue($item->getAttribute('payload_json')['arguments']['singleRecord']);
        $this->assertStringNotContainsString('geheim123', (string) $item->getAttribute('exception'));
    }

    public function test_wrapper_job_fixes_entity_type_and_redispatches_same_class(): void
    {
        Queue::fake();
        config()->set('hub.sync.chunks.max_per_run', 1);
        $connection = $this->activeConnection('carddav_contacts');
        $this->connector->page(null, new SyncResult(processed: 1, cursor: 'c1'));

        $job = new FetchImmowareContactsJob((int) $connection->getKey());
        $this->assertSame(SyncEntity::Contact->value, $job->entityType);
        $this->assertSame('sync', $job->queue);

        $this->runJob($job);

        Queue::assertPushed(FetchImmowareContactsJob::class, fn (FetchImmowareContactsJob $next): bool => $next->cursor === 'c1');
    }
}
