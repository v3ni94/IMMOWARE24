<?php

declare(strict_types=1);

namespace App\Modules\Sla\Services;

use App\Modules\Cases\Enums\Priority;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Models\SlaRule;
use Illuminate\Contracts\Config\Repository;

/**
 * Ermittelt die Zielzeit einer Uhr: zuerst mail_sla_rules (Team und Vorgangstyp, dann Team, dann Organisation),
 * sonst Startwerte aus hub.sla.defaults. Liefert die Quelle mit (rule oder config) für das Uhrenprotokoll.
 */
final class SlaRuleResolver
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array{target_minutes: int, uses_calendar: bool, warn_percent: int, source: string, rule_id: ?int, escalate_after_minutes: ?int}
     */
    public function resolve(int $organizationId, ?int $teamId, Priority $priority, ?string $caseType, ClockType $clock): array
    {
        $rule = SlaRule::query()
            ->where('organization_id', $organizationId)
            ->where('priority', $priority->value)
            ->where('clock_type', $clock->value)
            ->where('active', true)
            ->where(static function ($q) use ($teamId): void {
                $q->whereNull('team_id');

                if ($teamId !== null) {
                    $q->orWhere('team_id', $teamId);
                }
            })
            ->where(static function ($q) use ($caseType): void {
                $q->whereNull('case_type');

                if ($caseType !== null) {
                    $q->orWhere('case_type', $caseType);
                }
            })
            ->orderByRaw('CASE WHEN team_id IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('CASE WHEN case_type IS NULL THEN 1 ELSE 0 END')
            ->first();

        if ($rule instanceof SlaRule) {
            return [
                'target_minutes' => (int) $rule->target_minutes,
                'uses_calendar' => (bool) $rule->uses_calendar,
                'warn_percent' => (int) $rule->warn_percent,
                'source' => 'rule',
                'rule_id' => (int) $rule->getKey(),
                'escalate_after_minutes' => $rule->escalate_after_minutes !== null ? (int) $rule->escalate_after_minutes : null,
            ];
        }

        $defaults = (array) $this->config->get('hub.sla.defaults.'.$priority->value, []);

        return [
            'target_minutes' => (int) ($defaults[$clock->value] ?? 0),
            'uses_calendar' => (bool) ($defaults['uses_calendar'] ?? ! $priority->usesCalendarTime()),
            'warn_percent' => (int) $this->config->get('hub.sla.warn_percent', 50),
            'source' => 'config',
            'rule_id' => null,
            'escalate_after_minutes' => isset($defaults['escalate_after_minutes']) ? (int) $defaults['escalate_after_minutes'] : null,
        ];
    }
}
