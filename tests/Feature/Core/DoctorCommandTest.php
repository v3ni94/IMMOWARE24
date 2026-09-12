<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class DoctorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_doctor_reports_flags_adapters_and_never_aborts_without_redis(): void
    {
        $code = Artisan::call('hub:doctor', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('"check": "boot_guard"', $output);
        $this->assertStringContainsString('"check": "redis"', $output);
        $this->assertStringContainsString('"check": "database"', $output);
        $this->assertStringContainsString('IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED', $output);
        $this->assertStringContainsString('rest_api_slot, webdav, carddav, caldav, file_import', $output);
    }

    public function test_doctor_flags_hard_locked_violation(): void
    {
        config()->set('hub.core.write.webdav_move_enabled', true);

        $this->assertSame(1, Artisan::call('hub:doctor'));
        $this->assertStringContainsString('Verletzungen: IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED', Artisan::output());
    }
}
