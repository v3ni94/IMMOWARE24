<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Hausverwaltung Müller GmbH',
            'legal_entity_code' => 'HVM-'.fake()->unique()->numerify('####'),
            'settings' => [],
        ];
    }
}
