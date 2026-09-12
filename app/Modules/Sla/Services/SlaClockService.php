<?php

declare(strict_types=1);

namespace App\Modules\Sla\Services;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Sla\Calendar\BusinessTime;
use App\Modules\Sla\DTO\TrafficLight;
use App\Modules\Sla\Enums\ClockState;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Enums\SlaColor;
use App\Modules\Sla\Models\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Vier Uhren je Teilanliegen (acknowledge, first_qualified_reply, next_update, resolution). Start ist die Empfangszeit
 * der Nachricht (Gmail internalDate), ein Import verjüngt nicht. Jede Frist trägt ihre Quelle, jede Änderung steht
 * im Protokoll mail_sla_clock_log. Pausen sind nur für die Lösungsuhr bei waiting_external erlaubt und begrenzt.
 * Altbestand (LegacyImportPolicy): Uhren entstehen als cancelled mit Hinweis Altbestand statt sofort verletzt.
 */
final class SlaClockService
{
    public function __construct(
        private readonly SlaRuleResolver $rules,
        private readonly BusinessTime $businessTime,
        private readonly Repository $config,
        private readonly LegacyImportPolicy $legacy,
    ) {}

    /**
     * Startet alle vier Uhren eines Teilanliegens mit der Empfangszeit als Startpunkt. Für Altbestand (Empfang vor
     * import_from des Postfachs oder älter als hub.sla.legacy_after_days beim Import) werden die Uhren als cancelled
     * angelegt und protokolliert (Quelle legacy_import); es entsteht kein künstlicher SLA-Verstoß.
     *
     * @return array<int, SlaClock>
     */
    public function startForItem(CaseItem $item, CarbonImmutable $receivedAt, ?int $actorId = null): array
    {
        $case = $item->loadMissing('case')->case;
        $priority = $item->priority instanceof Priority ? $item->priority : Priority::from((string) ($item->priority ?? 'p2'));
        $clocks = [];
        $legacyReason = $this->legacyReasonFor($case, $item, $receivedAt);

        foreach (ClockType::cases() as $type) {
            $resolved = $this->rules->resolve((int) $case->organization_id, $case->team_id !== null ? (int) $case->team_id : null, $priority, (string) $item->item_type, $type);
            $minutes = $resolved['target_minutes'];

            if ($minutes <= 0) {
                continue;
            }

            $targetAt = $this->addMinutes($receivedAt, $minutes, $resolved['uses_calendar']);
            $warnAt = $this->addMinutes($receivedAt, (int) floor($minutes * $resolved['warn_percent'] / 100), $resolved['uses_calendar']);

            if ($legacyReason !== null) {
                $clock = SlaClock::query()->create([
                    'case_id' => $case->getKey(),
                    'case_item_id' => $item->getKey(),
                    'clock_type' => $type->value,
                    'sla_rule_id' => $resolved['rule_id'],
                    'target_source' => $resolved['source'],
                    'target_minutes' => $minutes,
                    'uses_calendar' => $resolved['uses_calendar'],
                    'started_at' => $receivedAt->utc(),
                    'stopped_at' => CarbonImmutable::now(),
                    'target_at' => $targetAt,
                    'warn_at' => $warnAt,
                    'state' => ClockState::Cancelled->value,
                    'color' => SlaColor::Green->value,
                    'cause_text' => mb_substr($legacyReason, 0, 300),
                    'last_evaluated_at' => CarbonImmutable::now(),
                ]);

                $this->log($clock, 'cancelled', $targetAt, null, LegacyImportPolicy::SOURCE, $legacyReason, $actorId);
                $clocks[] = $clock;

                continue;
            }

            $clock = SlaClock::query()->create([
                'case_id' => $case->getKey(),
                'case_item_id' => $item->getKey(),
                'clock_type' => $type->value,
                'sla_rule_id' => $resolved['rule_id'],
                'target_source' => $resolved['source'],
                'target_minutes' => $minutes,
                'uses_calendar' => $resolved['uses_calendar'],
                'started_at' => $receivedAt->utc(),
                'target_at' => $targetAt,
                'warn_at' => $warnAt,
                'state' => ClockState::Running->value,
                'color' => SlaColor::Green->value,
            ]);

            $this->log($clock, 'started', null, $targetAt, $resolved['source'], sprintf('Start mit Empfangszeit %s, Ziel %d Minuten (%s).', $receivedAt->utc()->toIso8601String(), $minutes, $resolved['uses_calendar'] ? 'Arbeitszeit' : 'Kalenderzeit'), $actorId);
            $this->evaluate($clock, CarbonImmutable::now());
            $clocks[] = $clock;
        }

        return $clocks;
    }

