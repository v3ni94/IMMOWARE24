<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Sync\Jobs\WorkerHeartbeatJob;
use App\Modules\Sync\Support\Heartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class WorkerHeartbeatTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([Heartbeat::WORKER, Heartbeat::SCHEDULER] as $name) {
            @unlink(Heartbeat::path($name));
        }

        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_command_writes_scheduler_heartbeat_and_queues_worker_job_on_high(): void
    {
        Queue::fake();
        @unlink(Heartbeat::path(Heartbeat::SCHEDULER));

        $this->artisan('hub:worker:heartbeat')->assertSuccessful();

        $this->assertFileExists(Heartbeat::path(Heartbeat::SCHEDULER));
        Queue::assertPushedOn('high', WorkerHeartbeatJob::class);
        $this->assertTrue($this->app->make(Heartbeat::class)->isFresh(Heartbeat::SCHEDULER));
    }

    public function test_worker_job_writes_worker_heartbeat_and_check_reports_age(): void
    {
        @unlink(Heartbeat::path(Heartbeat::WORKER));

        $this->artisan('hub:heartbeat:check', ['name' => 'worker'])->assertFailed();

        (new WorkerHeartbeatJob)->handle($this->app->make(Heartbeat::class));

        $this->assertFileExists(Heartbeat::path(Heartbeat::WORKER));
        $this->artisan('hub:heartbeat:check', ['name' => 'worker', '--max-age' => 180])->assertSuccessful();

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(181));
        $this->artisan('hub:heartbeat:check', ['name' => 'worker', '--max-age' => 180])->assertFailed();
        $this->artisan('hub:heartbeat:check', ['name' => 'unbekannt'])->assertExitCode(2);
    }
}
