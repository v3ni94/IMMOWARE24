<?php

declare(strict_types=1);

namespace App\Modules\Sla\Services;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Services\CaseStatusLogger;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Models\Team;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Calendar\WorkCalendar;
use App\Modules\Sla\Channels\AlertChannelInterface;
use App\Modules\Sla\Jobs\EmergencyAlertJob;
use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Models\EscalationStep;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

/**
 * Notfallwarteschlange (P0). Alarmzustellung (Kanäle, Queue mail-high) und menschliche Annahme (ack) sind getrennt
 * protokolliert. Ohne Annahme eskaliert der Alarm alle escalate_after_minutes eine Stufe weiter
 * (Verantwortlicher, Teamleitung, Eskalationskontakt, Geschäftsführung). sms und call sind Stubs (not_configured).
 * Ohne Bereitschaft trägt der Alarm den Hinweis "keine 24/7-Betreuung eingerichtet".
 */
final class EmergencyQueue
{
    /**
     * @param  array<int, AlertChannelInterface>  $channels
     */
    public function __construct(
        private readonly array $channels,
        private readonly WorkCalendar $calendar,
        private readonly CaseStatusLogger $log,
        private readonly Repository $config,
        private readonly BusDispatcher $bus,
    ) {}

    public function raise(MailCase $case, ?MailMessage $message, string $matchedRule, ?int $actorId = null, string $detectedBy = 'rule'): EmergencyAlert
    {
        if (! in_array($detectedBy, ['rule', 'manual', 'ai_confirmed'], true)) {
            throw new InvalidArgumentException('Notfallerkennung nur durch rule, manual oder ai_confirmed.');
        }

        $existing = EmergencyAlert::query()->allOrganizations()->where('case_id', $case->getKey())->whereIn('status', ['open', 'escalated'])->first();

        if ($existing instanceof EmergencyAlert) {
            return $existing;
        }

        $now = CarbonImmutable::now();
        $onCall = $this->onCallConfigured();

        $alert = EmergencyAlert::query()->create([
            'organization_id' => $case->organization_id,
            'case_id' => $case->getKey(),
            'message_id' => $message?->getKey(),
            'detected_by' => $detectedBy,
            'matched_rule' => mb_substr($matchedRule, 0, 120),
            'detected_at' => $now,
            'acknowledge_due_at' => $now->addMinutes((int) $this->config->get('hub.sla.emergency.acknowledge_minutes', 10)),
            'next_escalation_at' => $now->addMinutes($this->escalateAfterMinutes()),
            'escalation_level' => 0,
            'status' => 'open',
            'on_call_configured' => $onCall,
            'delivery_log_json' => $onCall || $this->calendar->isWithinWorkingHours($now) ? [] : [['at' => $now->toIso8601String(), 'notice' => (string) $this->config->get('hub.sla.not_on_call_notice')]],
        ]);

        $this->log->log($case, 'emergency', null, 'open', 'Notfallalarm ausgelöst (Regel '.$matchedRule.').', $actorId, null, $detectedBy === 'manual' ? 'user' : 'system', ['alert_id' => $alert->getKey(), 'on_call_configured' => $onCall]);
        $this->bus->dispatch(new EmergencyAlertJob((int) $alert->getKey(), 0));

        return $alert;
    }

