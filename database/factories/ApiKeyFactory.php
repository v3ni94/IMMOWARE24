<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Services\ApiKeyService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    protected $model = ApiKey::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $prefix = Str::random(8);

        return [
            'organization_id' => Organization::factory(),
            'name' => 'Testkey '.fake()->word(),
            'prefix' => $prefix,
            'key_hash' => ApiKeyService::hashKey('hub_live_'.$prefix.'_'.Str::random(43)),
            'scopes' => ['properties:read'],
            'allowed_ips' => null,
            'expires_at' => now()->addMonths(6),
        ];
    }

    /**
     * Setzt einen bekannten Klartextschlüssel, damit Tests ihn als Bearer verwenden können.
     */
    public function withPlainKey(string $plainKey): static
    {
        $parts = explode('_', $plainKey);

        return $this->state(fn (array $attributes) => [
            'prefix' => $parts[2] ?? $attributes['prefix'],
            'key_hash' => ApiKeyService::hashKey($plainKey),
        ]);
    }

    /**
     * @param  array<int, string>  $scopes
     */
    public function scopes(array $scopes): static
    {
        return $this->state(fn (array $attributes) => ['scopes' => $scopes]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => ['expires_at' => now()->subDay()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => ['revoked_at' => now()->subHour()]);
    }
}
