<?php

declare(strict_types=1);

namespace App\Modules\Actions\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Services\ActionOutboxWriter;
use App\Modules\Actions\Services\PreconditionChecker;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Scheduler-Lauf für Pläne im Status scheduled: Wirksamkeitsdatum erreicht, Vorbedingungen erneut geprüft, dann
 * Ausführung über ExecuteActionJob. Pläne mit Datum in der Zukunft werden nie vorzeitig ausgeführt.
 */
class RunScheduledActionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly ?string $correlationId = null)
    {
        $this->onQueue((string) config('hub.actions.jobs.queue', 'mail-high'));
    }

    /**
     * @return array{dispatched: int, blocked: int, waiting: int}
     */
    public function handle(PreconditionChecker $preconditions, Dispatcher $bus, ActionOutboxWriter $outbox, CorrelationId $correlation): array
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        $today = CarbonImmutable::now()->startOfDay();
        $stats = ['dispatched' => 0, 'blocked' => 0, 'waiting' => 0];

        ActionPlan::query()->withoutGlobalScopes()->where('status', ActionStatus::Scheduled->value)->with('currentVersion')->lazyById(100)->each(function (ActionPlan $plan) use ($today, $preconditions, $bus, $outbox, $correlation, &$stats): void {
            $version = $plan->currentVersion;

            if ($version === null) {
                return;
            }

            $effective = $version->getAttribute('effective_date');

            if ($effective instanceof CarbonImmutable && $effective->startOfDay()->greaterThan($today)) {
                $stats['waiting']++;

                return;
            }

            $version->setRelation('plan', $plan);
            $reasons = $preconditions->check($version);

            if ($reasons !== []) {
                $plan->forceFill(['status' => ActionStatus::ManualReview]);
                $plan->save();
                $outbox->write((int) $plan->getAttribute('organization_id'), 'execution.failed', 'action_plan', (int) $plan->getKey(), ['reason' => implode(' ', $reasons), 'status' => 'blocked_precondition']);
                Log::info('Eingeplanter Plan gesperrt (Vorbedingungen).', ['plan_id' => $plan->getKey(), 'reasons' => $reasons]);
                $stats['blocked']++;

                return;
            }

            $plan->forceFill(['status' => ActionStatus::Approved]);
            $plan->save();

            foreach ($version->steps() as $index => $step) {
                $bus->dispatch(ExecuteActionJob::forStep((int) $version->getKey(), (int) $index, null, $correlation->current()));
            }

            $stats['dispatched']++;
        });

        return $stats;
    }
}
