<?php

declare(strict_types=1);

namespace Tests\Contract;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Startet tests/mock-immoware/server.php über den PHP-Built-in-Server auf einem freien Port
 * und wartet, bis /__mock/health antwortet. Für setUpBeforeClass und tearDownAfterClass.
 */
final class MockServerProcess
{
    public const string USER = 'hub-read';

    public const string PASSWORD = 'mock-secret';

    private ?Process $process = null;

    private int $port = 0;

    private string $runtimeDir = '';

    /**
     * @param  array<string, string>  $env  zusätzliche Umgebungsvariablen für den Mock (z. B. MOCK_TIMEOUT_SLEEP)
     */
    public function start(array $env = [], float $timeoutSeconds = 10.0): void
    {
        $this->port = self::freePort();
        $this->runtimeDir = sys_get_temp_dir().'/immoware-hub-mock-'.$this->port.'-'.bin2hex(random_bytes(4));

        $router = dirname(__DIR__).'/mock-immoware/server.php';

        $this->process = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:'.$this->port, $router],
            dirname(__DIR__, 2),
            [
                'MOCK_DAV_USER' => self::USER,
                'MOCK_DAV_PASS' => self::PASSWORD,
                'MOCK_RUNTIME_DIR' => $this->runtimeDir,
                'MOCK_TIMEOUT_SLEEP' => '3',
                'MOCK_SLOW_SLEEP' => '2',
                ...$env,
            ],
        );
        $this->process->setTimeout(null);
        $this->process->start();

        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            if (! $this->process->isRunning()) {
                throw new RuntimeException('Mock-Server konnte nicht gestartet werden: '.$this->process->getErrorOutput().$this->process->getOutput());
            }

            if ($this->healthy()) {
                return;
            }

            usleep(100_000);
        }

        $this->stop();

        throw new RuntimeException(sprintf('Mock-Server auf Port %d antwortete nicht innerhalb von %.1f s.', $this->port, $timeoutSeconds));
    }

    public function stop(): void
    {
        if ($this->process !== null && $this->process->isRunning()) {
            $this->process->stop(3);
        }

        $this->process = null;

        if ($this->runtimeDir !== '' && is_dir($this->runtimeDir)) {
            self::removeTree($this->runtimeDir);
        }
    }

    public function isRunning(): bool
    {
        return $this->process !== null && $this->process->isRunning();
    }

    public function port(): int
    {
        return $this->port;
    }

    /** Basis-URL ohne Pfad, z. B. http://127.0.0.1:8123 */
    public function origin(): string
    {
        return 'http://127.0.0.1:'.$this->port;
    }

    /** Basis-URL des DAV-Adapters, z. B. http://127.0.0.1:8123/dav */
    public function davBaseUrl(): string
    {
        return $this->origin().'/dav';
    }

    /** DAV-Basis-URL mit Szenario-Pfadpräfix, z. B. http://127.0.0.1:8123/s/ratelimited/dav */
    public function scenarioBaseUrl(string $scenario): string
    {
        return $this->origin().'/s/'.$scenario.'/dav';
    }

    public function reset(): void
    {
        $this->control('/__mock/reset');
    }

    /**
     * Protokoll aller Requests seit dem letzten Reset.
     *
     * @return array<int, array<string, mixed>>
     */
    public function log(): array
    {
        $decoded = json_decode($this->control('/__mock/log'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, array<string, mixed>> Einträge mit gesetztem violation
     */
    public function violations(): array
    {
        return array_values(array_filter($this->log(), static fn (array $entry): bool => ($entry['violation'] ?? null) !== null));
    }

    private function healthy(): bool
    {
        try {
            return str_contains($this->control('/__mock/health', 1), '"ok":true');
        } catch (RuntimeException) {
            return false;
        }
    }

    private function control(string $path, int $timeout = 5): string
    {
        $context = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true]]);
        $body = @file_get_contents($this->origin().$path, false, $context);

        if ($body === false) {
            throw new RuntimeException('Mock-Steuerpfad nicht erreichbar: '.$path);
        }

        return $body;
    }

    public static function freePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException('Kein freier Port: '.$errstr);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        $port = is_string($name) ? (int) substr($name, (int) strrpos($name, ':') + 1) : 0;

        if ($port <= 0) {
            throw new RuntimeException('Freier Port konnte nicht bestimmt werden.');
        }

        return $port;
    }

    private static function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $path = $dir.'/'.$name;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
