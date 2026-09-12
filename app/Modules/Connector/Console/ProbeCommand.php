<?php

declare(strict_types=1);

namespace App\Modules\Connector\Console;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Probe\ProbeService;
use Illuminate\Console\Command;

final class ProbeCommand extends Command
{
    protected $signature = 'hub:probe
        {connection : ID oder Name der ImmowareConnection}
        {--delay= : Sekunden zwischen den beiden PROPFIND Depth 1 der ETag-Prüfung (Standard aus Konfiguration)}
        {--json : Ergebnis als JSON ausgeben}';

    protected $description = 'Führt den Capability-Test (Probe) einer Immoware24-Connection aus und persistiert Strategie und Fingerprint.';

    public function handle(ProbeService $probe): int
    {
        $identifier = (string) $this->argument('connection');

        $query = ImmowareConnection::query()->withoutGlobalScopes();
        $connection = ctype_digit($identifier)
            ? $query->find((int) $identifier)
            : $query->where('name', $identifier)->first();

        if (! $connection instanceof ImmowareConnection) {
            $this->error(sprintf('Connection "%s" nicht gefunden.', $identifier));

            return self::FAILURE;
        }

        $delay = $this->option('delay');
        $report = $probe->run($connection, null, is_numeric($delay) ? (int) $delay : null);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report->result->ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf('Probe Connection #%d (%s, %s)', $report->connectionId, $connection->getAttribute('name'), $report->connector));
        $this->line(str_repeat('-', 72));

        foreach ($report->lines() as $line) {
            $this->line($line);
        }

        $this->line(str_repeat('-', 72));
        $this->line(sprintf('Strategie: %s', $report->strategy !== null ? $report->strategy->value : 'keine (Probe nicht erfolgreich)'));
        $this->line(sprintf('ETag stabil: %s, sync-token: %s', $this->yesNo((bool) ($report->facts['etag_stable'] ?? false)), $this->yesNo((bool) ($report->facts['sync_token_supported'] ?? false))));

        if ($report->serverFingerprint !== null) {
            $this->line(sprintf('Server-Fingerprint: %s', substr($report->serverFingerprint, 0, 16).'…'));
        }

        $connection->refresh();

        if ($connection->getAttribute('status') === 'degraded') {
            $this->warn('Connection ist degraded: '.(string) $connection->getAttribute('degraded_reason'));
        }

        $this->line($report->result->ok ? 'Ergebnis: ✓ bestanden' : 'Ergebnis: ✗ nicht bestanden');

        return $report->result->ok ? self::SUCCESS : self::FAILURE;
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'ja' : 'nein';
    }
}