    /**
     * Zustellung auf Stufe $level (aus EmergencyAlertJob). Erzeugt einen Eskalationsschritt mit Kanalergebnissen.
     */
    public function deliver(EmergencyAlert $alert, int $level): EscalationStep
    {
        $case = $alert->loadMissing('case')->case;
        $now = CarbonImmutable::now();
        $recipients = $this->recipientsForLevel($case, $level);
        $results = [];
        $anySent = false;
        $subject = sprintf('NOTFALL %s: %s', (string) $case->case_number, (string) $case->title);
        $text = $this->alertText($alert, $case, $level);

        if ($recipients === []) {
            $results[] = ['channel' => 'none', 'status' => 'failed', 'detail' => 'Keine Empfänger auf Stufe '.$level.'.'];
        }

        foreach ($recipients as $recipient) {
            foreach ($this->channels as $channel) {
                $result = $channel->send($alert, $recipient, $subject, $text);
                $result['recipient_user_id'] = (int) $recipient->getKey();
                $result['at'] = $now->toIso8601String();
                $results[] = $result;
                $anySent = $anySent || $result['status'] === 'sent';
            }
        }

        if (! $alert->on_call_configured && ! $this->calendar->isWithinWorkingHours($now)) {
            $results[] = ['channel' => 'notice', 'status' => 'info', 'detail' => (string) $this->config->get('hub.sla.not_on_call_notice'), 'at' => $now->toIso8601String()];
        }

        $step = EscalationStep::query()->create([
            'case_id' => $case->getKey(),
            'emergency_alert_id' => $alert->getKey(),
            'level' => $level,
            'reason' => $level === 0 ? 'Erstalarm Notfall' : sprintf('Keine Annahme innerhalb von %d Minuten, Stufe %d.', $this->escalateAfterMinutes(), $level),
            'escalated_to_role' => $this->roleForLevel($level),
            'escalated_to_user_id' => $recipients !== [] ? (int) $recipients[0]->getKey() : null,
            'status' => $anySent ? 'notified' : 'failed',
            'triggered_at' => $now,
            'notified_at' => $anySent ? $now : null,
            'channel_results_json' => $results,
        ]);

        $log = (array) ($alert->delivery_log_json ?? []);
        $log[] = ['level' => $level, 'step_id' => $step->getKey(), 'status' => $step->status, 'at' => $now->toIso8601String(), 'results' => $results];
        $alert->forceFill(['delivery_log_json' => $log])->save();

        $this->log->log($case, 'emergency', (string) $alert->status, 'delivery_'.$step->status, sprintf('Alarmzustellung Stufe %d: %s.', $level, $anySent ? 'mindestens ein Kanal übergeben' : 'kein Kanal erfolgreich'), null, null, 'system', ['alert_id' => $alert->getKey(), 'step_id' => $step->getKey()]);

        return $step;
    }

    /**
     * Menschliche Annahme, getrennt von der Zustellung.
     */
    public function acknowledge(EmergencyAlert $alert, User $user): EmergencyAlert
    {
        if (! in_array((string) $alert->status, ['open', 'escalated'], true)) {
            return $alert;
        }

        $now = CarbonImmutable::now();
        $alert->forceFill(['status' => 'acknowledged', 'acknowledged_at' => $now, 'acknowledged_by' => $user->getKey(), 'next_escalation_at' => null])->save();

        EscalationStep::query()->where('emergency_alert_id', $alert->getKey())->whereIn('status', ['pending', 'notified', 'failed'])->update(['status' => 'acknowledged', 'acknowledged_at' => $now, 'updated_at' => $now]);

        $case = $alert->loadMissing('case')->case;
        $case->forceFill(['acknowledged_at' => $case->acknowledged_at ?? $now, 'acknowledged_by' => $case->acknowledged_by ?? $user->getKey()])->save();
        $this->log->log($case, 'emergency', 'open', 'acknowledged', 'Notfall angenommen.', $user->getKey(), null, 'user', ['alert_id' => $alert->getKey(), 'within_due' => $now->lessThanOrEqualTo($alert->acknowledge_due_at)]);

        return $alert->refresh();
    }

