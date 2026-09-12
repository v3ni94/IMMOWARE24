<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Boot\BootGuard;
use App\Modules\Api\Health\HealthService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Throwable;

/**
 * System: hub:doctor als Seite (Konfiguration, Flags, Queue, DB, Redis, BootGuard), Versionen, Health-Übersicht
 * und Zeitpläne. Nur lesend.
 */
final class SystemController extends AdminController
{
    public function __construct(
        private readonly ConsoleKernel $console,
        private readonly HealthService $health,
        private readonly BootGuard $guard,
    ) {}

    public function index(Request $request): View
    {
        $this->requirePermission('connections.manage');

        return view('admin::system.index', [
            'doctor' => $this->doctorRows(),
            'flags' => $this->flags(),
            'versions' => [
                'Immoware-Connector (immoware_connector_version)' => (string) config('hub.connector.version', 'keine Angabe'),
                'Mapping-Version Kontakte (mapping_version)' => (string) config('hub.contacts.mapping_version', 'keine Angabe'),
                'Mapping-Version Kalender (mapping_version)' => (string) config('hub.calendar.mapping_version', 'keine Angabe'),
                'API-Version (api_version)' => (string) config('hub.api.version', 'keine Angabe'),
                'Laravel' => app()->version(),
                'PHP' => PHP_VERSION,
            ],
            'health' => $this->health->aggregate(),
            'schedule' => $this->scheduleRows(),
            'configuredCrons' => (array) config('hub.sync.schedule', []),
            'queues' => (array) config('hub.core.queues', []),
            'queueConnection' => (string) config('queue.default'),
            'queueDriver' => (string) config('queue.connections.'.(string) config('queue.default').'.driver', ''),
            'environment' => (string) config('app.env'),
        ]);
    }

    /**
     * @return array<int, array{check: string, status: string, detail: string}>
     */
    private function doctorRows(): array
    {
        try {
            $this->console->call('hub:doctor', ['--json' => true]);
            $decoded = json_decode(trim($this->console->output()), true);
        } catch (Throwable $e) {
            return [['check' => 'hub:doctor', 'status' => 'fail', 'detail' => 'Ausführung fehlgeschlagen: '.$e::class]];
        }

        if (! is_array($decoded)) {
            return [['check' => 'hub:doctor', 'status' => 'warn', 'detail' => 'Ausgabe konnte nicht gelesen werden.']];
        }

        $rows = [];

        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['check'], $row['status'])) {
                $rows[] = ['check' => (string) $row['check'], 'status' => (string) $row['status'], 'detail' => (string) ($row['detail'] ?? '')];
            }
        }

        return $rows;
    }

    /**
     * Schreib-Flags mit Bewertung: aktivierte Schreibpfade werden als Warnung markiert, hart gesperrte Flags
     * dürfen nie true sein (BootGuard).
     *
     * @return array<int, array{key: string, env: string, value: bool, level: string, hint: string}>
     */
    private function flags(): array
    {
        $core = (array) config('hub.core', []);
        $rows = [
            ['key' => 'read.enabled', 'env' => 'IMMOWARE_READ_ENABLED', 'value' => (bool) data_get($core, 'read.enabled'), 'level' => (bool) data_get($core, 'read.enabled') ? 'ok' : 'warn', 'hint' => 'Lesender Spiegel'],
            ['key' => 'write.enabled', 'env' => 'IMMOWARE_WRITE_ENABLED', 'value' => (bool) data_get($core, 'write.enabled'), 'level' => (bool) data_get($core, 'write.enabled') ? 'warn' : 'ok', 'hint' => 'Hauptschalter Schreibpfad'],
            ['key' => 'write.webdav_create_enabled', 'env' => 'IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED', 'value' => (bool) data_get($core, 'write.webdav_create_enabled'), 'level' => (bool) data_get($core, 'write.webdav_create_enabled') ? 'warn' : 'ok', 'hint' => 'Create-only PUT in den Posteingang'],
            ['key' => 'write.dry_run', 'env' => 'IMMOWARE_WRITE_DRY_RUN', 'value' => (bool) data_get($core, 'write.dry_run'), 'level' => 'ok', 'hint' => 'Testlauf ohne Upload'],
        ];

        foreach (BootGuard::HARD_LOCKED_FLAGS as $path => $env) {
            $value = (bool) data_get($core, $path);
            $rows[] = ['key' => $path, 'env' => $env, 'value' => $value, 'level' => $value ? 'fail' : 'ok', 'hint' => 'hart gesperrt, true verhindert den Start'];
        }

        $rows[] = ['key' => 'boot_guard', 'env' => 'HUB_BOOT_GUARD', 'value' => (bool) config('hub.core.boot_guard', true), 'level' => (bool) config('hub.core.boot_guard', true) ? ($this->guard->violations($core) === [] ? 'ok' : 'fail') : 'warn', 'hint' => 'BootGuard prüft die gesperrten Flags beim Start'];
        $rows[] = ['key' => 'webhooks.enabled', 'env' => 'HUB_WEBHOOKS_ENABLED', 'value' => (bool) config('hub.webhooks.enabled', false), 'level' => 'ok', 'hint' => 'Ausgehende Webhooks'];
        $rows[] = ['key' => 'api_keys.enabled', 'env' => 'HUB_API_KEYS_ENABLED', 'value' => (bool) config('hub.core.api_keys.enabled', false), 'level' => 'ok', 'hint' => 'API-Key-Authentifizierung'];

        return $rows;
    }

    /**
     * Zeitpläne, die im aktuellen Prozess registriert sind. Modul-Provider registrieren ihre Zeitpläne nur im
     * Konsolenkontext, deshalb kann die Liste im Web-Request leer sein; php artisan schedule:list ist maßgeblich.
     *
     * @return array<int, array{command: string, expression: string, description: string}>
     */
    private function scheduleRows(): array
    {
        try {
            $schedule = app(Schedule::class);
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($schedule->events() as $event) {
            $command = is_string($event->command) ? trim((string) preg_replace('/^.*artisan\S*\s+/', '', $event->command)) : ($event->description ?? 'Closure');
            $rows[] = ['command' => $command !== '' ? $command : 'Closure', 'expression' => $event->expression, 'description' => (string) ($event->description ?? '')];
        }

        return $rows;
    }
}
