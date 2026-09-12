<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Listeners;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Services\CaseService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Spiegelt den Planstatus (ExecutionService::refreshPlanStatus, block, markUnclear, Freigabe) in die Geschäftsdimension
 * des zugehörigen Teilanliegens (mail_case_items.status_business). Ohne diese Spiegelung bliebe status_business bei
 * proposed und CloseConditionChecker könnte Teilfehler (result_unclear, failed, manual_review) und begonnene,
 * unverifizierte Aktionen nie erkennen; Resolved wäre trotz offenem Teilfehler zulässig.
 *
 * Registriert als Eloquent-Ereignis saved auf ActionPlan (Modul MailIntegration, keine Änderung am Modul Actions).
 * Verified wird weiterhin nur über ExecutionVerified (UpdateCaseAfterVerification) gesetzt; hier werden nur die
 * Zwischen- und Fehlerzustände gespiegelt. Zwischenschritte der Zustandsmatrix werden über den kürzesten erlaubten
 * Pfad durchlaufen, jeder Schritt mit Statuslog (Quelle system).
 */
final class MirrorPlanStatusToCaseItem
{
    /** @var array<int, ActionStatus> */
    private const array MIRRORED = [
        ActionStatus::Validated,
        ActionStatus::ApprovalRequired,
        ActionStatus::Approved,
        ActionStatus::Scheduled,
        ActionStatus::Executing,
        ActionStatus::Executed,
        ActionStatus::ResultUnclear,
        ActionStatus::Failed,
        ActionStatus::ManualReview,
    ];

    public function __construct(private readonly CaseService $cases) {}

    public function handle(ActionPlan $plan): void
    {
        if (! $plan->wasChanged('status') && ! $plan->wasRecentlyCreated) {
            return;
        }

        $target = $plan->status instanceof ActionStatus ? $plan->status : ActionStatus::tryFrom((string) $plan->getAttribute('status'));

        if ($target === null || ! in_array($target, self::MIRRORED, true)) {
            return;
        }

        foreach ($this->itemsOf($plan) as $item) {
            $this->mirror($item, $target, $plan);
        }
    }

    /**
     * @return array<int, CaseItem>
     */
    private function itemsOf(ActionPlan $plan): array
    {
        $itemId = $plan->getAttribute('case_item_id');

        if ($itemId !== null) {
            $item = CaseItem::query()->withoutGlobalScopes()->find((int) $itemId);

            return $item instanceof CaseItem ? [$item] : [];
        }

        return CaseItem::query()->withoutGlobalScopes()->where('case_id', $plan->getAttribute('case_id'))->get()->all();
    }

    private function mirror(CaseItem $item, ActionStatus $target, ActionPlan $plan): void
    {
        $current = $item->status_business instanceof ActionStatus ? $item->status_business : ActionStatus::tryFrom((string) $item->status_business);

        if ($current === null || $current === $target || $current === ActionStatus::Verified) {
            return;
        }

        $path = $this->path($current, $target);

        if ($path === null) {
            Log::info('MailIntegration: Planstatus nicht auf Teilanliegen spiegelbar (kein erlaubter Übergang).', ['item_id' => $item->getKey(), 'from' => $current->value, 'to' => $target->value]);

            return;
        }

        try {
            foreach ($path as $step) {
                $item = $this->cases->transitionBusiness($item, $step, null, 'Aktionsplan #'.$plan->getKey().': '.$target->label().'.', 'system');
            }
        } catch (Throwable $e) {
            Log::info('MailIntegration: Geschäftsstatus des Teilanliegens nicht gespiegelt.', ['item_id' => $item->getKey(), 'reason' => $e->getMessage()]);
        }
    }

    /**
     * Kürzester erlaubter Pfad in ActionStatus::allowedTransitions(), ohne Start.
     *
     * @return array<int, ActionStatus>|null
     */
    private function path(ActionStatus $from, ActionStatus $to): ?array
    {
        $queue = [[$from, []]];
        $seen = [$from->value => true];

        while ($queue !== []) {
            [$node, $trail] = array_shift($queue);

            foreach ($node->allowedTransitions() as $next) {
                if ($next === $to) {
                    return [...$trail, $next];
                }

                if (! isset($seen[$next->value])) {
                    $seen[$next->value] = true;
                    $queue[] = [$next, [...$trail, $next]];
                }
            }
        }

        return null;
    }
}
