<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Services\Replay\ReplayOutcome;
use App\Modules\Sync\Services\ReplayService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

/**
 * hub:replay {entity} {external_id|--all} --from=payload: Wiederaufbau von Spiegeldatensätzen aus external_payloads
 * über die Mirror-Services (Documents, Contacts, Calendar). Chronologisch, chunked, mit Fortschrittsanzeige.
 * Kein Request an Immoware24, kein Sweep, kein Soft Delete.
 */
final class ReplayCommand extends Command
{
    protected $signature = 'hub:replay
        {entity : document, contact oder calendar_event}
        {external_id? : Externe ID (Pfad, UID); alternativ --all}
        {--all : Alle Nutzlasten der Entität verarbeiten}
        {--from=payload : Quelle, derzeit nur payload (external_payloads)}
        {--connection= : Nur Nutzlasten dieser Connection-ID}
        {--latest : Nur die jüngste Nutzlast je externer ID statt aller Stände chronologisch}
        {--chunk=200 : Nutzlasten je Datenbank-Chunk}
        {--dry-run : Nur zählen und auflisten, nichts schreiben}';

    protected $description = 'Baut Spiegeldatensätze aus archivierten Nutzlasten (external_payloads) neu auf.';

    public function handle(ReplayService $service): int
    {
        if ((string) $this->option('from') !== 'payload') {
            $this->error('Nur --from=payload wird unterstützt.');

            return self::FAILURE;
        }

        $entity = (string) $this->argument('entity');
        $externalId = $this->argument('external_id');
        $all = (bool) $this->option('all');

        if (($externalId === null) === ! $all) {
            $this->error('Entweder eine external_id oder --all angeben.');

            return self::FAILURE;
        }

        $connection = $this->option('connection');
        $connectionId = $connection !== null && $connection !== '' ? (int) $connection : null;
        $dryRun = (bool) $this->option('dry-run');
        $latest = (bool) $this->option('latest');
        $chunk = max(1, (int) $this->option('chunk'));

        try {
            $replayer = $service->replayerFor($entity);
            $total = $service->count($entity, $externalId, $connectionId, $latest);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s: %d Nutzlasten vom Typ %s%s%s.', $dryRun ? 'Dry-Run' : 'Replay', $total, $replayer->payloadType(), $connectionId !== null ? ', Connection '.$connectionId : '', $latest ? ', nur jüngster Stand je ID' : ', chronologisch'));

        if ($total === 0) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();
        $verbose = $this->output->isVerbose();

        try {
            $stats = $service->replay($entity, $externalId, $connectionId, $dryRun, $latest, $chunk, function (ExternalPayload $payload, ?ReplayOutcome $outcome, ?Throwable $error) use ($bar, $verbose): void {
                $bar->advance();

                if ($error !== null) {
                    $bar->clear();
                    $this->warn(sprintf('Nutzlast #%d: %s', (int) $payload->getKey(), $error->getMessage()));
                    $bar->display();
                } elseif ($verbose && $outcome !== null) {
                    $bar->clear();
                    $this->line(sprintf('Nutzlast #%d: %d created, %d updated, %d unchanged', (int) $payload->getKey(), $outcome->created, $outcome->updated, $outcome->unchanged));
                    $bar->display();
                }
            });
        } catch (Throwable $e) {
            $bar->finish();
            $this->newLine();
            $this->error('Replay abgebrochen: '.$e->getMessage());

            return self::FAILURE;
        }

        $bar->finish();
        $this->newLine();

        $this->table(['Kennzahl', 'Wert'], [
            ['Nutzlasten', (string) $stats['payloads']],
            ['created', (string) $stats['created']],
            ['updated', (string) $stats['updated']],
            ['unchanged', (string) $stats['unchanged']],
            ['fehlgeschlagen', (string) $stats['failed']],
            ['übersprungen, pseudonymisiert', (string) $stats['skipped_pseudonymized']],
            ['übersprungen, Inhalt fehlt', (string) $stats['skipped_missing']],
        ]);

        if ($dryRun) {
            $this->info('Dry-Run, nichts geschrieben.');
        }

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
