<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Modules\Actions\Models\ActionPlan;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Cases\Models\Task;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\MailUi\Support\BankDataMasker;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Auskunftsexport (DSGVO Art. 15) zu einem Kontakt: Nachrichten (Absender über sender_contact_id oder Adressen des
 * Kontakts), Vorgänge (Hauptkontakt oder verknüpfte Nachricht), Aufgaben und Aktionspläne dieser Vorgänge. Bankdaten
 * in Alt/Neu-Werten und IBAN-Muster in Texten sind maskiert (BankDataMasker). Ergebnis als JSON oder CSV auf der
 * konfigurierten Disk unterhalb von hub.mail.subject_access_export.path. Keine Zugangsdaten, keine Roh-Payloads.
 */
final class SubjectAccessExportService
{
    private const string IBAN_PATTERN = '/\b([A-Z]{2}\d{2}(?:\s?[A-Z0-9]{4}){2,7}(?:\s?[A-Z0-9]{1,4})?)\b/';

    public function __construct(
        private readonly Repository $config,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * @return array{contact: array<string, mixed>, messages: array<int, array<string, mixed>>, cases: array<int, array<string, mixed>>, tasks: array<int, array<string, mixed>>, plans: array<int, array<string, mixed>>}
     */
    public function build(Contact $contact): array
    {
        $organizationId = (int) $contact->getAttribute('organization_id');
        $emails = $this->emailsOf($contact);

        $messageQuery = MailMessage::query()->withoutGlobalScopes()->withTrashed()
            ->where('organization_id', $organizationId)
            ->where(static function (Builder $q) use ($contact, $emails): void {
                $q->where('sender_contact_id', $contact->getKey());

                foreach ($emails as $email) {
                    $q->orWhereRaw('LOWER(from_address) = ?', [$email]);
                    $q->orWhere('to_json', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], json_encode($email, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: $email).'%');
                }
            });
        $messageQuery->orderBy('received_at');
        $messages = $messageQuery->get();
        $messageIds = $messages->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $linkedCaseIds = $messageIds === [] ? [] : CaseMessage::query()->whereIn('message_id', $messageIds)->pluck('case_id')->map(static fn (mixed $id): int => (int) $id)->all();

        $caseQuery = MailCase::query()->withoutGlobalScopes()->withTrashed()
            ->where('organization_id', $organizationId)
            ->where(static function (Builder $q) use ($contact, $linkedCaseIds): void {
                $q->where('primary_contact_id', $contact->getKey());

                if ($linkedCaseIds !== []) {
                    $q->orWhereIn('id', $linkedCaseIds);
                }
            });
        $caseQuery->orderBy('opened_at');
        $cases = $caseQuery->get();
        $caseIds = $cases->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        // whereIn mit leerer Liste liefert keine Zeilen (Laravel setzt 0 = 1), kein Sonderfall nötig.
        $taskQuery = Task::query()->withoutGlobalScopes()->withTrashed()->whereIn('case_id', $caseIds);
        $taskQuery->orderBy('id');
        $tasks = $taskQuery->get();
        $planQuery = ActionPlan::query()->withoutGlobalScopes()->with('currentVersion')->whereIn('case_id', $caseIds);
        $planQuery->orderBy('id');
        $planRows = [];

        foreach ($planQuery->get() as $plan) {
            if ($plan instanceof ActionPlan) {
                $planRows[] = $this->planRow($plan);
            }
        }

        return [
            'contact' => [
                'id' => (int) $contact->getKey(),
                'source_system' => (string) $contact->getAttribute('source_system'),
                'external_id' => (string) $contact->getAttribute('external_id'),
                'display_name' => trim((string) $contact->getAttribute('first_name').' '.(string) $contact->getAttribute('last_name')),
                'emails' => $emails,
                'personal_data_erased_at' => $this->iso($contact->getAttribute('personal_data_erased_at')),
            ],
            'messages' => $messages->map(fn (MailMessage $m): array => $this->messageRow($m))->values()->all(),
            'cases' => $cases->map(fn (MailCase $c): array => $this->caseRow($c))->values()->all(),
            'tasks' => $tasks->map(fn (Task $t): array => $this->taskRow($t))->values()->all(),
            'plans' => $planRows,
        ];
    }

