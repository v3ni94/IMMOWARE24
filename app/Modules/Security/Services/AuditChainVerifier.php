<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\Security\DTO\ChainVerificationResult;
use App\Modules\Security\Models\AuditAnchor;
use App\Modules\Security\Models\AuditLog;

/**
 * Prüft die Hash-Kette des Auditlogs zeilenweise und gegen die gespeicherten Anker.
 */
final class AuditChainVerifier
{
    public function verify(?int $fromId = null): ChainVerificationResult
    {
        $previous = null;
        $checked = 0;
        $anchors = AuditAnchor::query()->pluck('root_hash', 'last_audit_id')->all();

        if ($fromId !== null && $fromId > 1) {
            $previous = AuditLog::query()->where('id', '<', $fromId)->orderByDesc('id')->first();
        }

        $query = AuditLog::query()->orderBy('id');

        if ($fromId !== null) {
            $query->where('id', '>=', $fromId);
        }

        foreach ($query->lazyById(500) as $entry) {
            /** @var AuditLog $entry */
            $checked++;

            if (! $entry->verifyChain($previous)) {
                return new ChainVerificationResult(
                    valid: false,
                    checked: $checked,
                    firstBrokenId: (int) $entry->getKey(),
                    lastHash: $previous?->getAttribute('row_hash'),
                    message: sprintf('Kette bei audit_logs.id %d unterbrochen.', (int) $entry->getKey()),
                );
            }

            $anchorHash = $anchors[(int) $entry->getKey()] ?? null;

            if ($anchorHash !== null && ! hash_equals((string) $anchorHash, (string) $entry->getAttribute('row_hash'))) {
                return new ChainVerificationResult(
                    valid: false,
                    checked: $checked,
                    firstBrokenId: (int) $entry->getKey(),
                    lastHash: $entry->getAttribute('row_hash'),
                    message: sprintf('Anker für audit_logs.id %d stimmt nicht mit der Kette überein.', (int) $entry->getKey()),
                );
            }

            $previous = $entry;
        }

        return new ChainVerificationResult(
            valid: true,
            checked: $checked,
            lastHash: $previous?->getAttribute('row_hash') ?? AuditLog::GENESIS_HASH,
            message: sprintf('%d Einträge geprüft, Kette intakt.', $checked),
        );
    }
}
