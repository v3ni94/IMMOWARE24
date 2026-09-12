<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Models\ExportSchedule;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Services\ExportScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ExportScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_schedules_are_listed_and_remind_command_marks_them(): void
    {
        $connection = $this->createConnection();
        $due = ExportSchedule::query()->create(['connection_id' => $connection->getKey(), 'export_type' => 'open_items', 'interval_days' => 7, 'next_due_at' => CarbonImmutable::now()->subDays(2)]);
        ExportSchedule::query()->create(['connection_id' => $connection->getKey(), 'export_type' => 'properties', 'interval_days' => 30, 'next_due_at' => CarbonImmutable::now()->addDays(5)]);
        $never = ExportSchedule::query()->create(['connection_id' => $connection->getKey(), 'export_type' => 'contacts_csv', 'interval_days' => 14]);

        $overdue = $this->app->make(ExportScheduleService::class)->overdue();
        $this->assertEqualsCanonicalizing([$due->getKey(), $never->getKey()], $overdue->pluck('id')->all());

        $this->artisan('hub:imports:remind', ['--mark' => true])
            ->expectsOutputToContain('open_items')
            ->assertSuccessful();

        $this->assertNotNull($due->refresh()->reminder_sent_at);
    }

    public function test_successful_import_updates_schedule_and_data_age(): void
    {
        $organization = $this->createOrganization();
        $connection = $this->createConnection($organization);
        $schedule = ExportSchedule::query()->create(['connection_id' => $connection->getKey(), 'export_type' => 'properties', 'interval_days' => 7]);

        $processedAt = CarbonImmutable::parse('2026-09-10 10:00:00', 'UTC');
        $file = ImportFile::query()->create([
            'organization_id' => $organization->getKey(),
            'connection_id' => $connection->getKey(),
            'original_filename' => 'objekte.csv',
            'file_type' => 'csv',
            'export_type' => 'properties',
            'content_hash' => str_repeat('a', 64),
            'status' => ImportFileStatus::Imported->value,
            'received_at' => $processedAt,
            'processed_at' => $processedAt,
        ]);

        $service = $this->app->make(ExportScheduleService::class);
        $service->markImported($file);

        $schedule->refresh();
        $this->assertSame('2026-09-10 10:00:00', $schedule->last_import_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-17 10:00:00', $schedule->next_due_at?->format('Y-m-d H:i:s'));

        $now = CarbonImmutable::parse('2026-09-12 10:00:00', 'UTC');
        $this->assertSame(2 * 86400, $service->dataAgeSeconds((int) $organization->getKey(), ExportType::Properties, $now));
        $this->assertNull($service->dataAgeSeconds((int) $organization->getKey(), ExportType::Units, $now));

        $age = $service->dataAge((int) $organization->getKey(), $now);
        $this->assertSame(2, $age['properties']['age_days']);
        $this->assertNull($age['camt053']['age_days']);
    }
}
