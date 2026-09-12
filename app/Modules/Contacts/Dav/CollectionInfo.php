<?php

declare(strict_types=1);

namespace App\Modules\Contacts\Dav;

/**
 * Ergebnis eines PROPFIND Depth 0 auf eine Adressbuch- oder Kalender-Collection.
 */
final readonly class CollectionInfo
{
    /**
     * @param  array<int, string>  $supportedReports
     */
    public function __construct(
        public ?string $ctag,
        public ?string $syncToken,
        public array $supportedReports = [],
    ) {}

    public function supportsSyncCollection(): bool
    {
        return in_array('sync-collection', $this->supportedReports, true);
    }
}
