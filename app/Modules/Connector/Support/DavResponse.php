<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

final readonly class DavResponse
{
    /**
     * @param  array<int, string>  $supportedReports  Lokale Namen, z. B. sync-collection, addressbook-multiget
     */
    public function __construct(
        public string $href,
        public ?int $status,
        public bool $isCollection,
        public ?string $etag,
        public ?string $ctag,
        public ?string $syncToken,
        public ?string $lastModified,
        public ?int $contentLength,
        public array $supportedReports = [],
    ) {}

    public function supportsReport(string $name): bool
    {
        return in_array(strtolower($name), $this->supportedReports, true);
    }
}
