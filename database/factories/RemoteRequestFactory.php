<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\RemoteRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RemoteRequest>
 */
class RemoteRequestFactory extends Factory
{
    protected $model = RemoteRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = 'https://dav.example.test/share/Posteingang/';

        return [
            'connection_id' => ImmowareConnection::factory(),
            'connector_name' => 'webdav:read',
            'correlation_id' => fake()->uuid(),
            'method' => 'PROPFIND',
            'path' => $path,
            'path_hash' => hash('sha256', $path),
            'request_headers_masked' => ['Depth' => '1'],
            'response_status' => 207,
            'response_headers' => ['Content-Type' => 'application/xml'],
            'response_bytes' => 1024,
            'duration_ms' => 120,
            'outcome' => 'success',
            'requested_at' => now(),
        ];
    }
}
