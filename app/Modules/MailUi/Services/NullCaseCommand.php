<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Services;

use App\Core\Support\GermanDate;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\MailUi\Contracts\CaseCommandInterface;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Null-Implementierung bis zur Verdrahtung des Moduls Cases: schreibt Modelle direkt und protokolliert jede Änderung
 * in mail_case_status_log. Statuswechsel nur entlang CaseStatus::allowedTransitions(). Uhren, Eskalation und
 * Vertretungslogik übernimmt später das Modul Sla.
 */
final class NullCaseCommand implements CaseCommandInterface
{
    public function assign(MailCase $case, ?User $assignee, User $actor, ?string $nextStep = null, ?string $dueAt = null): WorkflowResult
    {
        $due = $dueAt !== null && $dueAt !== '' ? self::parseBerlin($dueAt) : null;

        if ($dueAt !== null && $dueAt !== '' && $due === null) {
            return WorkflowResult::failed('Fälligkeit nicht lesbar (Format TT.MM.JJJJ HH:MM).');
        }

        return DB::transaction(function () use ($case, $assignee, $actor, $nextStep, $due): WorkflowResult {
            $before = $case->getAttribute('assignee_user_id');
            $changes = ['assignee_user_id' => $assignee?->getKey()];

            if ($nextStep !== null && $nextStep !== '') {
                $changes['next_step'] = $nextStep;
            }

            if ($due !== null) {
                $changes['due_at'] = $due;
            }

            if ($assignee !== null && in_array($case->status_processing, [CaseStatus::New, CaseStatus::AssignmentOpen], true)) {
                $changes['status_processing'] = CaseStatus::Open;
                $this->log($case, 'processing', $case->status_processing->value, CaseStatus::Open->value, 'Zuweisung', $actor);
            }

            $case->forceFill($changes)->save();
            $this->log($case, 'assignment', $before !== null ? (string) $before : null, (string) ($assignee?->getKey() ?? ''), 'Zuweisung durch Oberfläche', $actor);

            $missing = $this->missingMandatoryFields($case);

            return $missing === []
                ? WorkflowResult::ok($assignee === null ? 'Zuweisung entfernt.' : 'Vorgang zugewiesen an '.$assignee->getAttribute('name').'.')
                : WorkflowResult::ok('Zuweisung gespeichert. Unvollständig: '.implode(', ', $missing).'.');
        });
    }

    public function setCategory(MailCase $case, string $caseType, User $actor): WorkflowResult
    {
        $before = (string) $case->getAttribute('case_type');
        $case->forceFill(['case_type' => $caseType])->save();
        $this->log($case, 'category', $before, $caseType, 'Kategorie durch Oberfläche', $actor);

        return WorkflowResult::ok('Kategorie gesetzt.');
    }

    public function setProcessingStatus(MailCase $case, string $status, User $actor, ?string $reason = null): WorkflowResult
    {
        $target = CaseStatus::tryFrom($status);

        if ($target === null) {
            return WorkflowResult::failed('Unbekannter Bearbeitungsstatus.');
        }

        if (! $case->status_processing->canTransitionTo($target)) {
            return WorkflowResult::failed('Übergang von "'.$case->status_processing->label().'" nach "'.$target->label().'" ist nicht erlaubt.');
        }

        if ($target === CaseStatus::Resolved || $target === CaseStatus::Closed) {
            return WorkflowResult::unavailable('Abschluss prüft das Modul Vorgänge (Abschlussbedingungen, Kommunikation, Ergebnis). Beantwortet ist nicht erledigt.');
        }

        $from = $case->status_processing->value;
        $case->forceFill(['status_processing' => $target])->save();
        $this->log($case, 'processing', $from, $target->value, $reason ?? 'Statuswechsel durch Oberfläche', $actor);

        return WorkflowResult::ok('Bearbeitungsstatus: '.$target->label().'.');
    }

    public function createInternalTask(MailCase $case, array $data, User $actor): WorkflowResult
    {
        $due = $data['due_at'] !== null && $data['due_at'] !== '' ? self::parseBerlin($data['due_at']) : null;

        $task = Task::query()->create([
            'organization_id' => $case->getAttribute('organization_id'),
            'case_id' => $case->getKey(),
            'task_type' => 'internal',
            'title' => $data['title'],
            'instructions' => $data['instructions'],
            'target_system' => 'manual',
            'assignee_user_id' => $data['assignee_user_id'],
            'due_at' => $due,
            'status' => 'open',
            'created_by' => $actor->getKey(),
        ]);

        return WorkflowResult::ok('Interne Aufgabe angelegt.', (int) $task->getKey());
    }

    public function addInternalNote(MailCase $case, string $note, User $actor): WorkflowResult
    {
        $this->log($case, 'note', null, 'note', $note, $actor);

        return WorkflowResult::ok('Interne Notiz gespeichert (nie Teil einer Antwort).');
    }

    /**
     * @return array<int, string>
     */
    public function missingMandatoryFields(MailCase $case): array
    {
        if (! $case->status_processing->isOpen()) {
            return [];
        }

        $missing = [];

        if ($case->getAttribute('assignee_user_id') === null) {
            $missing[] = 'Verantwortlicher';
        }

        if (trim((string) $case->getAttribute('next_step')) === '') {
            $missing[] = 'nächster Schritt';
        }

        if ($case->getAttribute('due_at') === null) {
            $missing[] = 'Fälligkeit';
        }

        return $missing;
    }

    /**
     * Eingabe TT.MM.JJJJ HH:MM in Europe/Berlin, Speicherung UTC.
     */
    public static function parseBerlin(string $value): ?CarbonImmutable
    {
        $value = trim($value);

        try {
            if (preg_match('/^\d{1,2}\.\d{1,2}\.\d{4}(?: \d{1,2}:\d{2})?$/', $value) === 1) {
                $format = str_contains($value, ' ') ? 'd.m.Y H:i' : '!d.m.Y';
                $date = CarbonImmutable::createFromFormat($format, $value, GermanDate::TIMEZONE);

                return $date?->utc();
            }

            if (preg_match('/^\d{4}-\d{2}-\d{2}(?:T\d{2}:\d{2})?$/', $value) === 1) {
                return CarbonImmutable::parse($value, GermanDate::TIMEZONE)->utc();
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    private function log(MailCase $case, string $dimension, ?string $from, string $to, string $reason, User $actor): void
    {
        CaseStatusLog::query()->create([
            'case_id' => $case->getKey(),
            'dimension' => $dimension,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => mb_substr($reason, 0, 500),
            'changed_by' => $actor->getKey(),
            'source' => 'user',
            'changed_at' => now(),
        ]);
    }
}
