<?php

declare(strict_types=1);

namespace App\Modules\Contacts\VCard;

/**
 * Normalisierte Sicht auf eine geparste vCard (3.0 oder 4.0). Reine Datenklasse, kein Serializer (kein Schreibpfad).
 */
final readonly class VCard
{
    /**
     * @param  array{family: string, given: string, additional: string, prefix: string, suffix: string}|null  $name
     * @param  array<int, array{value: string, types: array<int, string>}>  $emails
     * @param  array<int, array{value: string, types: array<int, string>}>  $phones
     * @param  array<int, array{types: array<int, string>, po_box: string, extended: string, street: string, city: string, region: string, postal_code: string, country: string}>  $addresses
     * @param  array<int, string>  $categories
     * @param  array<string, array<int, string>>  $extra  X-Properties und unbekannte Properties, Name in Großbuchstaben
     */
    public function __construct(
        public string $version,
        public ?string $uid,
        public ?string $formattedName,
        public ?array $name,
        public ?string $organization,
        public ?string $organizationUnit,
        public ?string $title,
        public array $emails,
        public array $phones,
        public array $addresses,
        public ?string $note,
        public array $categories,
        public ?string $rev,
        public array $extra,
    ) {}

    public function hasUid(): bool
    {
        return $this->uid !== null && $this->uid !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'uid' => $this->uid,
            'fn' => $this->formattedName,
            'n' => $this->name,
            'org' => $this->organization,
            'org_unit' => $this->organizationUnit,
            'title' => $this->title,
            'emails' => $this->emails,
            'phones' => $this->phones,
            'addresses' => $this->addresses,
            'note' => $this->note,
            'categories' => $this->categories,
            'rev' => $this->rev,
            'extra' => $this->extra,
        ];
    }
}
