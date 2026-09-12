<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\Security\Models\AuditAnchor;
use App\Modules\Security\Models\AuditLog;

/**
 * Verankert den letzten Kettenwert in audit_anchors (täglich per Scheduler).
 */
final class AuditAnchorService
{
    /**
     * Schreibt einen Anker für den letzten Eintrag, sofern seit dem letzten Anker neue Einträge existieren.
     */
    public function anchor(?int $exportedBy = null): ?AuditAnchor
    {
        $last = AuditLog::query()->orderByDesc('id')->first();

        if (! $last instanceof AuditLog) {
            return null;
        }

        $lastId = (int) $last->getKey();

        if (AuditAnchor::query()->where('last_audit_id', $lastId)->exists()) {
            return null;
        }

        return AuditAnchor::query()->create([
            'last_audit_id' => $lastId,
            'root_hash' => $last->getAttribute('row_hash'),
            'exported_at' => now()->toImmutable(),
            'external_location' => (string) config('hub.security.audit.anchor_location', 'local://audit-anchors'),
            'exported_by' => $exportedBy,
        ]);
    }
}
