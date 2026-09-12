<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Unit>
 */
class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'organization_id' => fn (array $attributes) => ($attributes['property_id'] instanceof Property ? $attributes['property_id'] : Property::query()->withoutGlobalScopes()->findOrFail($attributes['property_id']))->organization_id,
            'unit_number' => fake()->unique()->numerify('VE-###'),
            'unit_type' => 'Wohnung',
            'floor' => (string) fake()->numberBetween(0, 6),
            'living_area_sqm' => fake()->randomFloat(2, 25, 140),
            'source_system' => 'immoware24',
            'external_id' => fake()->unique()->uuid(),
            'first_synced_at' => now(),
            'last_synced_at' => now(),
            'sync_version' => 1,
        ];
    }
}
