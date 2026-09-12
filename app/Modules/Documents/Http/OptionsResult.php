<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http;

/**
 * Ergebnis eines OPTIONS: DAV-Klassen und erlaubte Methoden laut Server.
 */
final readonly class OptionsResult
{
    /**
     * @param  array<int, string>  $davClasses
     * @param  array<int, string>  $allow
     */
    public function __construct(
        public int $status,
        public array $davClasses = [],
        public array $allow = [],
        public ?string $server = null,
    ) {}

    public function supportsDav(): bool
    {
        return $this->davClasses !== [];
    }

    public function supportsClass(string $class): bool
    {
        return in_array(strtolower($class), array_map('strtolower', $this->davClasses), true);
    }
}
