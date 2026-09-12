<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $path = '/Dokumente/'.fake()->unique()->uuid().'.pdf';

        return [
            'connection_id' => ImmowareConnection::factory(),
            'organization_id' => fn (array $attributes) => ($attributes['connection_id'] instanceof ImmowareConnection ? $attributes['connection_id'] : ImmowareConnection::query()->withoutGlobalScopes()->findOrFail($attributes['connection_id']))->organization_id,
            'path' => $path,
            'path_hash' => Document::hashPath($path),
            'filename' => basename($path),
            'content_type' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 5_000_000),
            'remote_etag' => '"'.fake()->sha1().'"',
            'remote_last_modified' => now()->subDay(),
            'origin' => 'remote',
            'source_system' => 'immoware24',
            'external_id' => $path,
            'first_synced_at' => now(),
            'last_synced_at' => now(),
            'sync_version' => 1,
        ];
    }
}
