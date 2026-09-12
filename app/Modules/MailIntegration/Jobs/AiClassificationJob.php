<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Jobs;

use App\Modules\Ai\Enums\AiTask;
use App\Modules\Ai\Services\AiSuggestionService;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * KI-Klassifikation eines neu angelegten Vorgangs (Queue mail-ai). Liefert ausschließlich Vorschläge über
 * AiSuggestionService (Schema-Validierung, Maskierung, Budget); Vorgangstyp, Priorität und Zuordnung ändern sich
 * erst nach menschlicher Bestätigung. Budgetstopp, fehlende Konfiguration oder Providerfehler beenden den Job ohne
 * Wiederholung und ohne Auswirkung auf den Vorgang.
 */
final class AiClassificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly int $caseId)
    {
        $this->onQueue((string) config('hub.mail.queues.ai', 'mail-ai'));
    }

    public function handle(AiSuggestionService $ai): void
    {
        if (! $ai->isEnabled()) {
            return;
        }

        $case = MailCase::query()->withoutGlobalScopes()->find($this->caseId);

        if (! $case instanceof MailCase) {
            return;
        }

        $linkQuery = CaseMessage::query()->where('case_id', $case->getKey());
        $linkQuery->orderByRaw("CASE WHEN link_type = 'origin' THEN 0 ELSE 1 END")->orderBy('id');
        $link = $linkQuery->first();
        $message = $link instanceof CaseMessage ? MailMessage::query()->withoutGlobalScopes()->find($link->getAttribute('message_id')) : null;

        $untrusted = [];

        if ($message instanceof MailMessage) {
            $untrusted[] = ['type' => 'email', 'label' => 'Eingehende Nachricht', 'content' => trim((string) $message->getAttribute('subject')."\n\n".(string) ($message->getAttribute('body_text') ?? $message->getAttribute('snippet') ?? ''))];
        }

        $priority = $case->getAttribute('priority');

        $result = $ai->run(AiTask::Classify, $case, $message, null, [
            'case_type' => (string) $case->getAttribute('case_type'),
            'rule_priority' => is_object($priority) && property_exists($priority, 'value') ? $priority->value : (string) $priority,
            'item_types' => $case->items()->pluck('item_type')->all(),
        ], $untrusted, [
            'rule_priority' => is_object($priority) && property_exists($priority, 'value') ? $priority->value : (string) $priority,
        ]);

        Log::info('MailIntegration: KI-Klassifikation abgeschlossen.', ['case_id' => $case->getKey(), 'status' => $result->status->value, 'suggestions' => count($result->suggestions)]);
    }

    public function failed(Throwable $exception): void
    {
        Log::warning('MailIntegration: KI-Klassifikation fehlgeschlagen, Vorgang bleibt manuell bearbeitbar.', ['case_id' => $this->caseId, 'error' => $exception::class]);
    }
}
