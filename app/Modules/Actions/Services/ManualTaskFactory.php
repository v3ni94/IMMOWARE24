<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Support\IbanValidator;
use App\Modules\Cases\Models\Task;
use App\Modules\Sync\Models\ProposedChange;
use App\Modules\Sync\Services\ProposedChangeService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Erzeugt manuelle Aufgaben (mail_tasks) mit Alt/Neu (verschlüsselt), Quelle und optionalem Deep-Link sowie den
 * bestehenden proposed_changes-Rückweg des Sync-Moduls. Deep-Links nur bei konfiguriertem Muster, nichts erfinden.
 */
final class ManualTaskFactory
{
    public function __construct(
        private readonly ProposedChangeService $proposedChanges,
        private readonly IbanValidator $iban,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array{task: Task, proposed_change: ?ProposedChange}
     */
    public function create(ActionPlanVersion $version, int $stepIndex, array $step, array $old, array $new, TargetSystem $system, ?int $executedBy): array
    {
        $plan = $version->plan;
        $ref = (array) ($step['external_ref'] ?? []);
        $type = (string) ($step['action_type'] ?? 'manual_task');
        $organizationId = (int) $plan?->getAttribute('organization_id');
        $deepLink = $this->deepLink($system, $ref);
        $sourceMessageId = $version->getAttribute('source_message_id');

        $lines = [
            sprintf('Manuelle Änderung in %s (%s). Bitte im Zielsystem umsetzen und anschließend hier als erledigt bestätigen.', $system->label(), $this->typeLabel($type)),
            sprintf('Quelle: Aktionsplan %d, Version %d%s.', (int) $plan?->getKey(), (int) $version->getAttribute('version'), $sourceMessageId !== null ? ', Nachricht '.$sourceMessageId : ''),
        ];

        if (isset($ref['reference_type'], $ref['external_id'])) {
            $lines[] = sprintf('Referenz: %s %s.', (string) $ref['reference_type'], (string) $ref['external_id']);
        }

        if ($deepLink !== null) {
            $lines[] = 'Link: '.$deepLink;
        }

        if ($type === 'bank_change') {
            $lines[] = 'Bankdaten werden in der Aufgabe maskiert dargestellt und erst nach Bankdatenrecht angezeigt.';
        }

        $task = new Task;
        $task->forceFill([
            'organization_id' => $organizationId,
            'case_id' => $plan?->getAttribute('case_id'),
            'case_item_id' => $plan?->getAttribute('case_item_id'),
            'task_type' => $system === TargetSystem::Lexware ? 'manual_change_lexware' : ($system === TargetSystem::Immoware24 ? 'manual_change_immoware' : 'other'),
            'title' => mb_substr(sprintf('%s: %s manuell umsetzen', $system->label(), $this->typeLabel($type)), 0, 300),
            'instructions' => implode("\n", $lines),
            'old_value_json' => $old,
            'new_value_json' => $new,
            'target_system' => $system->value,
            'assignee_user_id' => $plan?->case?->getAttribute('assignee_user_id') ?? $executedBy,
            'due_at' => CarbonImmutable::now()->addDays(2),
            'status' => ManualTaskService::TASK_OPEN,
            'created_by' => $executedBy,
        ]);
        $task->save();

        $change = null;

        if ($system === TargetSystem::Immoware24 && isset($ref['reference_type']) && (int) ($ref['local_id'] ?? 0) > 0) {
            $entityType = (string) $ref['reference_type'];
            $field = implode(',', array_keys($new));
            $change = $this->proposedChanges->propose(
                $entityType,
                (int) $ref['local_id'],
                $field !== '' ? mb_substr($field, 0, 120) : $type,
                $this->maskForProposal($old),
                $this->maskForProposal($new),
                null,
                isset($ref['connection_id']) ? (int) $ref['connection_id'] : null,
                $organizationId,
                sprintf('Mail-Vorgang, Aktionsplan %d Schritt %d (%s).', (int) $plan?->getKey(), $stepIndex + 1, $type),
            );

            $task->forceFill(['proposed_change_id' => $change->getKey()]);
            $task->save();
        }

        return ['task' => $task, 'proposed_change' => $change];
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    public function deepLink(TargetSystem $system, array $ref): ?string
    {
        if ($system !== TargetSystem::Immoware24) {
            return null;
        }

        $pattern = $this->config->get('hub.actions.immoware_deep_link_pattern');

        if (! is_string($pattern) || trim($pattern) === '' || ! isset($ref['external_id'])) {
            return null;
        }

        return str_replace(['{external_id}', '{reference_type}'], [rawurlencode((string) $ref['external_id']), rawurlencode((string) ($ref['reference_type'] ?? ''))], $pattern);
    }

    public function typeLabel(string $type): string
    {
        return match ($type) {
            'address_change' => 'Adressänderung',
            'bank_change' => 'Bankdatenänderung',
            'note' => 'Notiz',
            default => 'Aufgabe',
        };
    }

    /**
     * proposed_changes speichert Klartext; IBAN dort nur maskiert (Klartext liegt verschlüsselt in mail_tasks).
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function maskForProposal(array $values): array
    {
        if (isset($values['iban']) && is_string($values['iban'])) {
            $values['iban'] = $this->iban->mask($values['iban']);
        }

        return $values;
    }
}
