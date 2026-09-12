<?php

declare(strict_types=1);

namespace App\Modules\Documents\DTO;

/**
 * Antrag auf einen Posteingang-Upload. Der Inhalt liegt nur im Speicher bzw. im Blob-Speicher,
 * nie in der Datenbank.
 */
final readonly class UploadRequest
{
    public function __construct(
        public int $connectionId,
        public string $content,
        public string $originalFilename,
        public ?string $intentKey = null,
        public ?string $contentType = null,
        public ?int $requestedBy = null,
        public string $requestedVia = 'ui',
        public ?int $sourceDocumentId = null,
        public ?int $caseId = null,
        public ?string $sourceStorageKey = null,
        public ?bool $dryRun = null,
    ) {}

    public function contentHash(): string
    {
        return hash('sha256', $this->content);
    }

    public function sizeBytes(): int
    {
        return strlen($this->content);
    }
}
