<?php

declare(strict_types=1);

namespace App\Modules\Learning\Console;

use App\Modules\Connector\Models\Organization;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Enums\LearningRunStatus;
use App\Modules\Learning\Exceptions\NoConnectionForKindException;
use App\Modules\Learning\Jobs\RunLearningJob;
use App\Modules\Learning\Services\LearningAiAdvisor;
use App\Modules\Learning\Services\LearningRunService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Startet die Lernphase Immoware24: erkundet lesend die aktuelle Struktur über die belegten Zugangswege und
 * vergleicht sie mit dem vorigen Lauf. Ohne --kind laufen alle Arten nacheinander. Wiederholbar, insbesondere
 * nach Änderungen an Immoware24 selbst (neue Ordner, neues Exportformat, andere Felder).
 */
final class LearnCommand extends Command
{
    protected $signature = 'hub:immoware:learn
        {--kind= : webdav, carddav, caldav oder imports; ohne Angabe alle}
        {--connection= : ID einer bestimmten Connection}
        {--organization= : ID der Organisation, Standard: einzige vorhandene Organisation}
        {--sync : synchron statt über die Queue ausführen}
        {--ai : im Anschluss eine KI-Auswertung anstoßen (nur mit eingerichteter KI und Freigabe)}';

    protected $description = 'Führt einen oder alle Lernläufe der Lernphase Immoware24 aus.';

    public function handle(LearningRunService $runs, LearningAiAdvisor $advisor): int
    {
        $organizationId = $this->option('organization') !== null
            ? (int) $this->option('organization')
            : Organization::query()->orderBy('id')->value('id');

        if ($organizationId === null || ! Organization::query()->whereKey($organizationId)->exists()) {
            $this->error('Keine gültige Organisation gefunden. Bitte --organization angeben.');

            return self::FAILURE;
        }

        $kindOption = $this->option('kind');
        $kind = $kindOption !== null ? LearningKind::tryFrom((string) $kindOption) : null;

        if ($kindOption !== null && $kind === null) {
            $this->error('Ungültige Art. Erlaubt: '.implode(', ', array_map(static fn (LearningKind $k): string => $k->value, LearningKind::cases())));

            return self::INVALID;
        }

        $kinds = $kind !== null ? [$kind] : LearningKind::cases();
        $connectionId = $this->option('connection') !== null ? (int) $this->option('connection') : null;
        $synchronous = (bool) $this->option('sync');
        $withAi = (bool) $this->option('ai');
        $exitCode = self::SUCCESS;

        foreach ($kinds as $currentKind) {
            try {
                $connection = $runs->resolveConnection($currentKind, $organizationId, $connectionId);
            } catch (NoConnectionForKindException $e) {
                $this->warn(sprintf('%s: übersprungen (%s)', $currentKind->label(), $e->getMessage()));

                continue;
            }

            $run = $runs->start($organizationId, $currentKind, $connection?->getKey(), null);

            if ($synchronous) {
                $run = $runs->execute($run);

                if ($withAi && $run->status() === LearningRunStatus::Succeeded) {
                    try {
                        $result = $advisor->advise($run);
                        $this->line('  KI-Auswertung: '.$result->statusLabel());
                    } catch (Throwable $e) {
                        $this->warn('  KI-Auswertung fehlgeschlagen: '.$e->getMessage());
                    }
                }

                $status = $run->status();
                $this->{$status === LearningRunStatus::Succeeded ? 'info' : 'error'}(sprintf('%s: %s', $currentKind->label(), $status->label()));

                if ($status !== LearningRunStatus::Succeeded) {
                    $exitCode = self::FAILURE;
                }
            } else {
                RunLearningJob::dispatch($run->getKey(), $withAi);
                $this->info(sprintf('%s: Lauf %d eingeplant.', $currentKind->label(), $run->getKey()));
            }
        }

        return $exitCode;
    }
}
