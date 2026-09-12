<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Enums\ConflictResolution;
use App\Modules\Sync\Models\Conflict;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Manuelle Auflösung von Konflikten durch die Rolle operator, mit Audit.
 */
final class ConflictService
{
    public function __construct(
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    public function resolve(int $id, ConflictResolution|string $resolution, User $user, ?string $note = null): Conflict
    {
        $resolution = $resolution instanceof ConflictResolution ? $resolution : ConflictResolution::tryFrom($resolution);

        if ($resolution === null) {
            throw new InvalidArgumentException('Auflösung muss local, remote oder manual sein.');
        }

        /** @var Conflict|null $conflict */
        $conflict = Conflict::query()->find($id);

        if ($conflict === null) {
            throw new InvalidArgumentException(sprintf('Konflikt %d existiert nicht.', $id));
        }

        if (! in_array($conflict->getAttribute('status'), Conflict::OPEN_STATUSES, true)) {
            throw new InvalidArgumentException(sprintf('Konflikt %d ist bereits geschlossen.', $id));
        }

        $before = ['status' => $conflict->getAttribute('status')];

        $conflict->forceFill([
            'status' => $resolution->status(),
            'resolved_by' => $user->getKey(),
            'resolved_at' => CarbonImmutable::now(),
            'resolution_note' => $note,
        ]);
        $conflict->save();

        $this->audit->log(
            'conflict.resolved',
            $conflict,
            $before,
            ['status' => $resolution->status(), 'resolution' => $resolution->value, 'note' => $note],
            AuditSource::User->value,
            $this->correlationId->current(),
        );

        return $conflict;
    }

    public function assign(int $id, User $assignee): Conflict
    {
        /** @var Conflict $conflict */
        $conflict = Conflict::query()->findOrFail($id);
        $conflict->forceFill(['assigned_to' => $assignee->getKey(), 'status' => 'in_progress']);
        $conflict->save();

        return $conflict;
    }
}
