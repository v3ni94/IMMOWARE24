<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Core\Exceptions\ConnectorException;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\FetchImmowareContactsJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Models\SyncState;
use App\Modules\Sync\Services\SyncMetrics;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Queue\Jobs\SyncJob;
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

    public function test_unchanged_cursor_aborts_run_and_lands_in_dlq(): void
    {
        Queue::fake();
        $connection = $this->activeConnection();
        $this->connector
            ->page(null, new SyncResult(processed: 1, cursor: 'same'))
            ->page('same', new SyncResult(processed: 1, cursor: 'same'));

        $this->runJob(new RunSyncJob((int) $connection->getKey(), SyncEntity::Document->value, SyncMode::Incremental));

        Queue::assertNothingPushed();
        $this->assertSame(2, $this->connector->requestCount());
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncStatus::Failed, $run->getAttribute('status'));
        $this->assertStringContainsString('unveränderten Cursor', (string) $run->getAttribute('error_summary'));
        $this->assertSame(1, DlqItem::query()->count());
        $this->assertNull(SyncState::query()->firstOrFail()->getAttribute('last_success_at'));
    }

    public function test_incremental_run_is_skipped_while_lock_of_same_connection_and_entity_is_held(): void
    {
        Queue::fake();
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $lock = Cache::lock(SyncLockManager::key($connectionId, SyncEntity::Document->value), 600);
        $this->assertTrue($lock->get());

        // 07-sync-strategy.md Abschnitt 5: der Lock je Connection und Adapter schließt auch Incremental neben Full aus.
        $this->runJob(new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Incremental));

        $this->assertSame(0, $this->connector->requestCount());
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncStatus::Skipped, $run->getAttribute('status'));
        $this->assertStringContainsString('Lock', (string) $run->getAttribute('error_summary'));

        // Anderer Adapter derselben Connection ist nicht betroffen.
        $this->runJob(new RunSyncJob($connectionId, SyncEntity::Contact->value, SyncMode::Incremental));
        $this->assertSame(1, $this->connector->requestCount());
    }

    public function test_lock_loss_between_chunks_aborts_run_without_cursor_commit(): void
    {
        Queue::fake();
        config()->set('hub.sync.chunks.max_per_run', 5);
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $key = SyncLockManager::key($connectionId, SyncEntity::Document->value);
        $store = Cache::getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);

        // Nach dem ersten Chunk geht der Lock verloren (TTL abgelaufen) und ein fremder Lauf übernimmt ihn.
        $this->connector
            ->page(null, new SyncResult(processed: 1, cursor: 'c1'))
            ->page('c1', new SyncResult(processed: 1))
            ->afterPull(function () use ($store, $key): void {
                unset($store->locks[$key]);
                $this->assertTrue(Cache::lock($key, 600, 'fremd')->get());
            });

        $this->runJob(new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Incremental));

        Queue::assertNothingPushed();
        $this->assertSame(1, $this->connector->requestCount(), 'Nach Lock-Verlust kein weiterer Chunk.');
        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncStatus::Aborted, $run->getAttribute('status'));
        $this->assertSame('aborted', $run->getAttribute('phase'));
        $this->assertNull(SyncState::query()->firstOrFail()->getAttribute('last_success_at'), 'kein Cursor-Commit nach Lock-Verlust');
        $this->assertSame('fremd', $store->locks[$key]['owner'], 'fremder Lock bleibt unangetastet');
    }

    public function test_transient_exception_keeps_lock_for_retry_and_same_job_resumes_it(): void
    {
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $locks = $this->app->make(SyncLockManager::class);
        $this->connector->failWith(new ConnectorException('503 vorübergehend'));

        $job = new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Full);
        $queueJob = new SyncJob($this->app, json_encode(['uuid' => 'job-uuid-1', 'attempts' => 1], JSON_THROW_ON_ERROR), 'sync', 'sync');
        $job->setJob($queueJob);

        try {
            $this->runJob($job);
            $this->fail('Exception erwartet.');
        } catch (ConnectorException) {
            $this->addToAssertionCount(1);
        }

        // Kein Lock-Release bei transienter Exception: der Retry desselben Jobs nimmt ihn wieder auf ...
        $this->assertTrue($locks->isLocked($connectionId, SyncEntity::Document->value));
        $this->assertTrue($locks->acquireOrResume($connectionId, SyncEntity::Document->value, SyncLockManager::ownerForJob('job-uuid-1')));
        // ... ein fremder Lauf nicht.
        $this->assertFalse($locks->acquireOrResume($connectionId, SyncEntity::Document->value, 'anderer-lauf'));

        // Erst das endgültige Scheitern gibt ihn frei.
        $job->failed(new ConnectorException('endgültig'));
        $this->assertFalse($locks->isLocked($connectionId, SyncEntity::Document->value));
    }

    public function test_lock_ttl_is_at_least_twice_the_job_timeout_and_renewable(): void
    {
        config()->set('hub.sync.jobs.timeout_seconds', 5000);
        config()->set('hub.sync.locks.full_ttl_seconds', 60);
        $locks = $this->app->make(SyncLockManager::class);

        $this->assertSame(10000, $locks->defaultTtl());
        $this->assertNotNull($owner = $locks->acquire(1, 'document'));
        $this->assertTrue($locks->renew(1, 'document', $owner));
        $this->assertFalse($locks->renew(1, 'document', 'fremd'));
        $locks->release(1, 'document', $owner);
        $this->assertFalse($locks->isLocked(1, 'document'));
    }
}
