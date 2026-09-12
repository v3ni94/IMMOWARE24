<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Core\Support\GermanDate;
use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\MailUi\Support\BankDataMasker;
use Carbon\CarbonImmutable;

/**
 * Kommunikationsvorschlag nach Verifikation: erzeugt oder aktualisiert einen lokalen Antwortentwurf (generated_by
 * system, status local, nie an Gmail übertragen), der ausschließlich verifizierte Änderungen nennt. Offene, nur
 * ausgeführte oder manuell noch nicht bestätigte Schritte erscheinen nicht als erledigt.
 */
final class ReplyProposalService
{
    public const string GENERATED_BY = 'system';

    /**
     * @return array<int, array{system: string, action: string, fields: array<string, string>, verified_at: ?string}>
     */
    public function verifiedChanges(MailCase $case): array
    {
        $result = [];

        $plans = ActionPlan::query()->where('case_id', $case->getKey())->with('currentVersion')->orderBy('id')->get();

        foreach ($plans as $plan) {
            $version = $plan->currentVersion;

            if ($version === null) {
                continue;
            }

            $steps = $version->steps();
            $newValues = is_array($version->getAttribute('new_values')) ? $version->getAttribute('new_values') : [];

            $targetQuery = ActionTarget::query()->where('action_plan_version_id', $version->getKey())->where('status', ActionTarget::VERIFIED);
            $targetQuery->orderBy('step_index');
            $targets = $targetQuery->get();

            foreach ($targets as $target) {
                if (! $target instanceof ActionTarget) {
                    continue;
                }

                $index = (int) $target->getAttribute('step_index');
                $step = $steps[$index] ?? [];
                $fields = BankDataMasker::maskArray((array) ($newValues[$index] ?? ($step['new_values'] ?? [])), false);
                $verifiedAt = $target->getAttribute('updated_at');

                $result[] = [
                    'system' => (string) ($step['target_system'] ?? $target->getAttribute('target_system') ?? ''),
                    'action' => (string) ($step['action_type'] ?? ''),
                    'fields' => array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $fields),
                    'verified_at' => $verifiedAt !== null ? GermanDate::formatDateTime(CarbonImmutable::instance($verifiedAt)) : null,
                ];
            }
        }

        return $result;
    }

    public function proposeForCase(MailCase $case): ?MailDraft
    {
        $changes = $this->verifiedChanges($case);

        if ($changes === [] || $case->getAttribute('mailbox_id') === null) {
            return null;
        }

        $origin = $this->originMessage($case);
        $body = $this->body($case, $changes);

        $draft = MailDraft::query()->withoutGlobalScopes()
            ->where('case_id', $case->getKey())
            ->where('generated_by', self::GENERATED_BY)
            ->where('status', 'local')
            ->first();

        $attributes = [
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'mailbox_id' => $case->getAttribute('mailbox_id'),
            'reply_to_message_id' => $origin?->getKey(),
            'to_json' => $origin !== null && (string) $origin->getAttribute('from_address') !== '' ? [(string) $origin->getAttribute('from_address')] : [],
            'cc_json' => [],
            'subject' => 'Re: '.mb_substr((string) ($origin?->getAttribute('subject') ?? $case->getAttribute('title')), 0, 900),
            'body_text' => $body,
            'generated_by' => self::GENERATED_BY,
            'status' => 'local',
            'delivery_status' => 'unknown',
        ];

        if ($draft instanceof MailDraft) {
            $draft->forceFill($attributes)->save();

            return $draft;
        }

        return MailDraft::query()->create($attributes);
    }

    /**
     * @param  array<int, array{system: string, action: string, fields: array<string, string>, verified_at: ?string}>  $changes
     */
    private function body(MailCase $case, array $changes): string
    {
        $lines = ['Guten Tag,', '', 'zu Ihrem Anliegen (Vorgang '.(string) $case->getAttribute('case_number').') können wir folgende Änderungen als durchgeführt und geprüft bestätigen:', ''];

        foreach ($changes as $change) {
            $fields = [];

            foreach ($change['fields'] as $field => $value) {
                $fields[] = $field.': '.$value;
            }

            $lines[] = sprintf('- %s in %s (%s)%s', $this->actionLabel($change['action']), $this->systemLabel($change['system']), implode(', ', $fields), $change['verified_at'] !== null ? ', geprüft am '.$change['verified_at'] : '');
        }

        $lines[] = '';
        $lines[] = 'Weitere Punkte Ihres Anliegens sind noch in Bearbeitung; dazu erhalten Sie gesondert Nachricht.';
        $lines[] = '';
        $lines[] = 'Mit freundlichen Grüßen';

        return implode("\n", $lines);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'address_change' => 'Adressänderung',
            'bank_change' => 'Änderung der Bankverbindung',
            'contact_update' => 'Aktualisierung der Kontaktdaten',
            default => $action !== '' ? $action : 'Änderung',
        };
    }

    private function systemLabel(string $system): string
    {
        return match ($system) {
            'lexware' => 'Lexware Office',
            'immoware24' => 'Immoware24',
            'manual' => 'unseren Unterlagen',
            default => $system !== '' ? $system : 'unseren Systemen',
        };
    }

    private function originMessage(MailCase $case): ?MailMessage
    {
        $query = CaseMessage::query()->where('case_id', $case->getKey());
        $query->orderByRaw("CASE WHEN link_type = 'origin' THEN 0 ELSE 1 END")->orderBy('id');
        $link = $query->first();

        if (! $link instanceof CaseMessage) {
            return null;
        }

        $message = MailMessage::query()->withoutGlobalScopes()->find($link->getAttribute('message_id'));

        return $message instanceof MailMessage ? $message : null;
    }
}
