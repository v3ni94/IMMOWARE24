<?php

declare(strict_types=1);

namespace App\Core\Enums;

enum WriteOperationStatus: string
{
    case Pending = 'pending';
    case Prechecked = 'prechecked';
    case Sent = 'sent';
    case Unknown = 'unknown';
    case Verified = 'verified';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /**
     * Ab diesem Status hat möglicherweise ein PUT stattgefunden. Ein Rücksprung ist verboten.
     */
    public function mayHaveReachedRemote(): bool
    {
        return match ($this) {
            self::Sent, self::Unknown, self::Verified => true,
            default => false,
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Verified, self::Failed, self::Rejected => true,
            default => false,
        };
    }
}
