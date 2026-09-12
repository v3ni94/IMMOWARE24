<?php

declare(strict_types=1);

namespace App\Modules\MailIntegration\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailAttachment;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Models\PushEvent;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\MailUi\Services\OrgSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Aufbewahrungsregeln (config hub.mail.retention, je Organisation überschreibbar über mail_org_settings, Schlüssel
 * retention). Regeln: Nachrichtentexte entfernen, Anhänge löschen (Datei plus Soft Delete), KI-Läufe entfernen,
 * Push-Ereignisse entfernen, abgeschlossene Vorgänge soft löschen. Sperrfälle: ein Vorgang mit legal_hold_at
 * schützt sich selbst, seine Nachrichten, Anhänge und KI-Läufe; Nachrichten offener Vorgänge bleiben ebenfalls
 * unangetastet. Kein Hard Delete auf Vorgangs- oder Nachrichtenzeilen, nur Inhaltsentfernung und deleted_at.
 * Jeder Lauf (auch Vorschau) wird auditiert.
 */
final class RetentionService
{
    public const string RULE_MESSAGES = 'messages';

    public const string RULE_ATTACHMENTS = 'attachments';

    public const string RULE_AI_RUNS = 'ai_runs';

    public const string RULE_PUSH_EVENTS = 'push_events';

    public const string RULE_CLOSED_CASES = 'closed_cases';

    /** @var array<string, string> */
    public const array RULE_LABELS = [
        self::RULE_MESSAGES => 'Nachrichtentexte',
        self::RULE_ATTACHMENTS => 'Anhänge',
        self::RULE_AI_RUNS => 'KI-Läufe',
        self::RULE_PUSH_EVENTS => 'Push-Ereignisse',
        self::RULE_CLOSED_CASES => 'Abgeschlossene Vorgänge',
    ];

    /** Mindestfrist in Tagen, unter die keine Regel fallen darf (Schutz vor Fehlkonfiguration). */
    private const int MIN_DAYS = 7;

    public function __construct(
        private readonly Repository $config,
        private readonly OrgSettings $settings,
        private readonly FilesystemFactory $filesystems,
        private readonly AuditLoggerInterface $audit,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('hub.mail.retention.enabled', false);
    }

