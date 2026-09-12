<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\DTO\SyncResult;
use App\Core\Enums\Role;
use App\Core\Enums\SyncMode;
use App\Core\Exceptions\ConnectorException;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\ProcessDlqRetryJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Services\DlqService;
use App\Modules\Sync\Support\SyncLockManager;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

final class DlqTest extends SyncTestCase
{
    public function test_exhausted_job_lands_in_dlq_with_masked_exception_and_releases_lock(): void
    {
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $this->connector->failWith(new ConnectorException('PROPFIND fehlgeschlagen für https://hub-read:S3cretPass@dav.example.test/ (Authorization: Basic aHViOnBhc3M=)'));

        $job = new RunSyncJob($connectionId, SyncEntity::Document->value, SyncMode::Full);

        try {
            // Sync-Treiber: Bei Exception wird failed() aufgerufen und die Exception erneut geworfen.
            Queue::connection('sync')->push($job);
            $this->fail('Exception erwartet.');
        } catch (Throwable $exception) {
            $this->assertInstanceOf(ConnectorException::class, $exception);
        }

        $item = DlqItem::query()->firstOrFail();
        $this->assertSame(RunSyncJob::class, $item->getAttribute('job_class'));
        $this->assertSame($connectionId, (int) $item->getAttribute('connection_id'));
        $this->assertSame(DlqStatus::Open, $item->getAttribute('status'));
        $this->assertStringContainsString('ConnectorException', (string) $item->getAttribute('exception'));
        $this->assertStringNotContainsString('S3cretPass', (string) $item->getAttribute('exception'));
        $this->assertStringNotContainsString('aHViOnBhc3M=', (string) $item->getAttribute('exception'));
        $this->assertSame('full', $item->getAttribute('payload_json')['arguments']['mode']);

        $this->assertFalse($this->app->make(SyncLockManager::class)->isLocked($connectionId, SyncEntity::Document->value));
        $this->assertSame('failed', SyncRun::query()->firstOrFail()->getAttribute('status')->value);
    }

    public function test_retry_reprocesses_single_record_and_marks_item_replayed(): void
    {
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $user = $this->actingAsRole(Role::Operator, $connection->organization);

        $this->connector->page('rec:doc-42', new SyncResult(processed: 1, updated: 1));

        /** @var DlqService $dlq */
        $dlq = $this->app->make(DlqService::class);
        $item = $dlq->storeRecordFailure(RunSyncJob::class, [
            'connectionId' => $connectionId,
            'entityType' => SyncEntity::Document->value,
            'mode' => 'incremental',
            'cursor' => 'rec:doc-42',
            'limit' => 1,
            'singleRecord' => true,
        ], ['external_id' => 'doc-42', 'message' => 'Timeout'], $connectionId, SyncEntity::Document->value);

        Queue::fake();
        $dlq->retry((int) $item->getKey(), $user);
        Queue::assertPushed(ProcessDlqRetryJob::class, fn (ProcessDlqRetryJob $job): bool => $job->dlqItemId === (int) $item->getKey());
        $this->assertSame(DlqStatus::Retrying, $item->fresh()->getAttribute('status'));

        $this->runJob(new ProcessDlqRetryJob((int) $item->getKey(), (int) $user->getKey()));

        $this->assertSame(1, $this->connector->requestCount());
        $request = $this->connector->lastRequest();
        $this->assertNotNull($request);
        $this->assertSame('rec:doc-42', $request->cursor);
        $this->assertSame(1, $request->limit);

        $item = $item->fresh();
        $this->assertSame(DlqStatus::Replayed, $item->getAttribute('status'));
        $this->assertSame('succeeded', $item->getAttribute('replay_result'));
        $this->assertNotNull($item->getAttribute('replayed_at'));
        $this->assertSame((int) $user->getKey(), (int) $item->getAttribute('replayed_by'));
        $this->assertSame(SyncRun::TYPE_REPLAY, SyncRun::query()->firstOrFail()->getAttribute('run_type'));
        $this->assertTrue(AuditLog::query()->where('action', 'dlq.retry_requested')->exists());
    }

    public function test_failed_retry_keeps_item_in_dlq(): void
    {
        $connection = $this->activeConnection();
        $this->connector->failWith(new RuntimeException('immer noch kaputt'));

        /** @var DlqService $dlq */
        $dlq = $this->app->make(DlqService::class);
        $item = $dlq->store(RunSyncJob::class, ['connectionId' => (int) $connection->getKey(), 'entityType' => 'document', 'mode' => 'incremental', 'singleRecord' => true, 'limit' => 1], new RuntimeException('erster Fehler'), (int) $connection->getKey(), 'document');

        $this->runJob(new ProcessDlqRetryJob((int) $item->getKey()));

        $item = $item->fresh();
        $this->assertSame(DlqStatus::Failed, $item->getAttribute('status'));
        $this->assertSame('failed', $item->getAttribute('replay_result'));
        $this->assertSame(1, $item->getAttribute('attempts'));
        $this->assertStringContainsString('immer noch kaputt', (string) $item->getAttribute('exception'));
    }

    public function test_ignore_and_payload_mask_secrets(): void
    {
        $connection = $this->activeConnection();
        $user = $this->actingAsRole(Role::Operator, $connection->organization);

        /** @var DlqService $dlq */
        $dlq = $this->app->make(DlqService::class);
        $item = $dlq->store(RunSyncJob::class, ['connectionId' => 1, 'entityType' => 'document', 'password' => 'geheim', 'token' => 'abc'], new RuntimeException('x'), (int) $connection->getKey());

        $payload = $dlq->payload((int) $item->getKey());
        $this->assertSame('***', $payload['arguments']['password']);
        $this->assertSame('***', $payload['arguments']['token']);

        $dlq->ignore((int) $item->getKey(), $user, 'Duplikat');
        $this->assertSame(DlqStatus::Ignored, $item->fresh()->getAttribute('status'));
        $this->assertTrue(AuditLog::query()->where('action', 'dlq.ignored')->exists());

        $this->expectException(\InvalidArgumentException::class);
        $dlq->retry((int) $item->getKey(), $user);
    }
}