    public function clock(CaseItem $item, ClockType $type): ?SlaClock
    {
        return SlaClock::query()
            ->where('case_item_id', $item->getKey())
            ->where('clock_type', $type->value)
            ->first();
    }

    /**
     * Stoppt eine Uhr. Ergebnis met oder breached je nach Zielzeit. Bereits gestoppte Uhren bleiben unverändert.
     */
    public function stop(CaseItem $item, ClockType $type, CarbonImmutable $at, string $reason, ?int $actorId = null, string $source = 'system'): ?SlaClock
    {
        $clock = $this->clock($item, $type);

        if (! $clock instanceof SlaClock || ! ClockState::from((string) $clock->state)->isActive()) {
            return $clock;
        }

        if ($clock->state === ClockState::Paused->value) {
            $this->resume($item, $type, 'Stopp beendet die Pause.', $actorId, $at);
            $clock->refresh();
        }

        $met = $at->lessThanOrEqualTo($clock->target_at);
        $clock->forceFill([
            'stopped_at' => $at->utc(),
            'state' => $met ? ClockState::Met->value : ClockState::Breached->value,
            'color' => $met ? ($at->greaterThanOrEqualTo($clock->warn_at) ? SlaColor::Yellow->value : SlaColor::Green->value) : SlaColor::Red->value,
            'cause_text' => $met ? sprintf('%s eingehalten.', $type->label()) : sprintf('%s überschritten (Ziel %s).', $type->label(), $this->display($clock->target_at)),
            'last_evaluated_at' => CarbonImmutable::now(),
        ])->save();

        $this->log($clock, $met ? 'stopped_met' : 'stopped_breached', $clock->target_at, $clock->target_at, $source, $reason, $actorId);

        return $clock;
    }

    public function cancel(CaseItem $item, ClockType $type, string $reason, ?int $actorId = null): ?SlaClock
    {
        $clock = $this->clock($item, $type);

        if (! $clock instanceof SlaClock || ! ClockState::from((string) $clock->state)->isActive()) {
            return $clock;
        }

        $clock->forceFill(['state' => ClockState::Cancelled->value, 'stopped_at' => CarbonImmutable::now(), 'cause_text' => 'Uhr storniert: '.$reason])->save();
        $this->log($clock, 'cancelled', $clock->target_at, null, 'system', $reason, $actorId);

        return $clock;
    }

    /**
     * Startet die Uhr next_update neu (nach jedem Kundenzwischenstand), Zielzeit ab $from.
     */
    public function restartNextUpdate(CaseItem $item, CarbonImmutable $from, string $reason, ?int $actorId = null): ?SlaClock
    {
        $clock = $this->clock($item, ClockType::NextUpdate);

        if (! $clock instanceof SlaClock) {
            return null;
        }

        $targetAt = $this->addMinutes($from, (int) $clock->target_minutes, (bool) $clock->uses_calendar);
        $warnAt = $this->addMinutes($from, (int) floor($clock->target_minutes * $this->warnPercent($clock) / 100), (bool) $clock->uses_calendar);
        $old = $clock->target_at;

        $clock->forceFill([
            'started_at' => $from->utc(),
            'paused_at' => null,
            'paused_minutes' => 0,
            'stopped_at' => null,
            'target_at' => $targetAt,
            'warn_at' => $warnAt,
            'state' => ClockState::Running->value,
        ])->save();

        $this->log($clock, 'retargeted', $old, $targetAt, 'system', $reason, $actorId);
        $this->evaluate($clock, CarbonImmutable::now());

        return $clock;
    }

