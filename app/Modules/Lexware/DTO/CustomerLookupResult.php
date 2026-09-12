<?php

declare(strict_types=1);

namespace App\Modules\Lexware\DTO;

/**
 * Ergebnis der Kundensuche. Nichterreichbarkeit ist "Ungeklärt" (unclear), eine vollständige erfolgreiche Suche
 * ohne Treffer ist "Nicht erforderlich" (not_required). Beides wird nie vermischt.
 */
final readonly class CustomerLookupResult
{
    public const string FOUND = 'found';

    public const string NOT_REQUIRED = 'not_required';

    public const string UNCLEAR = 'unclear';

    public const string NOT_CONFIGURED = 'not_configured';

    /**
     * @param  array<int, array<string, mixed>>  $contacts  maskierte Trefferliste (id, version, name)
     */
    public function __construct(
        public string $status,
        public array $contacts = [],
        public ?string $reason = null,
    ) {}

    public function label(): string
    {
        return match ($this->status) {
            self::FOUND => 'Kunde in Lexware gefunden',
            self::NOT_REQUIRED => 'Nicht erforderlich (kein Lexware-Kunde)',
            self::UNCLEAR => 'Ungeklärt (Lexware nicht erreichbar)',
            default => 'Nicht eingerichtet',
        };
    }

    public function isDecided(): bool
    {
        return in_array($this->status, [self::FOUND, self::NOT_REQUIRED], true);
    }
}
