<?php

declare(strict_types=1);

namespace App\Modules\Paperless\Services;

use App\Core\Contracts\Mail\PaperlessSourceInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;

/**
 * Gebunden, solange keine Paperless-Basis-URL oder kein API-Token hinterlegt ist ("Nicht eingerichtet").
 */
final class NotConfiguredPaperlessSource implements PaperlessSourceInterface
{
    public function search(string $query, array $options = []): array
    {
        throw MailIntegrationNotConfiguredException::for('paperless');
    }

    public function get(int $documentId): array
    {
        throw MailIntegrationNotConfiguredException::for('paperless');
    }

    public function forProperty(string $objectNumber, array $options = []): array
    {
        throw MailIntegrationNotConfiguredException::for('paperless');
    }

    public function upload(string $filename, string $content, string $mimeType, string $title, ?string $objectNumber = null): string
    {
        throw MailIntegrationNotConfiguredException::for('paperless');
    }
}