    /**
     * Herabstufung nur durch einen Menschen mit Begründung (KI darf P0 nie herabstufen).
     */
    public function downgrade(EmergencyAlert $alert, User $user, string $reason): EmergencyAlert
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Herabstufung eines Notfalls braucht eine Begründung.');
        }

        $alert->forceFill(['status' => 'downgraded', 'downgrade_reason' => mb_substr($reason, 0, 200), 'next_escalation_at' => null])->save();
        $this->log->log($alert->loadMissing('case')->case, 'emergency', 'open', 'downgraded', $reason, $user->getKey(), null, 'user', ['alert_id' => $alert->getKey()]);

        return $alert->refresh();
    }

    /**
     * Fällige Eskalationen (SlaCheckJob): jede offene, nicht angenommene Warnung eine Stufe weiter, Job auf mail-high.
     */
    public function escalateDue(CarbonImmutable $now): int
    {
        $count = 0;
        $maxLevel = (int) $this->config->get('hub.sla.emergency.max_escalation_level', 4);

        EmergencyAlert::query()->allOrganizations()
            ->whereIn('status', ['open', 'escalated'])
            ->whereNotNull('next_escalation_at')
            ->where('next_escalation_at', '<=', $now)
            ->chunkById(100, function ($alerts) use ($now, $maxLevel, &$count): void {
                foreach ($alerts as $alert) {
                    $level = (int) $alert->escalation_level + 1;

                    if ($level > $maxLevel) {
                        $alert->forceFill(['next_escalation_at' => null])->save();

                        continue;
                    }

                    $alert->forceFill([
                        'status' => 'escalated',
                        'escalation_level' => $level,
                        'escalated_at' => $now,
                        'next_escalation_at' => $level < $maxLevel ? $now->addMinutes($this->escalateAfterMinutes()) : null,
                    ])->save();

                    $this->log->log($alert->loadMissing('case')->case, 'emergency', 'open', 'escalated_'.$level, sprintf('Keine Annahme, Eskalation Stufe %d.', $level), null, null, 'system', ['alert_id' => $alert->getKey()]);
                    $this->bus->dispatch(new EmergencyAlertJob((int) $alert->getKey(), $level));
                    $count++;
                }
            });

        return $count;
    }

    public function onCallConfigured(): bool
    {
        return (array) $this->config->get('hub.sla.emergency.on_call_user_ids', []) !== [];
    }

    /**
     * Status der Bereitschaft für die Oberfläche.
     *
     * @return array{configured: bool, notice: ?string, user_ids: array<int, int>}
     */
    public function onCallStatus(): array
    {
        $ids = array_values(array_map('intval', (array) $this->config->get('hub.sla.emergency.on_call_user_ids', [])));

        return [
            'configured' => $ids !== [],
            'notice' => $ids === [] ? (string) $this->config->get('hub.sla.not_on_call_notice') : null,
            'user_ids' => $ids,
        ];
    }

    /**
     * @return array<int, User>
     */
    private function recipientsForLevel(MailCase $case, int $level): array
    {
        $chain = (array) $this->config->get('hub.sla.emergency.chain', ['assignee', 'team_lead', 'escalation_contact', 'owner']);
        $team = $case->loadMissing('team')->team;
        $users = [];

        for ($i = min($level, count($chain) - 1); $i >= 0 && $users === []; $i--) {
            $users = $this->usersForRole((string) $chain[$i], $case, $team);
        }

        if ($level > 0 || $users === []) {
            foreach ($this->onCallUsers($case) as $onCall) {
                $users[] = $onCall;
            }
        }

        $unique = [];

        foreach ($users as $user) {
            $unique[(int) $user->getKey()] = $user;
        }

        return array_values($unique);
    }

    /**
     * @return array<int, User>
     */
    private function usersForRole(string $role, MailCase $case, ?Team $team): array
    {
        $ids = match ($role) {
            'assignee' => [$case->assignee_user_id],
            'team_lead' => [$team?->lead_user_id],
            'escalation_contact' => [$team?->escalation_user_id],
            'owner' => User::query()->where('organization_id', $case->organization_id)->where('role', Role::Owner->value)->pluck('id')->all(),
            default => [],
        };

        $ids = array_values(array_filter(array_map(static fn (mixed $id): ?int => $id !== null ? (int) $id : null, $ids)));

        return $ids === [] ? [] : User::query()->where(static fn ($q) => $q->whereIn('id', $ids))->get()->all();
    }

    /**
     * @return array<int, User>
     */
    private function onCallUsers(MailCase $case): array
    {
        $ids = array_values(array_map('intval', (array) $this->config->get('hub.sla.emergency.on_call_user_ids', [])));

        return $ids === [] ? [] : User::query()->where('organization_id', $case->organization_id)->where(static fn ($q) => $q->whereIn('id', $ids))->get()->all();
    }

    private function roleForLevel(int $level): string
    {
        $chain = (array) $this->config->get('hub.sla.emergency.chain', []);

        return (string) ($chain[min($level, max(0, count($chain) - 1))] ?? 'owner');
    }

    private function escalateAfterMinutes(): int
    {
        return max(1, (int) $this->config->get('hub.sla.emergency.escalate_after_minutes', 5));
    }

    private function alertText(EmergencyAlert $alert, MailCase $case, int $level): string
    {
        $tz = (string) $this->config->get('hub.mail.display_timezone', 'Europe/Berlin');
        $lines = [
            sprintf('Notfallverdacht im Vorgang %s (Stufe %d).', (string) $case->case_number, $level),
            'Titel: '.(string) $case->title,
            'Regel: '.(string) $alert->matched_rule,
            'Erkannt: '.$alert->detected_at->setTimezone($tz)->format('d.m.Y H:i').' Uhr',
            'Annahme fällig: '.$alert->acknowledge_due_at->setTimezone($tz)->format('d.m.Y H:i').' Uhr',
            'Bitte im Hub annehmen (Annahmebestätigung), sonst folgt die nächste Eskalationsstufe.',
        ];

        if (! $alert->on_call_configured) {
            $lines[] = (string) $this->config->get('hub.sla.not_on_call_notice');
        }

        return implode("\n", $lines);
    }
}
