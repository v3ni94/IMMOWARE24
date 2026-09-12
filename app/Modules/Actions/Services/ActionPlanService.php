<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Support\DiffHasher;
use App\Modules\Actions\Support\IbanValidator;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;

/**
 * Pipeline-Schritte readCurrent, validate, diff/preview: legt Plan und unveränderliche Version an. Jede Änderung
 * erzeugt eine neue Version; Freigaben der alten Version verfallen (Bindung an plan_version_id und diff_hash).
 *
 * Schrittformat: ['target_system' => 'lexware', 'action_type' => 'address_change',
 *   'external_ref' => ['reference_type' => 'contact', 'external_id' => '...', 'local_id' => ?int, 'connection_id' => ?int,
 *                      'contract_id' => ?int, 'role' => ?string],
 *   'new_values' => ['street' => '...'], 'fields' => ['street', ...] (optional, sonst Schlüssel von new_values)]
 */
final class ActionPlanService
{
    public function __construct(
        private readonly ActionAllowlist $allowlist,
        private readonly ActionPolicy $policy,
        private readonly AdapterRegistry $adapters,
        private readonly DiffHasher $hasher,
        private readonly IbanValidator $iban,
        private readonly ConnectionInterface $db,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlation,
        private readonly ActionOutboxWriter $outbox,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function propose(MailCase $case, array $steps, ?User $author, ?int $sourceMessageId = null, ?string $sourceHash = null, ?CarbonImmutable $effectiveDate = null, string $generatedBy = 'user'): ActionPlanVersion
    {
        $prepared = $this->prepare($steps);

        return $this->db->transaction(function () use ($case, $prepared, $author, $sourceMessageId, $sourceHash, $effectiveDate, $generatedBy): ActionPlanVersion {
            $plan = new ActionPlan;
            $plan->forceFill([
                'organization_id' => $case->getAttribute('organization_id'),
                'case_id' => $case->getKey(),
                'status' => ActionStatus::Proposed,
                'risk_class' => $prepared['risk'],
                'target_system' => $prepared['leading'],
                'created_by' => $author?->getKey(),
            ]);
            $plan->save();

            $version = $this->createVersion($plan, 1, $prepared, $author, $sourceMessageId, $sourceHash, $effectiveDate, $generatedBy, null);
            $this->audit->log('mail.action_plan.proposed', $plan, [], ['version' => 1, 'risk_class' => $prepared['risk']->value, 'steps' => count($prepared['steps'])], AuditSource::Mail->value, $this->correlation->current());

            return $version;
        });
    }

    /**
     * Neue Version eines bestehenden Plans. Vorherige Version wird als überholt markiert, ihre Freigaben gelten nicht mehr.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    public function revise(ActionPlan $plan, array $steps, ?User $author, ?string $changeNote = null, ?CarbonImmutable $effectiveDate = null): ActionPlanVersion
    {
        if ($plan->status instanceof ActionStatus && in_array($plan->status, [ActionStatus::Executing, ActionStatus::Executed, ActionStatus::Verified], true)) {
            throw new ActionPolicyException('Plan in Ausführung oder abgeschlossen kann nicht mehr geändert werden.', 'plan_locked');
        }

        $prepared = $this->prepare($steps);

        return $this->db->transaction(function () use ($plan, $prepared, $author, $changeNote, $effectiveDate): ActionPlanVersion {
            $previous = $plan->currentVersion;
            $number = ((int) $plan->versions()->max('version')) + 1;

            if ($previous !== null) {
                $previous->forceFill(['superseded_at' => CarbonImmutable::now()]);
                $previous->save();
            }

            $plan->forceFill(['risk_class' => $prepared['risk'], 'target_system' => $prepared['leading'], 'status' => ActionStatus::Proposed]);
            $plan->save();

            $version = $this->createVersion($plan, $number, $prepared, $author, $previous?->getAttribute('source_message_id'), $previous?->getAttribute('source_hash'), $effectiveDate ?? $this->dateOf($previous), 'user', $changeNote);
            $this->audit->log('mail.action_plan.revised', $plan, ['version' => $previous?->getAttribute('version')], ['version' => $number], AuditSource::Mail->value, $this->correlation->current());

            return $version;
        });
    }

    /**
     * Validiert Schritte gegen Allowlist und Adapter, liest den Ausgangszustand und berechnet den Diff.
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array{steps: array<int, array<string, mixed>>, old: array<int, array<string, mixed>>, new: array<int, array<string, mixed>>, risk: RiskClass, leading: TargetSystem, warnings: array<int, string>, preconditions: array<int, array<string, mixed>>}
     */
    public function prepare(array $steps): array
    {
        if ($steps === []) {
            throw new InvalidArgumentException('Ein Aktionsplan braucht mindestens einen Schritt.');
        }

        $normalized = [];
        $old = [];
        $new = [];
        $risks = [];
        $warnings = [];
        $preconditions = [];

        foreach (array_values($steps) as $index => $step) {
            $system = TargetSystem::tryFrom((string) ($step['target_system'] ?? ''));

            if ($system === null) {
                throw new ActionPolicyException('Unbekanntes Zielsystem im Schritt '.($index + 1).'.', 'blocked_capability');
            }

            $type = (string) ($step['action_type'] ?? '');
            $definition = $this->allowlist->require($system, $type);
            $ref = is_array($step['external_ref'] ?? null) ? $step['external_ref'] : [];
            $newValues = is_array($step['new_values'] ?? null) ? $step['new_values'] : [];
            $fields = array_values(array_map('strval', (array) ($step['fields'] ?? array_keys($newValues))));

            if ($type !== 'manual_task' && $type !== 'note' && $newValues === []) {
                throw new InvalidArgumentException(sprintf('Schritt %d ohne neue Werte.', $index + 1));
            }

            foreach ($fields as $field) {
                if ($definition['fields'] !== [] && ! in_array($field, $definition['fields'], true)) {
                    throw new ActionPolicyException(sprintf('Feld "%s" ist für %s/%s nicht zugelassen.', $field, $system->label(), $type), 'blocked_field');
                }
            }

            if ($type === 'bank_change') {
                $this->validateBankStep($ref, $newValues);
            }

            $adapter = $this->adapters->for($system);
            $normalizedStep = [
                'target_system' => $system->value,
                'action_type' => $type,
                'external_ref' => $ref,
                'fields' => $fields,
                'mode' => $definition['mode'],
            ];
            $warnings = array_merge($warnings, $adapter->validateStep($normalizedStep + ['new_values' => $newValues]));

            $current = $ref !== [] && isset($ref['reference_type'], $ref['external_id'])
                ? $adapter->readCurrent(['reference_type' => (string) $ref['reference_type'], 'external_id' => (string) $ref['external_id'], 'local_id' => isset($ref['local_id']) ? (int) $ref['local_id'] : null] + $ref)
                : [];

            $oldValues = [];

            foreach ($fields as $field) {
                $oldValues[$field] = $current[$field] ?? null;
            }

            foreach ((array) ($current['non_editable_reasons'] ?? []) as $reason) {
                $warnings[] = sprintf('Schritt %d: %s Manueller Klärungsweg.', $index + 1, (string) $reason);
            }

            if (isset($current['data_age']) && is_array($current['data_age']) && (bool) ($current['data_age']['stale'] ?? false)) {
                $warnings[] = sprintf('Schritt %d: Spiegeldaten sind veraltet (letzter erfolgreicher Sync %s).', $index + 1, (string) ($current['data_age']['last_success_at'] ?? 'unbekannt'));
            }

            $preconditions[$index] = [
                'source_state_hash' => $this->hasher->hash($oldValues),
                'external_version' => $current['version'] ?? null,
                'data_age' => $current['data_age'] ?? null,
            ];

            $normalized[$index] = $normalizedStep;
            $old[$index] = $oldValues;
            $new[$index] = $this->onlyFields($newValues, $fields);
            $risks[] = RiskClass::tryFrom($definition['risk_class']) ?? RiskClass::Medium;
        }

        $risk = $this->allowlist->highest($risks);

        return [
            'steps' => $normalized,
            'old' => $old,
            'new' => $new,
            'risk' => $risk,
            'leading' => TargetSystem::from((string) $normalized[0]['target_system']),
            'warnings' => $warnings,
            'preconditions' => $preconditions,
        ];
    }

    /**
     * Vorschau: Alt/Neu je Schritt, IBAN maskiert. Kein Schreibzugriff.
     *
     * @return array<int, array{target_system: string, action_type: string, diff: array<string, array{old: mixed, new: mixed}>}>
     */
    public function preview(ActionPlanVersion $version): array
    {
        $old = (array) ($version->getAttribute('old_values') ?? []);
        $new = (array) ($version->getAttribute('new_values') ?? []);
        $result = [];

        foreach ($version->steps() as $index => $step) {
            $diff = $this->hasher->diff((array) ($old[$index] ?? []), (array) ($new[$index] ?? []));

            foreach ($diff as $field => $change) {
                if ($field === 'iban') {
                    $diff[$field] = [
                        'old' => is_string($change['old']) ? $this->iban->mask($change['old']) : $change['old'],
                        'new' => is_string($change['new']) ? $this->iban->mask($change['new']) : $change['new'],
                    ];
                }
            }

            $result[$index] = ['target_system' => (string) $step['target_system'], 'action_type' => (string) $step['action_type'], 'diff' => $diff];
        }

        return $result;
    }

    /**
     * @param  array{steps: array<int, array<string, mixed>>, old: array<int, array<string, mixed>>, new: array<int, array<string, mixed>>, risk: RiskClass, leading: TargetSystem, warnings: array<int, string>, preconditions: array<int, array<string, mixed>>}  $prepared
     */
    private function createVersion(ActionPlan $plan, int $number, array $prepared, ?User $author, ?int $sourceMessageId, ?string $sourceHash, ?CarbonImmutable $effectiveDate, string $generatedBy, ?string $changeNote): ActionPlanVersion
    {
        $risk = $prepared['risk'];
        $leadingStep = $prepared['steps'][0];

        $version = new ActionPlanVersion;
        $version->forceFill([
            'action_plan_id' => $plan->getKey(),
            'version' => $number,
            'steps_json' => $prepared['steps'],
            'steps_hash' => $this->hasher->hash($prepared['steps']),
            'generated_by' => $generatedBy,
            'author_user_id' => $author?->getKey(),
            'change_note' => $changeNote,
            'target_system' => $prepared['leading'],
            'action_type' => $leadingStep['action_type'],
            'external_ref' => $leadingStep['external_ref'],
            'fields_json' => $leadingStep['fields'],
            'old_values' => $prepared['old'],
            'new_values' => $prepared['new'],
            'source_message_id' => $sourceMessageId,
            'source_hash' => $sourceHash,
            'risk_class' => $risk,
            'effective_date' => $effectiveDate?->toDateString(),
            'preconditions_json' => ['steps' => $prepared['preconditions'], 'warnings' => $prepared['warnings']],
            'required_approvals' => $this->policy->requiredApprovals($risk),
            'requires_identity_check' => $this->policy->requiresIdentityCheck($risk),
            'diff_hash' => $this->hasher->diffHash($prepared['steps'], $prepared['old'], $prepared['new']),
        ]);
        $version->save();

        foreach ($prepared['steps'] as $index => $step) {
            $target = new ActionTarget;
            $target->forceFill([
                'action_plan_version_id' => $version->getKey(),
                'step_index' => $index,
                'target_system' => $step['target_system'],
                'action_type' => $step['action_type'],
                'status' => ActionTarget::PENDING,
            ]);
            $target->save();
        }

        $plan->forceFill(['current_version_id' => $version->getKey(), 'status' => ActionStatus::Validated]);
        $plan->save();

        $this->outbox->write((int) $plan->getAttribute('organization_id'), 'plan.validated', 'action_plan', (int) $plan->getKey(), ['version' => $number, 'risk_class' => $risk->value]);

        return $version->fresh(['plan']) ?? $version;
    }

    /**
     * @param  array<string, mixed>  $ref
     * @param  array<string, mixed>  $newValues
     */
    private function validateBankStep(array $ref, array $newValues): void
    {
        if (! isset($ref['contract_id'], $ref['role']) || (string) $ref['role'] === '') {
            throw new ActionPolicyException('Bankänderung ohne Zuordnung des konkreten Bankdatenfelds (Vertrag und Rolle) ist nicht zulässig.', 'bank_field_unassigned');
        }

        $iban = $newValues['iban'] ?? null;

        if (! is_string($iban) || $iban === '') {
            throw new InvalidArgumentException('Bankänderung ohne neue IBAN.');
        }

        $errors = $this->iban->validate($iban);

        if ($errors !== []) {
            throw new InvalidArgumentException('Neue IBAN ungültig: '.implode(' ', $errors));
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function onlyFields(array $values, array $fields): array
    {
        $result = [];

        foreach ($fields as $field) {
            if (array_key_exists($field, $values)) {
                $result[$field] = $field === 'iban' && is_string($values[$field]) ? $this->iban->normalize($values[$field]) : $values[$field];
            }
        }

        return $result;
    }

    private function dateOf(?ActionPlanVersion $version): ?CarbonImmutable
    {
        $date = $version?->getAttribute('effective_date');

        return $date instanceof CarbonImmutable ? $date : null;
    }
}
