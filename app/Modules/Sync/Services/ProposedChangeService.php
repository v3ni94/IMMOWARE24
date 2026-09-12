<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Enums\ProposedChangeStatus;
use App\Modules\Sync\Models\ProposedChange;
use App\Modules\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Rückweg für Änderungswünsche: Der Hub schreibt nie nach Immoware24. Wünsche werden erfasst,
 * manuell im Mastersystem umgesetzt und durch den nächsten Sync bestätigt.
 */
final class ProposedChangeService
{
    public function __construct(
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    public function propose(
        string $entityType,
        int $entityId,
        string $field,
        mixed $oldValue,
        mixed $newValue,
        ?User $requestedBy = null,
        ?int $connectionId = null,
        ?int $organizationId = null,
        ?string $reason = null,
    ): ProposedChange {
        if (trim($field) === '') {
            throw new InvalidArgumentException('Feldname darf nicht leer sein.');
        }

        $change = new ProposedChange;
        $change->forceFill([
            'organization_id' => $organizationId ?? $requestedBy?->getAttribute('organization_id'),
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'field' => $field,
            'old_value' => $this->stringify($oldValue),
            'new_value' => $this->stringify($newValue),
            'reason' => $reason,
            'status' => ProposedChangeStatus::Open,
            'requested_by' => $requestedBy?->getKey(),
            'correlation_id' => $this->correlationId->current(),
        ]);
        $change->save();

        $this->audit->log('proposed_change.created', $change, [], ['field' => $field, 'entity_type' => $entityType, 'entity_id' => $entityId], AuditSource::User->value, $this->correlationId->current());

        return $change;
    }

    /**
     * Mitarbeiter hat die Änderung in Immoware24 vorgenommen.
     */
    public function markTransferred(int $id, User $user): ProposedChange
    {
        $change = $this->find($id);

        if ($change->getAttribute('status') !== ProposedChangeStatus::Open) {
            throw new InvalidArgumentException(sprintf('Änderungswunsch %d ist nicht offen.', $id));
        }

        $change->forceFill([
            'status' => ProposedChangeStatus::Transferred,
            'transferred_at' => CarbonImmutable::now(),
            'transferred_by' => $user->getKey(),
        ]);
        $change->save();

        $this->audit->log('proposed_change.transferred', $change, ['status' => ProposedChangeStatus::Open->value], ['status' => ProposedChangeStatus::Transferred->value], AuditSource::User->value, $this->correlationId->current());

        return $change;
    }

    /**
     * Der nächste Sync hat den neuen Wert im Spiegel bestätigt.
     */
    public function confirm(int $id, SyncRun $run): ProposedChange
    {
        $change = $this->find($id);
        $change->forceFill([
            'status' => ProposedChangeStatus::Confirmed,
            'confirmed_by_sync_run_id' => $run->getKey(),
            'confirmed_at' => CarbonImmutable::now(),
        ]);
        $change->save();

        $this->audit->log('proposed_change.confirmed', $change, [], ['sync_run_id' => $run->getKey()], AuditSource::ImmowareSync->value, $this->correlationId->current());

        return $change;
    }

    public function reject(int $id, User $user, ?string $reason = null): ProposedChange
    {
        $change = $this->find($id);
        $change->forceFill([
            'status' => ProposedChangeStatus::Rejected,
            'rejected_at' => CarbonImmutable::now(),
            'reason' => $reason ?? $change->getAttribute('reason'),
        ]);
        $change->save();

        $this->audit->log('proposed_change.rejected', $change, [], ['by' => $user->getKey(), 'reason' => $reason], AuditSource::User->value, $this->correlationId->current());

        return $change;
    }

    private function find(int $id): ProposedChange
    {
        /** @var ProposedChange|null $change */
        $change = ProposedChange::query()->find($id);

        if ($change === null) {
            throw new InvalidArgumentException(sprintf('Änderungswunsch %d existiert nicht.', $id));
        }

        return $change;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