    /**
     * @return array<int, int>
     */
    public function organizationIds(): array
    {
        return Mailbox::query()->distinct()->orderBy('organization_id')->pluck('organization_id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Wirksame Fristen je Organisation: Konfiguration, überschrieben durch die Organisationseinstellung.
     *
     * @return array<string, int>
     */
    public function daysFor(int $organizationId): array
    {
        $defaults = (array) $this->config->get('hub.mail.retention', []);
        $override = $this->settings->get($organizationId, OrgSettings::RETENTION);
        $result = [];

        foreach (array_keys(self::RULE_LABELS) as $rule) {
            $key = $rule.'_days';
            $value = isset($override[$key]) && (int) $override[$key] > 0 ? (int) $override[$key] : (int) ($defaults[$key] ?? 0);
            $result[$rule] = max(self::MIN_DAYS, $value);
        }

        return $result;
    }

    /**
     * Wendet alle Regeln für eine Organisation an. Bei $dryRun werden nur Kandidaten gezählt.
     *
     * @return array<string, array{label: string, days: int, cutoff: string, candidates: int, applied: int, held: int}>
     */
    public function apply(int $organizationId, bool $dryRun = true, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $days = $this->daysFor($organizationId);
        $chunk = max(50, (int) $this->config->get('hub.mail.retention.chunk', 500));
        $result = [];

        $result[self::RULE_MESSAGES] = $this->applyMessages($organizationId, $now->subDays($days[self::RULE_MESSAGES]), $dryRun, $chunk) + ['days' => $days[self::RULE_MESSAGES]];
        $result[self::RULE_ATTACHMENTS] = $this->applyAttachments($organizationId, $now->subDays($days[self::RULE_ATTACHMENTS]), $dryRun, $chunk) + ['days' => $days[self::RULE_ATTACHMENTS]];
        $result[self::RULE_AI_RUNS] = $this->applyAiRuns($organizationId, $now->subDays($days[self::RULE_AI_RUNS]), $dryRun, $chunk) + ['days' => $days[self::RULE_AI_RUNS]];
        $result[self::RULE_CLOSED_CASES] = $this->applyClosedCases($organizationId, $now->subDays($days[self::RULE_CLOSED_CASES]), $dryRun, $chunk) + ['days' => $days[self::RULE_CLOSED_CASES]];

        foreach ($result as $rule => $row) {
            $result[$rule]['label'] = self::RULE_LABELS[$rule];
        }

        $this->audit->log(
            $dryRun ? 'mail.retention.dry_run' : 'mail.retention.applied',
            null,
            [],
            ['organization_id' => $organizationId, 'rules' => $result],
            AuditSource::System->value,
        );

        return $result;
    }

    /**
     * Push-Ereignisse sind nicht organisationsgebunden und werden einmal je Lauf bereinigt.
     *
     * @return array{label: string, days: int, cutoff: string, candidates: int, applied: int, held: int}
     */
    public function applyPushEvents(bool $dryRun = true, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $days = max(self::MIN_DAYS, (int) $this->config->get('hub.mail.retention.push_events_days', 30));
        $cutoff = $now->subDays($days);
        $chunk = max(50, (int) $this->config->get('hub.mail.retention.chunk', 500));
        $query = PushEvent::query()->where('received_at', '<', $cutoff);
        $candidates = (clone $query)->count();
        $applied = 0;

        if (! $dryRun) {
            do {
                $ids = (clone $query)->orderBy('id')->limit($chunk)->pluck('id');
                $deleted = $ids->isEmpty() ? 0 : PushEvent::query()->whereIn('id', $ids->all())->delete();
                $applied += $deleted;
            } while ($deleted > 0 && $deleted >= $chunk);
        }

        $row = ['label' => self::RULE_LABELS[self::RULE_PUSH_EVENTS], 'days' => $days, 'cutoff' => $cutoff->toIso8601String(), 'candidates' => $candidates, 'applied' => $applied, 'held' => 0];

        $this->audit->log(
            $dryRun ? 'mail.retention.dry_run' : 'mail.retention.applied',
            null,
            [],
            ['organization_id' => null, 'rules' => [self::RULE_PUSH_EVENTS => $row]],
            AuditSource::System->value,
        );

        return $row;
    }

    /**
     * Nachrichten, die durch einen Vorgang geschützt sind: Legal Hold oder Vorgang nicht geschlossen.
     */
    private function protectedMessageIds(): QueryBuilder
    {
        return DB::table('mail_case_messages')
            ->join('mail_cases', 'mail_cases.id', '=', 'mail_case_messages.case_id')
            ->where(static function ($q): void {
                $q->whereNotNull('mail_cases.legal_hold_at')
                    ->orWhere('mail_cases.status_processing', '!=', CaseStatus::Closed->value);
            })
            ->select('mail_case_messages.message_id');
    }

    /**
     * @return array{cutoff: string, candidates: int, applied: int, held: int}
     */
    private function applyMessages(int $organizationId, CarbonImmutable $cutoff, bool $dryRun, int $chunk): array
    {
        $base = MailMessage::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('received_at', '<', $cutoff)
            ->where(static function ($q): void {
                $q->whereNotNull('body_text')->orWhereNotNull('body_html_sanitized');
            });

        $held = (clone $base)->whereIn('id', $this->protectedMessageIds())->count();
        $query = (clone $base)->whereNotIn('id', $this->protectedMessageIds());
        $candidates = (clone $query)->count();
        $applied = 0;

        if (! $dryRun) {
            (clone $query)->select(['id'])->chunkById($chunk, function ($messages) use (&$applied): void {
                $ids = $messages->pluck('id')->all();
                $applied += MailMessage::query()->withoutGlobalScopes()->whereIn('id', $ids)->update([
                    'body_text' => null,
                    'body_html_sanitized' => null,
                    'snippet' => null,
                    'updated_at' => now(),
                ]);
            });
        }

        return ['cutoff' => $cutoff->toIso8601String(), 'candidates' => $candidates, 'applied' => $applied, 'held' => $held];
    }

    /**
     * @return array{cutoff: string, candidates: int, applied: int, held: int}
     */
    private function applyAttachments(int $organizationId, CarbonImmutable $cutoff, bool $dryRun, int $chunk): array
    {
        $oldMessages = MailMessage::query()->withoutGlobalScopes()->withTrashed()
            ->where('organization_id', $organizationId)
            ->where('received_at', '<', $cutoff)
            ->select('id');

        $base = MailAttachment::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('message_id', $oldMessages);

        $held = (clone $base)->whereIn('message_id', $this->protectedMessageIds())->count();
        $query = (clone $base)->whereNotIn('message_id', $this->protectedMessageIds());
        $candidates = (clone $query)->count();
        $applied = 0;

        if (! $dryRun) {
            (clone $query)->select(['id', 'storage_disk', 'storage_path'])->chunkById($chunk, function ($attachments) use (&$applied): void {
                foreach ($attachments as $attachment) {
                    if ($attachment instanceof MailAttachment) {
                        $this->deleteFile((string) $attachment->getAttribute('storage_disk'), (string) $attachment->getAttribute('storage_path'));
                    }
                }

                $applied += MailAttachment::query()->withoutGlobalScopes()->whereIn('id', $attachments->pluck('id')->all())->update([
                    'storage_disk' => null,
                    'storage_path' => null,
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        return ['cutoff' => $cutoff->toIso8601String(), 'candidates' => $candidates, 'applied' => $applied, 'held' => $held];
    }

    private function deleteFile(string $disk, string $path): void
    {
        if ($disk === '' || $path === '') {
            return;
        }

        try {
            $this->filesystems->disk($disk)->delete($path);
        } catch (Throwable $e) {
            Log::warning('Aufbewahrung: Anhangdatei konnte nicht gelöscht werden.', ['disk' => $disk, 'error' => $e::class]);
        }
    }

    /**
     * @return array{cutoff: string, candidates: int, applied: int, held: int}
     */
    private function applyAiRuns(int $organizationId, CarbonImmutable $cutoff, bool $dryRun, int $chunk): array
    {
        $heldCases = MailCase::query()->withoutGlobalScopes()->withTrashed()->whereNotNull('legal_hold_at')->select('id');

        $base = AiRun::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<', $cutoff);

        $held = (clone $base)->whereIn('case_id', $heldCases)->count();
        $query = (clone $base)->where(static function ($q) use ($heldCases): void {
            $q->whereNull('case_id')->orWhereNotIn('case_id', $heldCases);
        });
        $candidates = (clone $query)->count();
        $applied = 0;

        if (! $dryRun) {
            do {
                $ids = (clone $query)->orderBy('id')->limit($chunk)->pluck('id');
                $deleted = $ids->isEmpty() ? 0 : AiRun::query()->withoutGlobalScopes()->whereIn('id', $ids->all())->delete();
                $applied += $deleted;
            } while ($deleted > 0 && $deleted >= $chunk);
        }

        return ['cutoff' => $cutoff->toIso8601String(), 'candidates' => $candidates, 'applied' => $applied, 'held' => $held];
    }

    /**
     * Soft Delete abgeschlossener Vorgänge samt Teilanliegen und Aufgaben. Legal Hold schützt den Vorgang vollständig.
     *
     * @return array{cutoff: string, candidates: int, applied: int, held: int}
     */
    private function applyClosedCases(int $organizationId, CarbonImmutable $cutoff, bool $dryRun, int $chunk): array
    {
        $base = MailCase::query()->withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('status_processing', CaseStatus::Closed->value)
            ->whereNotNull('closed_at')
            ->where('closed_at', '<', $cutoff)
            ->whereNull('deleted_at');

        $held = (clone $base)->whereNotNull('legal_hold_at')->count();
        $query = (clone $base)->whereNull('legal_hold_at');
        $candidates = (clone $query)->count();
        $applied = 0;

        if (! $dryRun) {
            (clone $query)->select(['id'])->chunkById($chunk, function ($cases) use (&$applied): void {
                $ids = $cases->pluck('id')->all();
                $stamp = now();
                DB::table('mail_case_items')->whereIn('case_id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $stamp]);
                DB::table('mail_tasks')->whereIn('case_id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $stamp]);
                $applied += DB::table('mail_cases')->whereIn('id', $ids)->whereNull('deleted_at')->update(['deleted_at' => $stamp, 'updated_at' => $stamp]);
            });
        }

        return ['cutoff' => $cutoff->toIso8601String(), 'candidates' => $candidates, 'applied' => $applied, 'held' => $held];
    }
}
