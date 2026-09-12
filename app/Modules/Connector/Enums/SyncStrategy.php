<?php

declare(strict_types=1);

namespace App\Modules\Connector\Enums;

/**
 * Sync-Strategie je Collection gemäß docs/immoware/07-sync-strategy.md Abschnitt 2.2.
 */
enum SyncStrategy: string
{
    case SyncToken = 'sync_token';
    case CtagEtag = 'ctag_etag';
    case EtagOnly = 'etag_only';
    case LastModifiedSizeHash = 'lastmodified_size_hash';
    case FullHash = 'full_hash';
    case FileSnapshot = 'file_snapshot';

    public static function choose(bool $syncTokenSupported, bool $ctagPresent, bool $etagStable, bool $lastModifiedAndSizePresent): self
    {
        return match (true) {
            $syncTokenSupported => self::SyncToken,
            $ctagPresent && $etagStable => self::CtagEtag,
            $etagStable => self::EtagOnly,
            $lastModifiedAndSizePresent => self::LastModifiedSizeHash,
            default => self::FullHash,
        };
    }
}
