<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\Organization;
use App\Modules\Estate\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Property>
 */
class PropertyFactory extends Factory
{
    protected $model = Property::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'immoware_object_number' => fake()->unique()->numerify('OBJ-####'),
            'name' => 'WEG '.fake()->streetName(),
            'management_type' => 'WEG',
            'street' => fake()->streetName(),
            'house_number' => (string) fake()->numberBetween(1, 120),
            'postal_code' => fake()->numerify('#####'),
            'city' => fake()->city(),
            'source_system' => 'immoware24',
            'external_id' => fake()->unique()->uuid(),
            'first_synced_at' => now(),
            'last_synced_at' => now(),
            'sync_version' => 1,
        ];
    }
}
