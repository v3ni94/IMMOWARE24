<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Jobs;

use App\Modules\Cases\Models\MailCase;
use App\Modules\Playbooks\Services\PlaybookMatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Gleicht einen neu geöffneten Vorgang mit den vorhandenen Prozessvorlagen ab (Queue mail-ai, wie die
 * KI-Klassifikation). Der regelbasierte Vergleich selbst ist kostenlos; ein KI-Aufruf erfolgt nur bei
 * unklarem, aber nicht aussichtslosem Ergebnis (PlaybookMatchService).
 */
final class MatchPlaybookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $caseId)
    {
        $this->onQueue((string) config('hub.mail.queues.ai', 'mail-ai'));
    }

    public function handle(PlaybookMatchService $matcher): void
    {
        $case = MailCase::query()->find($this->caseId);

        if (! $case instanceof MailCase) {
            Log::warning('Playbooks: Vorgang für Abgleich nicht gefunden.', ['case_id' => $this->caseId]);

            return;
        }

        $matcher->match($case);
    }
}
