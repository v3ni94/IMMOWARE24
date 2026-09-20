<?php

declare(strict_types=1);

namespace App\Modules\Learning\Jobs;

use App\Modules\Learning\Enums\LearningRunStatus;
use App\Modules\Learning\Models\LearningRun;
use App\Modules\Learning\Services\LearningAiAdvisor;
use App\Modules\Learning\Services\LearningRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Führt einen bereits angelegten Lauf der Lernphase Immoware24 aus (Queue sync, wie die übrigen Abgleiche mit
 * Immoware24). Ein Retry würde nur unnötige Last erzeugen, ohne dass sich der Zustand ändert (LearningRunService
 * schreibt Fehler auf den Lauf statt zu werfen); deshalb tries=1. Die KI-Auswertung läuft nur, wenn angefordert
 * und die Erkundung selbst erfolgreich war.
 */
final class RunLearningJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public readonly int $learningRunId,
        public readonly bool $withAi = false,
    ) {
        $this->onQueue((string) config('hub.sync.queue', 'sync'));
    }

    public function backoff(): int
    {
        return 600;
    }

    public function handle(LearningRunService $runs, LearningAiAdvisor $advisor): void
    {
        $run = LearningRun::query()->allOrganizations()->find($this->learningRunId);

        if (! $run instanceof LearningRun) {
            Log::warning('Lernphase Immoware24: Lauf nicht gefunden, Job wird verworfen.', ['run_id' => $this->learningRunId]);

            return;
        }

        $run = $runs->execute($run);

        if (! $this->withAi || $run->status() !== LearningRunStatus::Succeeded) {
            return;
        }

        try {
            $advisor->advise($run, $run->triggeredBy()->first());
        } catch (Throwable $e) {
            // Die KI-Auswertung ist eine Zugabe zum Lauf; ein Fehler hier darf den bereits erfolgreichen
            // Erkundungslauf nicht als fehlgeschlagen erscheinen lassen.
            Log::warning('Lernphase Immoware24: KI-Auswertung fehlgeschlagen', ['run_id' => $run->getKey(), 'class' => $e::class]);
        }
    }

    public function failed(Throwable $exception): void
    {
        $run = LearningRun::query()->allOrganizations()->find($this->learningRunId);

        $run?->forceFill([
            'status' => LearningRunStatus::Failed->value,
            'error_message' => mb_substr($exception->getMessage(), 0, 2000),
            'finished_at' => now()->toImmutable(),
        ])->save();
    }
}
