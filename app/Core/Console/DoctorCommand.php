<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Boot\BootGuard;
use App\Modules\Connector\Services\ConnectorManager;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * hub:doctor: Statusbericht zu Konfiguration, Schreib-Flags, Queue, Datenbank, Redis, BootGuard und Adaptern.
 * Bricht nie ab, jeder Prüfpunkt meldet ok, warn oder fail. Exit-Code 1 nur bei fail.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'hub:doctor {--json : Ausgabe als JSON}';

    protected $description = 'Prüft Konfiguration, Feature-Flags, Queue, Datenbank, Redis und BootGuard des Hubs.';

    /** @var array<int, array{check: string, status: string, detail: string}> */
    private array $rows = [];

    public function handle(ConfigRepository $config, BootGuard $guard, ConnectorManager $connectors): int
    {
        $this->rows = [];

        $this->checkEnvironment($config);
        $this->checkWriteFlags($config, $guard);
        $this->checkDatabase();
        $this->checkQueue($config);
        $this->checkRedis($config);
        $this->checkAdapters($connectors);
        $this->checkImports($config);

        if ($this->option('json')) {
            $this->line((string) json_encode($this->rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Prüfung', 'Status', 'Detail'], array_map(static fn (array $r): array => [$r['check'], strtoupper($r['status']), $r['detail']], $this->rows));
        }

        $failed = array_filter($this->rows, static fn (array $r): bool => $r['status'] === 'fail');

        return $failed === [] ? self::SUCCESS : self::FAILURE;
    }

    private function checkEnvironment(ConfigRepository $config): void
    {
        $env = (string) $config->get('app.env');
        $debug = (bool) $config->get('app.debug');
        $this->add('app.env', 'ok', $env.($debug ? ', debug an' : ''));

        if ($env === 'production' && $debug) {
            $this->add('app.debug', 'fail', 'APP_DEBUG darf in Produktion nicht aktiv sein.');
        }

        $this->add('app.url', (string) $config->get('app.url') !== '' ? 'ok' : 'warn', (string) $config->get('app.url'));
        $this->add('hub.read.enabled', (bool) $config->get('hub.core.read.enabled') ? 'ok' : 'warn', $this->bool($config->get('hub.core.read.enabled')));
        $this->add('hub.api_keys.enabled', 'ok', $this->bool($config->get('hub.core.api_keys.enabled')));
        $this->add('hub.webhooks.enabled', 'ok', $this->bool($config->get('hub.webhooks.enabled')));
        $this->add('hub.hash_pepper', $config->get('hub.security.hashing.pepper') !== null ? 'ok' : 'warn', $config->get('hub.security.hashing.pepper') !== null ? 'gesetzt' : 'HUB_HASH_PEPPER fehlt, Fallback APP_KEY');
    }

    private function checkWriteFlags(ConfigRepository $config, BootGuard $guard): void
    {
        $core = (array) $config->get('hub.core', []);
        $this->add('write.enabled', 'ok', $this->bool(data_get($core, 'write.enabled')));
        $this->add('write.webdav_create_enabled', 'ok', $this->bool(data_get($core, 'write.webdav_create_enabled')));
        $this->add('write.dry_run', 'ok', $this->bool(data_get($core, 'write.dry_run')));

        foreach (BootGuard::HARD_LOCKED_FLAGS as $path => $env) {
            $value = data_get($core, $path);
            $this->add($env, $value ? 'fail' : 'ok', $value ? 'true, hart gesperrt, Anwendung startet nicht' : 'false (gesperrt)');
        }

        $violations = $guard->violations($core);
        $this->add('boot_guard', (bool) $config->get('hub.core.boot_guard', true) ? ($violations === [] ? 'ok' : 'fail') : 'warn',
            (bool) $config->get('hub.core.boot_guard', true) ? ($violations === [] ? 'aktiv, keine Verletzung' : 'Verletzungen: '.implode(', ', $violations)) : 'deaktiviert (HUB_BOOT_GUARD=false)');
    }

    private function checkDatabase(): void
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
            $driver = $connection->getDriverName();
            $migrations = $connection->getSchemaBuilder()->hasTable('migrations') ? (int) $connection->table('migrations')->count() : 0;
            $this->add('database', 'ok', sprintf('%s, %d Migrationen', $driver, $migrations));
        } catch (Throwable $e) {
            $this->add('database', 'fail', 'Nicht erreichbar: '.$e::class);
        }
    }

    private function checkQueue(ConfigRepository $config): void
    {
        $connection = (string) $config->get('queue.default');
        $driver = (string) $config->get('queue.connections.'.$connection.'.driver', '');
        $status = $driver === 'sync' && (string) $config->get('app.env') === 'production' ? 'warn' : 'ok';
        $this->add('queue', $status, sprintf('%s (%s), Queues: %s', $connection, $driver, implode(', ', array_values((array) $config->get('hub.core.queues', [])))));
    }

    private function checkRedis(ConfigRepository $config): void
    {
        $needsRedis = in_array('redis', [
            (string) $config->get('queue.connections.'.(string) $config->get('queue.default').'.driver'),
            (string) $config->get('cache.stores.'.(string) $config->get('cache.default').'.driver'),
            (string) $config->get('session.driver'),
        ], true);

        try {
            $pong = Redis::connection()->command('ping');
            $this->add('redis', 'ok', is_string($pong) ? $pong : 'PONG');
        } catch (Throwable $e) {
            $this->add('redis', $needsRedis ? 'fail' : 'warn', 'Nicht erreichbar ('.$e::class.')'.($needsRedis ? ', aber für Queue, Cache oder Session konfiguriert' : ', nicht konfiguriert'));
        }
    }

    private function checkAdapters(ConnectorManager $connectors): void
    {
        $registered = $connectors->registeredNames();
        $missing = array_diff($connectors->knownNames(), $registered);
        $this->add('connector.adapters', $missing === [] ? 'ok' : 'warn', implode(', ', $registered).($missing !== [] ? ', fehlt: '.implode(', ', $missing) : ''));
    }

    private function checkImports(ConfigRepository $config): void
    {
        $path = (string) $config->get('hub.imports.drop_path', $config->get('hub.core.imports.drop_path'));

        if ($path === '') {
            $this->add('imports.drop_path', 'warn', 'HUB_IMPORT_DROP_PATH nicht gesetzt');

            return;
        }

        $this->add('imports.drop_path', is_dir($path) ? 'ok' : 'warn', is_dir($path) ? $path : $path.' (nicht vorhanden)');
    }

    private function add(string $check, string $status, string $detail): void
    {
        $this->rows[] = ['check' => $check, 'status' => $status, 'detail' => $detail];
    }

    private function bool(mixed $value): string
    {
        return $value ? 'true' : 'false';
    }
}