    /**
     * Schreibt den Export und liefert das Manifest (Pfade, Anzahl, Prüfsummen).
     *
     * @return array{export_uuid: string, disk: string, path: string, format: string, files: array<string, string>, counts: array<string, int>, created_at: string}
     */
    public function write(Contact $contact, string $exportUuid, string $format): array
    {
        if (! in_array($format, ['json', 'csv'], true)) {
            throw new InvalidArgumentException('Unbekanntes Exportformat: '.$format);
        }

        $data = $this->build($contact);
        $disk = (string) $this->config->get('hub.mail.subject_access_export.disk', 'local');
        $base = trim((string) $this->config->get('hub.mail.subject_access_export.path', 'mail-exports'), '/');
        $directory = $base.'/'.(int) $contact->getAttribute('organization_id').'/'.$exportUuid;
        $storage = $this->filesystems->disk($disk);
        $files = [];

        if ($format === 'json') {
            $path = $directory.'/auskunft.json';
            $content = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
            $storage->put($path, $content);
            $files[$path] = hash('sha256', $content);
        } else {
            foreach (['messages', 'cases', 'tasks', 'plans'] as $section) {
                $path = $directory.'/'.$section.'.csv';
                $content = $this->csv($data[$section]);
                $storage->put($path, $content);
                $files[$path] = hash('sha256', $content);
            }

            $path = $directory.'/kontakt.csv';
            $content = $this->csv([$data['contact']]);
            $storage->put($path, $content);
            $files[$path] = hash('sha256', $content);
        }

        $manifest = [
            'export_uuid' => $exportUuid,
            'disk' => $disk,
            'path' => $directory,
            'format' => $format,
            'files' => $files,
            'counts' => [
                'messages' => count($data['messages']),
                'cases' => count($data['cases']),
                'tasks' => count($data['tasks']),
                'plans' => count($data['plans']),
            ],
            'created_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        $storage->put($directory.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        return $manifest;
    }

    /**
     * @return array<int, string>
     */
    private function emailsOf(Contact $contact): array
    {
        $result = [];

        foreach ((array) $contact->getAttribute('emails') as $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;

            if (is_string($value) && trim($value) !== '') {
                $result[] = mb_strtolower(trim($value));
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(MailMessage $message): array
    {
        $to = [];

        foreach ((array) ($message->getAttribute('to_json') ?? []) as $recipient) {
            $to[] = is_array($recipient) ? (string) ($recipient['email'] ?? '') : (string) $recipient;
        }

        return [
            'id' => (int) $message->getKey(),
            'gmail_message_id' => (string) $message->getAttribute('gmail_message_id'),
            'direction' => (string) $message->getAttribute('direction'),
            'from_address' => (string) $message->getAttribute('from_address'),
            'from_name' => (string) $message->getAttribute('from_name'),
            'to' => implode(', ', array_filter($to)),
            'subject' => $this->maskText((string) $message->getAttribute('subject')),
            'received_at' => $this->iso($message->getAttribute('received_at')),
            'has_attachments' => (bool) $message->getAttribute('has_attachments'),
            'body_text' => $this->maskText((string) ($message->getAttribute('body_text') ?? '')),
            'processing_status' => (string) $message->getAttribute('processing_status'),
            'deleted_at' => $this->iso($message->getAttribute('deleted_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function caseRow(MailCase $case): array
    {
        return [
            'id' => (int) $case->getKey(),
            'case_number' => (string) $case->getAttribute('case_number'),
            'title' => $this->maskText((string) $case->getAttribute('title')),
            'case_type' => (string) $case->getAttribute('case_type'),
            'priority' => $this->enumValue($case->getAttribute('priority')),
            'status_processing' => $this->enumValue($case->getAttribute('status_processing')),
            'status_communication' => $this->enumValue($case->getAttribute('status_communication')),
            'status_business' => $this->enumValue($case->getAttribute('status_business')),
            'opened_at' => $this->iso($case->getAttribute('opened_at')),
            'closed_at' => $this->iso($case->getAttribute('closed_at')),
            'legal_hold_at' => $this->iso($case->getAttribute('legal_hold_at')),
            'ai_summary' => $this->maskText((string) ($case->getAttribute('ai_summary') ?? '')),
            'deleted_at' => $this->iso($case->getAttribute('deleted_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taskRow(Task $task): array
    {
        return [
            'id' => (int) $task->getKey(),
            'case_id' => (int) $task->getAttribute('case_id'),
            'task_type' => (string) $task->getAttribute('task_type'),
            'title' => $this->maskText((string) $task->getAttribute('title')),
            'status' => (string) $task->getAttribute('status'),
            'target_system' => (string) ($task->getAttribute('target_system') ?? ''),
            'old_values' => json_encode(BankDataMasker::maskArray((array) ($task->getAttribute('old_value_json') ?? [])), JSON_UNESCAPED_UNICODE) ?: '{}',
            'new_values' => json_encode(BankDataMasker::maskArray((array) ($task->getAttribute('new_value_json') ?? [])), JSON_UNESCAPED_UNICODE) ?: '{}',
            'due_at' => $this->iso($task->getAttribute('due_at')),
            'confirmed_at' => $this->iso($task->getAttribute('confirmed_at')),
            'deleted_at' => $this->iso($task->getAttribute('deleted_at')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function planRow(ActionPlan $plan): array
    {
        $version = $plan->currentVersion;

        return [
            'id' => (int) $plan->getKey(),
            'case_id' => (int) $plan->getAttribute('case_id'),
            'status' => $this->enumValue($plan->getAttribute('status')),
            'risk_class' => $this->enumValue($plan->getAttribute('risk_class')),
            'target_system' => $this->enumValue($plan->getAttribute('target_system')),
            'version' => $version !== null ? (int) $version->getAttribute('version') : null,
            'action_type' => $version !== null ? (string) ($version->getAttribute('action_type') ?? '') : '',
            'old_values' => json_encode(BankDataMasker::maskArray((array) ($version?->getAttribute('old_values') ?? [])), JSON_UNESCAPED_UNICODE) ?: '{}',
            'new_values' => json_encode(BankDataMasker::maskArray((array) ($version?->getAttribute('new_values') ?? [])), JSON_UNESCAPED_UNICODE) ?: '{}',
            'created_at' => $this->iso($plan->getAttribute('created_at')),
        ];
    }

    /**
     * IBAN-Muster in Freitext maskieren (Betreff, Body, Titel, Zusammenfassung).
     */
    private function maskText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        return (string) preg_replace_callback(self::IBAN_PATTERN, static function (array $m): string {
            $clean = preg_replace('/\s+/', '', $m[1]) ?? '';

            return mb_strlen($clean) >= 15 ? BankDataMasker::maskIban($clean).' (maskiert)' : $m[1];
        }, $text);
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) ($value ?? '');
    }

    private function iso(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->toIso8601String() : null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function csv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, array_keys($rows[0]), ';', '"', '\\');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn (mixed $v): string => is_bool($v) ? ($v ? 'ja' : 'nein') : (is_array($v) ? implode(', ', $v) : (string) ($v ?? '')), $row), ';', '"', '\\');
        }

        rewind($handle);
        $content = (string) stream_get_contents($handle);
        fclose($handle);

        return "\xEF\xBB\xBF".$content;
    }
}
