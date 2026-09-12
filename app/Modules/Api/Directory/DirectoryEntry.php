<?php

declare(strict_types=1);

namespace App\Modules\Api\Directory;

/**
 * Minimierter Verzeichniseintrag: keine Notizen, keine IBAN, keine Geburtsdaten, keine Adresshistorie.
 */
final readonly class DirectoryEntry
{
    /**
     * @param  array<int, string>  $phones
     * @param  array<int, string>  $mobiles
     * @param  array<int, string>  $emails
     * @param  array<int, array{role: string, property_id: int|null, property: string|null, unit_id: int|null, unit: string|null}>  $roles
     */
    public function __construct(
        public int $id,
        public string $displayName,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public array $phones,
        public array $mobiles,
        public array $emails,
        public array $roles,
        public ?string $updatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'display_name' => $this->displayName,
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'company' => $this->company,
            'phones' => $this->phones,
            'mobiles' => $this->mobiles,
            'emails' => $this->emails,
            'roles' => $this->roles,
            'updated_at' => $this->updatedAt,
        ];
    }
}
