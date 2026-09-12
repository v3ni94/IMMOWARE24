<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

/**
 * Ein DAV:response-Eintrag aus einer Multistatus-Antwort.
 */
final readonly class DavResource
{
    /**
     * @param  array<int, string>  $supportedReports
     */
    public function __construct(
        public string $href,
        public ?int $status,
        public bool $isCollection,
        public ?string $etag,
        public ?string $ctag,
        public ?string $syncToken,
        public ?string $data,
        public array $supportedReports = [],
    ) {}

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function supportsReport(string $name): bool
    {
        return in_array(strtolower($name), $this->supportedReports, true);
    }
}
