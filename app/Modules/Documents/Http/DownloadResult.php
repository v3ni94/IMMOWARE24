<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http;

/**
 * Ergebnis eines GET mit Streaming: nur Hash und Größe, der Inhalt wird nie in der Datenbank abgelegt.
 */
final readonly class DownloadResult
{
    public function __construct(
        public int $status,
        public ?string $sha256,
        public int $sizeBytes,
        public ?string $contentType = null,
        public ?string $etag = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status >= 200 && $this->status < 300 && $this->sha256 !== null;
    }
}
