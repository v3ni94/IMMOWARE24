<?php

declare(strict_types=1);

namespace App\Modules\Connector\Testing\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Komfort-Wrapper für den Mock-Immoware-Server (tests/mock-immoware/server.php) über den PHP-Built-in-Server.
 * Nur in den Umgebungen local und testing verfügbar; in Produktion beendet sich der Befehl ohne Wirkung.
 */
final class MockImmowareServeCommand extends Command
{
    protected $signature = 'hub:mock-immoware:serve
        {--host=127.0.0.1 : Bind-Adresse}
        {--port=8089 : Port}
        {--user= : Benutzername für Basic Auth (Standard MOCK_DAV_USER oder hub-read)}
        {--password= : Passwort für Basic Auth (Standard MOCK_DAV_PASS oder mock-secret)}
        {--runtime-dir= : Verzeichnis für Uploads, Protokoll und Zähler}
        {--timeout-sleep=35 : Wartezeit in Sekunden im Szenario timeout}';

    protected $description = 'Startet den Mock-Immoware24-DAV-Server (WebDAV, CardDAV, CalDAV mit Fehlerszenarien) für lokale Tests.';

    public function handle(): int
    {
        if (! $this->laravel->environment(['local', 'testing'])) {
            $this->error('hub:mock-immoware:serve ist nur in den Umgebungen local und testing zulässig.');

            return self::FAILURE;
        }

        $router = base_path('tests/mock-immoware/server.php');

        if (! is_file($router)) {
            $this->error('Mock-Server nicht gefunden: '.$router);

            return self::FAILURE;
        }

        $host = (string) $this->option('host');
        $port = (int) $this->option('port');
        $user = (string) ($this->option('user') ?: (getenv('MOCK_DAV_USER') ?: 'hub-read'));
        $password = (string) ($this->option('password') ?: (getenv('MOCK_DAV_PASS') ?: 'mock-secret'));

        $env = [
            'MOCK_DAV_USER' => $user,
            'MOCK_DAV_PASS' => $password,
            'MOCK_TIMEOUT_SLEEP' => (string) max(1, (int) $this->option('timeout-sleep')),
        ];

        if (is_string($this->option('runtime-dir')) && $this->option('runtime-dir') !== '') {
            $env['MOCK_RUNTIME_DIR'] = (string) $this->option('runtime-dir');
        }

        $this->info(sprintf('Mock-Immoware24-DAV-Server auf http://%s:%d (Benutzer %s, Passwort nicht angezeigt).', $host, $port, $user));
        $this->line('Endpunkte: /dav/files/, /dav/addressbooks/kontakte/, /dav/calendars/termine/, Steuerung /__mock/health, /__mock/log, /__mock/reset');
        $this->line('Szenarien: Header X-Mock-Scenario, Query ?scenario= oder Pfadpräfix /s/<szenario>/ (ok, unauthorized, forbidden, notfound, conflict, ratelimited, servererror, timeout, invalidxml, slow, etagunstable)');
        $this->line('Beenden mit Strg+C.');

        $process = new Process([PHP_BINARY, '-S', sprintf('%s:%d', $host, $port), $router], base_path(), $env, null, null);
        $process->setTty(false);

        return $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
    }
}
