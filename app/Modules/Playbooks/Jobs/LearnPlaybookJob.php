<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Jobs;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Services\PlaybookLearningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Lernt aus einem abgeschlossenen Vorgang (Queue mail-ai). PlaybookLearningService fängt eigene Fehler ab, dieser
 * Job wiederholt daher nicht: ein Fehler beim Lernen darf den bereits abgeschlossenen Vorgang nicht beeinträchtigen.
 */
final class LearnPlaybookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $caseId)
    {
        $this->onQueue((string) config('hub.mail.queues.ai', 'mail-ai'));
    }

    public function handle(PlaybookLearningService $learning): void
    {
        $case = MailCase::query()->find($this->caseId);

        if (! $case instanceof MailCase) {
            Log::warning('Playbooks: Vorgang zum Lernen nicht gefunden.', ['case_id' => $this->caseId]);

            return;
        }

        $learning->learnFromClosedCase($case);
    }
}
