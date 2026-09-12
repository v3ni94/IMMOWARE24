<?php

declare(strict_types=1);

namespace App\Modules\Actions\Jobs;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ActionOutboxWriter;
use App\Modules\Actions\Services\PreconditionChecker;
use App\Modules\Sync\Services\DlqService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduler-Lauf für Pläne im Status scheduled: Wirksamkeitsdatum erreicht, Vorbedingungen erneut geprüft, dann
 * Ausführung über ExecuteActionJob. Pläne mit Datum in der Zukunft werden nie vorzeitig ausgeführt. Pläne, die
 * approved sind, aber ohne Ausführungsbeleg blieben (Abbruch nach Statuswechsel), werden nachgefasst.
 */
class RunScheduledActionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly ?string $correlationId = null)
    {
        $this->timeout = max(120, (int) config('hub.actions.jobs.timeout', 120));
        $this->onQueue((string) config('hub.actions.jobs.queue', 'mail-high'));
    }

    /**
     * @return array{dispatched: int, blocked: int, waiting: int, recovered: int}
     */
    public function handle(PreconditionChecker $preconditions, Dispatcher $bus, ActionOutboxWriter $outbox, CorrelationId $correlation): array
    {
        if ($this->correlationId !== null) {
            $correlation->set($this->correlationId);
        }

        $today = CarbonImmutable::now()->startOfDay();
        $stats = ['dispatched' => 0, 'blocked' => 0, 'waiting' => 0, 'recovered' => 0];
        $stats['recovered'] = $this->recoverApprovedWithoutExecution($bus, $correlation, $today);

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

            // Statuswechsel und Einplanung gehören zusammen: der Dispatch läuft nach Commit (Outbox-Muster), ein
            // Abbruch davor lässt den Plan in scheduled, sodass der nächste Lauf ihn erneut aufnimmt.
            DB::transaction(function () use ($plan, $version, $bus, $correlation): void {
                $plan->forceFill(['status' => ActionStatus::Approved]);
                $plan->save();

                DB::afterCommit(static function () use ($version, $bus, $correlation): void {
                    foreach ($version->steps() as $index => $step) {
                        $bus->dispatch(ExecuteActionJob::forStep((int) $version->getKey(), (int) $index, null, $correlation->current()));
                    }
                });
            });

            $stats['dispatched']++;
        });

        return $stats;
    }

    /**
     * Nachfassen: Pläne, die nach Ablauf des Wirksamkeitsdatums approved sind, aber keinen Ausführungsbeleg haben
     * (Abbruch zwischen Statuswechsel und Einplanung in früheren Läufen). ExecuteActionJob ist je Schritt eindeutig
     * und idempotent, eine doppelte Einplanung erzeugt keinen zweiten externen Aufruf.
     */
    private function recoverApprovedWithoutExecution(Dispatcher $bus, CorrelationId $correlation, CarbonImmutable $today): int
    {
        $recovered = 0;
        $graceSeconds = max(60, (int) config('hub.actions.jobs.lock_seconds', 300));

        ActionPlan::query()->withoutGlobalScopes()
            ->where('status', ActionStatus::Approved->value)
            ->where('updated_at', '<=', CarbonImmutable::now()->subSeconds($graceSeconds))
            ->with('currentVersion')
            ->lazyById(100)
            ->each(function (ActionPlan $plan) use ($bus, $correlation, $today, &$recovered): void {
                $version = $plan->currentVersion;

                if ($version === null) {
                    return;
                }

                $effective = $version->getAttribute('effective_date');

                if (! $effective instanceof CarbonImmutable || $effective->startOfDay()->greaterThan($today)) {
                    return;
                }

                if (Execution::query()->where('action_plan_version_id', $version->getKey())->exists()) {
                    return;
                }

                Log::warning('Freigegebener Plan ohne Ausführungsbeleg, Einplanung nachgeholt.', ['plan_id' => $plan->getKey(), 'version_id' => $version->getKey()]);

                foreach ($version->steps() as $index => $step) {
                    $bus->dispatch(ExecuteActionJob::forStep((int) $version->getKey(), (int) $index, null, $correlation->current()));
                }

                $recovered++;
            });

        return $recovered;
    }

    public function failed(?Throwable $exception): void
    {
        $exception ??= new \RuntimeException('RunScheduledActionsJob ohne Exception fehlgeschlagen.');

        app(DlqService::class)->store(static::class, ['correlationId' => $this->correlationId], $exception, null, 'mail_action', (string) $this->queue);
    }
}
