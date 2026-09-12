<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Connector\Models\Organization;
use App\Modules\Security\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected Organization $organization;

    protected string $plainKey = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->createOrganization();
    }

    /**
     * @param  array<int, string>  $scopes
     */
    protected function issueKey(array $scopes, ?Organization $organization = null): string
    {
        $prefix = Str::random(8);
        $plain = 'hub_live_'.$prefix.'_'.Str::random(43);

        ApiKey::factory()
            ->for($organization ?? $this->organization)
            ->withPlainKey($plain)
            ->scopes($scopes)
            ->create();

        $this->plainKey = $plain;

        return $plain;
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(?string $plain = null, array $extra = []): array
    {
        return array_merge([
            'Authorization' => 'Bearer '.($plain ?? $this->plainKey),
            'Accept' => 'application/json',
        ], $extra);
    }

    /**
     * @return array<string, string>
     */
    protected function writeHeaders(string $idempotencyKey, ?string $plain = null): array
    {
        return $this->authHeaders($plain, ['Idempotency-Key' => $idempotencyKey]);
    }
}
