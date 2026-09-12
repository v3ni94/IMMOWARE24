<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http;

use App\Modules\Documents\Support\DavEntry;

/**
 * Ergebnis eines PROPFIND: HTTP-Status, der Eintrag der angefragten Ressource selbst und die Kinder.
 */
final readonly class PropfindResult
{
    /**
     * @param  array<int, DavEntry>  $entries  Alle Einträge inklusive der Ressource selbst
     */
    public function __construct(
        public int $status,
        public string $path,
        public array $entries = [],
    ) {}

    public function isMultistatus(): bool
    {
        return $this->status === 207;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function self(): ?DavEntry
    {
        foreach ($this->entries as $entry) {
            if (rtrim($entry->path, '/') === rtrim($this->path, '/')) {
                return $entry;
            }
        }

        return $this->entries === [] ? null : ($this->status === 207 && count($this->entries) === 1 ? $this->entries[0] : null);
    }

    /**
     * @return array<int, DavEntry>
     */
    public function children(): array
    {
        $children = [];

        foreach ($this->entries as $entry) {
            if (rtrim($entry->path, '/') === rtrim($this->path, '/')) {
                continue;
            }

            if (! $entry->isOk()) {
                continue;
            }

            $children[] = $entry;
        }

        return $children;
    }
}
