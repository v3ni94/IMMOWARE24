<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Jobs\ProcessDlqRetryJob;
use App\Modules\Sync\Jobs\RunSyncJob;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Services\DlqService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

final class DlqCommandsTest extends SyncTestCase
{
    public function test_list_shows_open_and_failed_by_default_and_filters_by_status(): void
    {
        $connectionId = (int) $this->activeConnection()->getKey();
        $open = $this->store($connectionId, 'PROPFIND fehlgeschlagen für https://hub-read:S3cret@dav.example.test/');
        $ignored = $this->store($connectionId, 'irrelevant');
        $this->app->make(DlqService::class)->ignore((int) $ignored->getKey(), null, 'Test');

        $this->assertSame(0, Artisan::call('hub:dlq:list', ['--json' => true]));
        $json = json_decode(Artisan::output(), true);
        $this->assertSame(1, $json['meta']['total']);
        $this->assertSame((int) $open->getKey(), $json['data'][0]['id']);
        $this->assertStringNotContainsString('S3cret', json_encode($json, JSON_THROW_ON_ERROR));

        $this->assertSame(0, Artisan::call('hub:dlq:list', ['--status' => ['ignored']]));
        $this->assertStringContainsString('RunSyncJob', Artisan::output());

        $this->assertSame(1, Artisan::call('hub:dlq:list', ['--status' => ['weird']]));
    }

    public function test_retry_single_dispatches_replay_job_and_audits(): void
    {
        Queue::fake();
        $item = $this->store((int) $this->activeConnection()->getKey(), 'Timeout');

        $this->assertSame(0, Artisan::call('hub:dlq:retry', ['id' => (int) $item->getKey()]));
        Queue::assertPushed(ProcessDlqRetryJob::class, 1);
        $this->assertSame(DlqStatus::Retrying, $item->fresh()->getAttribute('status'));
        $this->assertSame(1, AuditLog::query()->where('action', 'dlq.retry_requested')->count());

        $this->assertSame(1, Artisan::call('hub:dlq:retry', ['id' => 999999]));
        $this->assertSame(1, Artisan::call('hub:dlq:retry', []));
        $this->assertSame(1, Artisan::call('hub:dlq:retry', ['id' => 1, '--all-failed' => true]));
    }

    public function test_retry_all_failed_skips_write_queue_and_ignored_items(): void
    {
        Queue::fake();
        $connectionId = (int) $this->activeConnection()->getKey();
        $open = $this->store($connectionId, 'a');
        $failed = $this->store($connectionId, 'b');
        $failed->forceFill(['status' => DlqStatus::Failed])->save();
        $write = $this->store($connectionId, 'c');
        $write->forceFill(['queue' => 'write'])->save();
        $ignored = $this->store($connectionId, 'd');
        $this->app->make(DlqService::class)->ignore((int) $ignored->getKey(), null, 'Test');

        $this->assertSame(0, Artisan::call('hub:dlq:retry', ['--all-failed' => true, '--dry-run' => true]));
        Queue::assertNothingPushed();
        $this->assertStringContainsString('2 würden eingeplant, 1 write übersprungen', Artisan::output());

        $this->assertSame(0, Artisan::call('hub:dlq:retry', ['--all-failed' => true]));
        Queue::assertPushed(ProcessDlqRetryJob::class, 2);
        $this->assertSame(DlqStatus::Retrying, $open->fresh()->getAttribute('status'));
        $this->assertSame(DlqStatus::Retrying, $failed->fresh()->getAttribute('status'));
        $this->assertSame(DlqStatus::Open, $write->fresh()->getAttribute('status'));
        $this->assertSame(DlqStatus::Ignored, $ignored->fresh()->getAttribute('status'));

        $this->assertSame(0, Artisan::call('hub:dlq:retry', ['--all-failed' => true, '--include-write' => true]));
        $this->assertSame(DlqStatus::Retrying, $write->fresh()->getAttribute('status'));
    }

    public function test_ignore_requires_reason_and_marks_item(): void
    {
        $item = $this->store((int) $this->activeConnection()->getKey(), 'x');

        $this->assertSame(1, Artisan::call('hub:dlq:ignore', ['id' => (int) $item->getKey()]));
        $this->assertSame(DlqStatus::Open, $item->fresh()->getAttribute('status'));

        $this->assertSame(0, Artisan::call('hub:dlq:ignore', ['id' => (int) $item->getKey(), '--reason' => 'Ticket 4711, Datei manuell importiert']));
        $this->assertSame(DlqStatus::Ignored, $item->fresh()->getAttribute('status'));
        $this->assertStringStartsWith('ignored: Ticket 4711', (string) $item->fresh()->getAttribute('replay_result'));
        $this->assertSame(1, AuditLog::query()->where('action', 'dlq.ignored')->count());

        $this->assertSame(1, Artisan::call('hub:dlq:ignore', ['id' => 999999, '--reason' => 'x']));
    }

    private function store(int $connectionId, string $message): DlqItem
    {
        return $this->app->make(DlqService::class)->store(
            RunSyncJob::class,
            ['connectionId' => $connectionId, 'entityType' => SyncEntity::Document->value, 'mode' => 'incremental'],
            new RuntimeException($message),
            $connectionId,
            SyncEntity::Document->value,
            'sync',
        );
    }
}
