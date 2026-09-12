<?php

declare(strict_types=1);

namespace App\Modules\Documents\DTO;

use App\Core\Enums\WriteOperationStatus;
use App\Modules\Sync\Models\WriteOperation;

/**
 * Ergebnis eines Upload-Aufrufs. outcome beschreibt den Ablauf dieses Aufrufs, nicht nur den Endstatus.
 */
final readonly class UploadResult
{
    public const string OUTCOME_IDEMPOTENT_REPLAY = 'idempotent_replay';

    public const string OUTCOME_DENIED = 'denied';

    /** Antrag aus einem API-Key-Kontext: wartet auf menschliche Freigabe (approve), kein Netzwerkzugriff. */
    public const string OUTCOME_PENDING_APPROVAL = 'pending_approval';

    public const string OUTCOME_REJECTED = 'rejected';

    public const string OUTCOME_DRY_RUN = 'dry_run';

    public const string OUTCOME_EXISTS_IDENTICAL = 'exists_identical';

    public const string OUTCOME_UPLOADED = 'uploaded';

    public const string OUTCOME_UNKNOWN = 'unknown';

    public const string OUTCOME_FAILED = 'failed';

    public function __construct(
        public WriteOperation $operation,
        public string $outcome,
        public bool $putSent = false,
    ) {}

    public function status(): WriteOperationStatus
    {
        $status = $this->operation->getAttribute('status');

        return $status instanceof WriteOperationStatus ? $status : WriteOperationStatus::from((string) $status);
    }
}
