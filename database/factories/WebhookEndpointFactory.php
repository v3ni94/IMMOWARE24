<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\Organization;
use App\Modules\Webhooks\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebhookEndpoint>
 */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Endpoint '.fake()->unique()->word(),
            'url' => 'https://consumer.example.test/hooks/'.Str::lower(Str::random(8)),
            'secret' => 'whsec_'.Str::random(32),
            'events' => ['document.created', 'contact.updated'],
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['active' => false]);
    }

    /**
     * @param  array<int, string>  $events
     */
    public function events(array $events): static
    {
        return $this->state(fn (array $attributes) => ['events' => $events]);
    }

    public function secret(string $secret): static
    {
        return $this->state(fn (array $attributes) => ['secret' => $secret]);
    }
}
