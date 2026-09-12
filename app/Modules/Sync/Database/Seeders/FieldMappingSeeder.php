<?php

declare(strict_types=1);

namespace App\Modules\Sync\Database\Seeders;

use App\Modules\Sync\Services\FieldMappingService;
use Illuminate\Database\Seeder;

/**
 * Default-Mapping v1.0 für vCard, iCal und WebDAV. Aufruf: php artisan db:seed --class="App\Modules\Sync\Database\Seeders\FieldMappingSeeder"
 */
final class FieldMappingSeeder extends Seeder
{
    public function run(FieldMappingService $mappings): void
    {
        $created = $mappings->seedDefaults();

        if ($this->command !== null) {
            $this->command->info(sprintf('%d Default-Mappings angelegt.', count($created)));
        }
    }
}
