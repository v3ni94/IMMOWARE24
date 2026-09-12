<?php

declare(strict_types=1);

namespace App\Modules\Cases\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Modules\Cases\Models\CaseItem;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Models\MailCase;
use Carbon\CarbonImmutable;

/**
 * Protokolliert jeden Zwischenzustand in mail_case_status_log und spiegelt ihn in das append-only Auditlog.
 */
final class CaseStatusLogger
{
    public function __construct(private readonly AuditLoggerInterface $audit) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(MailCase $case, string $dimension, ?string $from, string $to, ?string $reason = null, ?int $actorId = null, ?CaseItem $item = null, string $source = 'user', array $context = []): CaseStatusLog
    {
        $entry = CaseStatusLog::query()->create([
            'case_id' => $case->getKey(),
            'case_item_id' => $item?->getKey(),
            'dimension' => $dimension,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null,
            'changed_by' => $actorId,
            'source' => $source,
            'context_json' => $context !== [] ? $context : null,
            'changed_at' => CarbonImmutable::now(),
        ]);

        $this->audit->log(
            'mail.case.'.$dimension,
            $item ?? $case,
            ['status' => $from],
            ['status' => $to, 'reason' => $reason, 'case_id' => $case->getKey(), 'case_item_id' => $item?->getKey()] + $context,
            $source === 'gmail' ? AuditSource::GmailPush->value : ($source === 'ai' ? AuditSource::Ai->value : AuditSource::Mail->value),
        );

        return $entry;
    }
}
