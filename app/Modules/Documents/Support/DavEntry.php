<?php

declare(strict_types=1);

namespace App\Modules\Documents\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Ein Eintrag einer PROPFIND-Multistatus-Antwort mit den für den Dokumentenspiegel relevanten Properties.
 * Fehlende Properties sind null; der Parser wirft nie wegen unvollständiger Antworten.
 */
final readonly class DavEntry
{
    public function __construct(
        public string $href,
        public string $path,
        public bool $isCollection,
        public ?int $status = null,
        public ?string $etag = null,
        public ?string $lastModified = null,
        public ?int $contentLength = null,
        public ?string $contentType = null,
        public ?string $displayName = null,
    ) {}

    public function name(): string
    {
        return WebDavPath::basename($this->path);
    }

    public function lastModifiedAt(): ?CarbonImmutable
    {
        if ($this->lastModified === null || trim($this->lastModified) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($this->lastModified))->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function isOk(): bool
    {
        return $this->status === null || ($this->status >= 200 && $this->status < 300);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'href' => $this->href,
            'path' => $this->path,
            'is_collection' => $this->isCollection,
            'status' => $this->status,
            'etag' => $this->etag,
            'last_modified' => $this->lastModified,
            'content_length' => $this->contentLength,
            'content_type' => $this->contentType,
            'display_name' => $this->displayName,
        ];
    }
}
