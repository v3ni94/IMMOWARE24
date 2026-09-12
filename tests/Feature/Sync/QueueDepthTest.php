<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Sync\Schedule\SyncSchedule;
use App\Modules\Sync\Services\QueueDepthProbe;
use App\Modules\Sync\Services\SyncMetrics;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

final class QueueDepthTest extends SyncTestCase
{
    public function test_command_sets_gauge_per_queue_from_jobs_table_when_driver_is_not_redis(): void
    {
        $this->app->make(SyncMetrics::class)->reset();
        config()->set('queue.default', 'database');
        $now = time();
        DB::table('jobs')->insert([
            ['queue' => 'sync', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'sync', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'documents', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
            ['queue' => 'mail-high', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => $now, 'created_at' => $now],
        ]);

        $this->assertSame(0, Artisan::call('hub:sync:queue-depth', ['--json' => true]));
        $json = json_decode(Artisan::output(), true);

        $this->assertSame(QueueDepthProbe::SOURCE_DATABASE, $json['source']);
        $this->assertSame(2, $json['by_queue']['sync']);
        $this->assertSame(1, $json['by_queue']['documents']);
        $this->assertSame(0, $json['by_queue']['write']);
        $this->assertSame(3, $json['total']);

        $metrics = $this->app->make(SyncMetrics::class);
        $this->assertSame(2, $metrics->value(SyncMetrics::QUEUE_DEPTH, ['queue' => 'sync']));
        $this->assertSame(3, $metrics->summary()[SyncMetrics::QUEUE_DEPTH]);
    }

    public function test_redis_failure_falls_back_to_database_without_exception(): void
    {
        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('database.redis.default.host', '127.0.0.1');
        config()->set('database.redis.default.port', 1);
        config()->set('database.redis.default.timeout', 0.1);

        $result = $this->app->make(QueueDepthProbe::class)->measure();

        $this->assertSame(QueueDepthProbe::SOURCE_DATABASE, $result['source']);
        $this->assertSame(0, $result['total']);
    }

    public function test_schedule_registers_queue_depth_every_minute(): void
    {
        $schedule = new Schedule;
        (new SyncSchedule)->register($schedule);

        $event = collect($schedule->events())->first(fn ($e): bool => $e->description === SyncSchedule::DESCRIPTION_PREFIX.'queue depth');

        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertStringContainsString('hub:sync:queue-depth', $event->command);
    }
}
