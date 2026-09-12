<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Core\Enums\CapabilityStatus;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Models\ImmowareConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Capability>
 */
class CapabilityFactory extends Factory
{
    protected $model = Capability::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => ImmowareConnection::factory(),
            'capability_key' => 'documents.read',
            'evidence_status' => CapabilityStatus::Assumed,
            'enabled' => false,
            'hard_locked' => false,
        ];
    }

    public function key(string $key): static
    {
        return $this->state(fn (array $attributes) => ['capability_key' => $key]);
    }

    public function status(CapabilityStatus $status): static
    {
        return $this->state(fn (array $attributes) => ['evidence_status' => $status, 'tested_at' => now()]);
    }

    public function hardLocked(): static
    {
        return $this->state(fn (array $attributes) => ['hard_locked' => true, 'enabled' => false, 'evidence_status' => CapabilityStatus::Unavailable]);
    }
}
