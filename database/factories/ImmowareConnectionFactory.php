<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\ImmowareTechnicalUser;
use App\Modules\Connector\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImmowareConnection>
 */
class ImmowareConnectionFactory extends Factory
{
    protected $model = ImmowareConnection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseUrl = 'https://dav.example.test/'.fake()->unique()->uuid();

        return [
            'organization_id' => Organization::factory(),
            'technical_user_id' => ImmowareTechnicalUser::factory(),
            'name' => 'WebDAV Dokumente '.fake()->unique()->numerify('###'),
            'connector_type' => 'webdav_documents',
            'base_url' => $baseUrl,
            'base_url_hash' => hash('sha256', $baseUrl),
            'credentials' => ['username' => 'hub-read', 'password' => 'test-secret'],
            'auth_scheme' => 'unknown',
            'purpose' => 'read',
            'write_enabled' => false,
            'poll_interval_seconds' => 1800,
            'rate_limit_rps' => 2.00,
            'status' => 'paused',
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => ['status' => 'active']);
    }

    public function carddav(): static
    {
        return $this->state(fn (array $attributes) => ['connector_type' => 'carddav_contacts', 'name' => 'CardDAV Kontakte '.fake()->unique()->numerify('###')]);
    }

    public function caldav(): static
    {
        return $this->state(fn (array $attributes) => ['connector_type' => 'caldav_calendar', 'name' => 'CalDAV Kalender '.fake()->unique()->numerify('###')]);
    }
}
