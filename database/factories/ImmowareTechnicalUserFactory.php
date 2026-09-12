<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\ImmowareTechnicalUser;
use App\Modules\Connector\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImmowareTechnicalUser>
 */
class ImmowareTechnicalUserFactory extends Factory
{
    protected $model = ImmowareTechnicalUser::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'label' => 'hub-read',
            'username' => 'hub-read-'.fake()->unique()->numerify('####'),
            'secret' => 'test-secret',
            'purpose' => 'read',
            'breaker_state' => 'closed',
            'status' => 'active',
        ];
    }
}
