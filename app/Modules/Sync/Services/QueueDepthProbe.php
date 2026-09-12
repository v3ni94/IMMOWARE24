<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Misst die Queue-Tiefe je Queue aus hub.core.queues und setzt das Gauge queue_depth (Label queue).
 * Redis-Treiber: LLEN der Liste plus ZCARD der delayed- und reserved-Mengen über die Redis-Connection der
 * Queue-Connection. Datenbank-Treiber oder Redis nicht erreichbar: Zählung in der Tabelle jobs je Queue.
 * Läuft minütlich im Scheduler (SyncSchedule), das Dashboard liest ausschließlich das Gauge.
 */
final class QueueDepthProbe
{
    public const string SOURCE_REDIS = 'redis';

    public const string SOURCE_DATABASE = 'database';

    public const string SOURCE_NONE = 'none';

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly RedisFactory $redis,
        private readonly SyncMetrics $metrics,
    ) {}

    /**
     * @return array{source: string, by_queue: array<string, int>, total: int}
     */
    public function measure(): array
    {
        $queues = $this->queues();
        $connection = (string) $this->config->get('queue.default');
        $driver = (string) $this->config->get('queue.connections.'.$connection.'.driver', $connection);

        if ($driver === 'redis') {
            try {
                return $this->fromRedis($connection, $queues);
            } catch (Throwable $e) {
                Log::warning('Queue-Tiefe: Redis nicht erreichbar, Rückfall auf Tabelle jobs.', ['error_class' => $e::class]);
            }
        }

        return $this->fromDatabase($queues);
    }

    /**
     * Misst und schreibt das Gauge je Queue.
     *
     * @return array{source: string, by_queue: array<string, int>, total: int}
     */
    public function record(): array
    {
        $result = $this->measure();

        foreach ($result['by_queue'] as $queue => $depth) {
            $this->metrics->gauge(SyncMetrics::QUEUE_DEPTH, $depth, ['queue' => $queue]);
        }

        return $result;
    }

    /**
     * @param  array<int, string>  $queues
     * @return array{source: string, by_queue: array<string, int>, total: int}
     */
    private function fromRedis(string $connection, array $queues): array
    {
        $client = $this->redis->connection((string) $this->config->get('queue.connections.'.$connection.'.connection', 'default'));
        $prefix = (string) $this->config->get('database.redis.options.prefix', '');
        $byQueue = [];
        $total = 0;

        foreach ($queues as $queue) {
            $key = $prefix.'queues:'.$queue;
            $depth = (int) $client->command('llen', [$key])
                + (int) $client->command('zcard', [$key.':delayed'])
                + (int) $client->command('zcard', [$key.':reserved']);
            $byQueue[$queue] = $depth;
            $total += $depth;
        }

        return ['source' => self::SOURCE_REDIS, 'by_queue' => $byQueue, 'total' => $total];
    }

    /**
     * @param  array<int, string>  $queues
     * @return array{source: string, by_queue: array<string, int>, total: int}
     */
    private function fromDatabase(array $queues): array
    {
        $byQueue = array_fill_keys($queues, 0);

        if (! Schema::hasTable('jobs')) {
            return ['source' => self::SOURCE_NONE, 'by_queue' => $byQueue, 'total' => 0];
        }

        $rows = DB::table('jobs')->selectRaw('queue, COUNT(*) AS aggregate')->whereIn('queue', $queues)->groupBy('queue')->get();

        foreach ($rows as $row) {
            $byQueue[(string) $row->queue] = (int) $row->aggregate;
        }

        return ['source' => self::SOURCE_DATABASE, 'by_queue' => $byQueue, 'total' => (int) array_sum($byQueue)];
    }

    /**
     * @return array<int, string>
     */
    private function queues(): array
    {
        $configured = array_map('strval', array_values((array) $this->config->get('hub.core.queues', [])));

        return array_values(array_unique($configured === [] ? ['default'] : $configured));
    }
}
