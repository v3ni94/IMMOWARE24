<?php

declare(strict_types=1);

namespace App\Modules\Actions\Events;

use App\Modules\Actions\Enums\VerificationStatus;

/**
 * Ein Ausführungsschritt wurde verifiziert (api_verified nach Nachlesen oder manually_confirmed durch einen Menschen).
 * Das Modul Cases nutzt das Ereignis, um Aufgabenstatus und Kommunikationsbedarf des Vorgangs fortzuschreiben.
 * Ein reiner HTTP-Erfolg löst dieses Ereignis nie aus.
 */
final class ExecutionVerified
{
    public function __construct(
        public readonly int $executionId,
        public readonly int $versionId,
        public readonly int $stepIndex,
        public readonly VerificationStatus $status,
        public readonly ?int $verifiedBy,
    ) {}
}
