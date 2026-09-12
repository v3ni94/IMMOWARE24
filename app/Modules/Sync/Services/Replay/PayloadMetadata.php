<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services\Replay;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Models\ExternalPayload;
use RuntimeException;

/**
 * Liest den Mandantenbezug einer Nutzlast: import_metadata.organization_id, sonst organization_id der Connection.
 */
final class PayloadMetadata
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function organizationId(ExternalPayload $payload, array $metadata): int
    {
        if (isset($metadata['organization_id']) && is_numeric($metadata['organization_id'])) {
            return (int) $metadata['organization_id'];
        }

        $organizationId = ImmowareConnection::query()
            ->whereKey((int) $payload->getAttribute('connection_id'))
            ->value('organization_id');

        if ($organizationId === null) {
            throw new RuntimeException(sprintf('Connection %d der Nutzlast existiert nicht, Replay nicht möglich.', (int) $payload->getAttribute('connection_id')));
        }

        return (int) $organizationId;
    }
}