    /**
     * Manuelle Neuberechnung der Zielzeit, protokolliert mit Quelle manual und Begründung.
     */
    public function retarget(SlaClock $clock, CarbonImmutable $newTarget, string $reason, int $actorId): SlaClock
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Eine manuelle Friständerung braucht eine Begründung.');
        }

        $old = $clock->target_at;
        $usesCalendar = (bool) $clock->uses_calendar;
        // Anteil der Zielzeit in derselben Zeitbasis wie die Uhr (Arbeitszeit oder Kalenderzeit), sonst liegt warn_at
        // bei Arbeitszeituhren außerhalb der Arbeitszeit und die Ampel wird zu früh oder zu spät gelb.
        $span = max(1, $this->elapsed($clock->started_at, $newTarget, $usesCalendar));
        $warnAt = $this->addMinutes($clock->started_at, (int) floor($span * $this->warnPercent($clock) / 100), $usesCalendar);

        $clock->forceFill(['target_at' => $newTarget->utc(), 'warn_at' => $warnAt, 'target_source' => 'manual'])->save();
        $this->log($clock, 'retargeted', $old, $newTarget, 'manual', $reason, $actorId);
        $this->evaluate($clock, CarbonImmutable::now());

        return $clock;
    }

    /**
     * Pause der Lösungsuhr bei waiting_external, nur für erlaubte Prioritäten, begrenzt auf max_pause_minutes.
     */
    public function pause(CaseItem $item, ClockType $type, string $reason, ?int $actorId = null, ?CarbonImmutable $at = null): ?SlaClock
    {
        if (! $type->mayPause()) {
            throw new InvalidArgumentException(sprintf('Die Uhr %s darf nicht pausieren.', $type->label()));
        }

        $priority = $item->priority instanceof Priority ? $item->priority->value : (string) $item->priority;

        if (! in_array($priority, (array) $this->config->get('hub.sla.pause_allowed_priorities', []), true)) {
            return $this->clock($item, $type);
        }

        $clock = $this->clock($item, $type);

        if (! $clock instanceof SlaClock || $clock->state !== ClockState::Running->value) {
            return $clock;
        }

        $at ??= CarbonImmutable::now();
        $clock->forceFill(['state' => ClockState::Paused->value, 'paused_at' => $at->utc()])->save();
        $this->log($clock, 'paused', $clock->target_at, $clock->target_at, 'system', $reason, $actorId);

        return $clock;
    }

    public function resume(CaseItem $item, ClockType $type, string $reason, ?int $actorId = null, ?CarbonImmutable $at = null): ?SlaClock
    {
        $clock = $this->clock($item, $type);

        if (! $clock instanceof SlaClock || $clock->state !== ClockState::Paused->value || $clock->paused_at === null) {
            return $clock;
        }

        $at ??= CarbonImmutable::now();
        $pausedNow = $this->elapsed($clock->paused_at, $at, (bool) $clock->uses_calendar);
        $consumedBefore = max(0, $this->elapsed($clock->started_at, $clock->paused_at, (bool) $clock->uses_calendar) - (int) $clock->paused_minutes);
        $remaining = max(0, (int) $clock->target_minutes - $consumedBefore);
        $old = $clock->target_at;
        $targetAt = $this->addMinutes($at, $remaining, (bool) $clock->uses_calendar);
        $warnRemaining = (int) floor($clock->target_minutes * $this->warnPercent($clock) / 100) - $consumedBefore;
        $warnAt = $warnRemaining > 0 ? $this->addMinutes($at, $warnRemaining, (bool) $clock->uses_calendar) : $at->utc();

        $clock->forceFill([
            'state' => ClockState::Running->value,
            'paused_at' => null,
            'paused_minutes' => (int) $clock->paused_minutes + $pausedNow,
            'target_at' => $targetAt,
            'warn_at' => $warnAt,
        ])->save();

        $this->log($clock, 'resumed', $old, $targetAt, 'system', $reason, $actorId);
        $this->evaluate($clock, CarbonImmutable::now());

        return $clock;
    }

    /**
     * Farbe und Ursache-Text einer Uhr neu berechnen. Pausen über max_pause_minutes werden automatisch beendet.
     */
    public function evaluate(SlaClock $clock, CarbonImmutable $now): SlaClock
    {
        $state = ClockState::from((string) $clock->state);

        if (! $state->isActive()) {
            return $clock;
        }

        if ($state === ClockState::Paused && $clock->paused_at !== null) {
            $limit = (int) $this->config->get('hub.sla.max_pause_minutes', 14400);

            // Pausenlimit in der Zeitbasis der Uhr messen (Arbeitszeit bei uses_calendar, sonst Kalenderzeit).
            if ($this->elapsed($clock->paused_at, $now, (bool) $clock->uses_calendar) >= $limit) {
                $item = $clock->loadMissing('caseItem')->caseItem;

                if ($item instanceof CaseItem) {
                    $this->log($clock, 'pause_limit_reached', $clock->target_at, $clock->target_at, 'system', sprintf('Pausenlimit von %d Minuten erreicht, Uhr läuft weiter.', $limit));
                    $this->resume($item, ClockType::from((string) $clock->clock_type), 'Pausenlimit erreicht.', null, $now);

                    return $clock->refresh();
                }
            }

            $clock->forceFill(['cause_text' => sprintf('%s pausiert (wartet auf Dritte) seit %s.', $this->typeLabel($clock), $this->display($clock->paused_at)), 'last_evaluated_at' => $now])->save();

            return $clock;
        }

        $previous = $clock->color;
        $color = $now->greaterThanOrEqualTo($clock->target_at)
            ? SlaColor::Red
            : ($now->greaterThanOrEqualTo($clock->warn_at) ? SlaColor::Yellow : SlaColor::Green);

        $cause = match ($color) {
            SlaColor::Red => sprintf('%s überschritten seit %s.', $this->typeLabel($clock), $this->display($clock->target_at)),
            SlaColor::Yellow => sprintf('%s zu über %d %% verbraucht, fällig %s.', $this->typeLabel($clock), $this->warnPercent($clock), $this->display($clock->target_at)),
            SlaColor::Green => sprintf('%s fällig %s.', $this->typeLabel($clock), $this->display($clock->target_at)),
        };

        $clock->forceFill(['color' => $color->value, 'cause_text' => $cause, 'last_evaluated_at' => $now])->save();

        if ($color === SlaColor::Red && $previous !== SlaColor::Red->value) {
            $this->log($clock, 'evaluated_breached', $clock->target_at, $clock->target_at, 'system', $cause);
        }

        return $clock;
    }

    /**
     * Alle aktiven Uhren neu bewerten (SlaCheckJob). Liefert die Anzahl.
     */
    public function evaluateAll(CarbonImmutable $now): int
    {
        $count = 0;

        SlaClock::query()
            ->where(static fn ($q) => $q->whereIn('state', [ClockState::Running->value, ClockState::Paused->value]))
            ->chunkById(200, function ($clocks) use ($now, &$count): void {
                foreach ($clocks as $clock) {
                    $this->evaluate($clock, $now);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Ampel B eines Vorgangs: schlimmste fällige Verpflichtung mit Ursache. Fehlende Pflichtfelder eines offenen
     * Vorgangs oder eine überschrittene Fälligkeit ergeben rot.
     */
    public function trafficLightForCase(MailCase $case, ?CarbonImmutable $now = null): TrafficLight
    {
        $now ??= CarbonImmutable::now();
        $status = $case->status_processing instanceof CaseStatus ? $case->status_processing : CaseStatus::from((string) $case->status_processing);
        $worst = TrafficLight::green();

        if ($status->isOpen()) {
            foreach ((array) $this->config->get('hub.cases.open_requires', []) as $field) {
                if ($case->getAttribute((string) $field) === null || $case->getAttribute((string) $field) === '') {
                    $worst = new TrafficLight(SlaColor::Red, sprintf('Pflichtfeld %s fehlt.', $this->fieldLabel((string) $field)));
                    break;
                }
            }

            if ($worst->color !== SlaColor::Red && $case->due_at !== null && $case->due_at->lessThan($now)) {
                $worst = new TrafficLight(SlaColor::Red, sprintf('Fälligkeit des Vorgangs überschritten seit %s.', $this->display($case->due_at)), null, null, $case->due_at);
            }
        }

        $clocks = SlaClock::query()
            ->where('case_id', $case->getKey())
            ->where(static fn ($q) => $q->whereIn('state', [ClockState::Running->value, ClockState::Paused->value]))
            ->get();

        $nearest = null;

        foreach ($clocks as $clock) {
            $this->evaluate($clock, $now);
            $color = SlaColor::from((string) $clock->color);
            $light = new TrafficLight($color, (string) $clock->cause_text, (string) $clock->clock_type, $clock->case_item_id !== null ? (int) $clock->case_item_id : null, $clock->target_at);

            if ($color->isWorseThan($worst->color) || ($color === $worst->color && $worst->targetAt !== null && $clock->target_at->lessThan($worst->targetAt))) {
                $worst = $light;
            }

            if ($nearest === null || $clock->target_at->lessThan($nearest->targetAt)) {
                $nearest = $light;
            }
        }

        // Alles im grünen Bereich: die nächste fällige Verpflichtung als Ursache ausweisen.
        return $worst->color === SlaColor::Green && $worst->clockType === null && $nearest !== null ? $nearest : $worst;
    }

    /**
     * Ampel eines Teilanliegens.
     */
    public function trafficLightForItem(CaseItem $item, ?CarbonImmutable $now = null): TrafficLight
    {
        $now ??= CarbonImmutable::now();
        $worst = TrafficLight::green();

        foreach (SlaClock::query()->where('case_item_id', $item->getKey())->where(static fn ($q) => $q->whereIn('state', [ClockState::Running->value, ClockState::Paused->value]))->get() as $clock) {
            $this->evaluate($clock, $now);
            $color = SlaColor::from((string) $clock->color);

            if ($color->isWorseThan($worst->color)) {
                $worst = new TrafficLight($color, (string) $clock->cause_text, (string) $clock->clock_type, (int) $item->getKey(), $clock->target_at);
            }
        }

        return $worst;
    }

    /**
     * Altbestandsprüfung: Importbeginn des Postfachs und Importzeitpunkt der Quellnachricht des Teilanliegens.
     */
    private function legacyReasonFor(MailCase $case, CaseItem $item, CarbonImmutable $receivedAt): ?string
    {
        $mailbox = $case->loadMissing('mailbox')->mailbox;
        $importFrom = $mailbox?->getAttribute('import_from');
        $importFrom = $importFrom instanceof \DateTimeInterface ? CarbonImmutable::instance($importFrom) : null;

        $importedAt = null;

        if ($item->source_message_id !== null) {
            $message = MailMessage::query()->allOrganizations()->find($item->source_message_id);
            $raw = $message instanceof MailMessage ? $message->getAttribute('imported_at') : null;
            $importedAt = $raw instanceof \DateTimeInterface ? CarbonImmutable::instance($raw) : null;
        }

        return $this->legacy->legacyReason($receivedAt, $importFrom, $importedAt);
    }

    public function addMinutes(CarbonImmutable $from, int $minutes, bool $usesCalendar): CarbonImmutable
    {
        return $usesCalendar
            ? $this->businessTime->addMinutes($from, $minutes)
            : $from->utc()->addMinutes($minutes);
    }

    private function elapsed(CarbonImmutable $from, CarbonImmutable $to, bool $usesCalendar): int
    {
        return $usesCalendar
            ? $this->businessTime->elapsedMinutes($from, $to)
            : max(0, (int) $from->diffInMinutes($to));
    }

    private function warnPercent(SlaClock $clock): int
    {
        $rule = $clock->sla_rule_id !== null ? $clock->loadMissing('rule')->rule : null;

        return $rule !== null ? (int) $rule->warn_percent : (int) $this->config->get('hub.sla.warn_percent', 50);
    }

    private function typeLabel(SlaClock $clock): string
    {
        return (ClockType::tryFrom((string) $clock->clock_type)?->label() ?? (string) $clock->clock_type)
            .($clock->case_item_id !== null ? sprintf(' (Teilanliegen %d)', (int) $clock->case_item_id) : '');
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'assignee_user_id' => 'Verantwortlicher',
            'next_step' => 'nächster Schritt',
            'due_at' => 'Fälligkeit',
            default => $field,
        };
    }

    private function display(CarbonImmutable $utc): string
    {
        return $utc->setTimezone((string) $this->config->get('hub.mail.display_timezone', 'Europe/Berlin'))->format('d.m.Y H:i');
    }

    private function log(SlaClock $clock, string $event, ?CarbonImmutable $old, ?CarbonImmutable $new, string $source, ?string $reason, ?int $actorId = null): void
    {
        DB::table('mail_sla_clock_log')->insert([
            'sla_clock_id' => $clock->getKey(),
            'case_id' => $clock->case_id,
            'event' => $event,
            'old_target_at' => $old?->utc(),
            'new_target_at' => $new?->utc(),
            'source' => $source,
            'reason' => $reason !== null ? mb_substr($reason, 0, 300) : null,
            'changed_by' => $actorId,
            'created_at' => CarbonImmutable::now(),
        ]);
    }
}
