<?php

declare(strict_types=1);

namespace App\Modules\Connector\Support;

/**
 * Ein DAV:response-Eintrag einer Multistatus-Antwort (WebDAV, CardDAV, CalDAV). Einzige Ergebnisklasse des
 * DavMultistatusParser im Hub; die Module Contacts und Calendar nutzen data (address-data bzw. calendar-data).
 */
final readonly class DavResponse
{
    /**
     * @param  array<int, string>  $supportedReports  Lokale Namen, z. B. sync-collection, addressbook-multiget
     * @param  string|null  $data  Nutzdaten aus card:address-data bzw. cal:calendar-data
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
        public ?string $data = null,
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
