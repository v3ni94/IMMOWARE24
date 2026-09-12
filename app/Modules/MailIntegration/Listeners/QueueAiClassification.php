<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Listeners;

use App\Modules\Cases\Events\CaseOpened;
use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailIntegration\Jobs\AiClassificationJob;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Cases CaseOpened: KI-Klassifikation als Job auf der Queue mail-ai, nur bei MAIL_AI_ENABLED. Ohne Flag geschieht
 * nichts, es gibt keinen Aufruf nach außen. Das Ergebnis ist ein Vorschlag (mail_ai_suggestions), nie eine Zuordnung.
 */
final class QueueAiClassification
{
    public function __construct(
        private readonly MailFeatureFlags $flags,
        private readonly Dispatcher $bus,
    ) {}

    public function handle(CaseOpened $event): void
    {
        if (! $this->flags->aiEnabled()) {
            return;
        }

        $this->bus->dispatch(new AiClassificationJob((int) $event->case->getKey()));
    }
}
