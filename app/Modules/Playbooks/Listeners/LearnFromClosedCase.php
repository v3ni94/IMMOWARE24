<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Listeners;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Events\CaseStatusChanged;
use App\Modules\Cases\StateMachines\ProcessingStateMachine;
use App\Modules\Playbooks\Jobs\LearnPlaybookJob;
use Illuminate\Contracts\Config\Repository;

/**
 * Stößt das Lernen aus einem Vorgang an, sobald seine Bearbeitung geschlossen wird (Dimension processing,
 * Zielstatus closed). Wiedereröffnung und erneutes Schließen lösen erneut aus; PlaybookLearningService
 * berücksichtigt bereits vorhandene, ungeprüfte Entwürfe und legt keine Dubletten an.
 */
final class LearnFromClosedCase
{
    public function __construct(private readonly Repository $config) {}

    public function handle(CaseStatusChanged $event): void
    {
        if ($event->dimension !== ProcessingStateMachine::DIMENSION || $event->to !== CaseStatus::Closed->value) {
            return;
        }

        if (! (bool) $this->config->get('hub.playbooks.flags.enabled', false)) {
            return;
        }

        LearnPlaybookJob::dispatch($event->case->getKey());
    }
}
