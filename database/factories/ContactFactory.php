<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\Organization;
use App\Modules\Contacts\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'kind' => 'person',
            'salutation' => fake()->randomElement(['Herr', 'Frau']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'emails' => [['type' => 'work', 'value' => fake()->unique()->safeEmail()]],
            'phones' => [['type' => 'cell', 'value' => fake()->numerify('+4917########')]],
            'vcard_uid' => fake()->unique()->uuid(),
            'source_system' => 'immoware24',
            'external_id' => fake()->unique()->uuid(),
            'first_synced_at' => now(),
            'last_synced_at' => now(),
            'sync_version' => 1,
        ];
    }
}
