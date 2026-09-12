<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Core\Contracts\Mail\DocumentSourceInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;

/**
 * Gebunden, solange keine Drive-Verbindung konfiguriert ist ("Nicht eingerichtet").
 */
final class NotConfiguredDocumentSource implements DocumentSourceInterface
{
    public function search(string $query, array $options = []): array
    {
        throw MailIntegrationNotConfiguredException::for('drive');
    }

    public function get(string $fileId): array
    {
        throw MailIntegrationNotConfiguredException::for('drive');
    }

    public function listFolder(string $folderId, ?string $pageToken = null): array
    {
        throw MailIntegrationNotConfiguredException::for('drive');
    }
}
