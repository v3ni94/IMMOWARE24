<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

final class BootGuardProcessTest extends TestCase
{
    public function test_application_refuses_to_boot_when_a_locked_write_flag_is_true(): void
    {
        $result = Process::path(base_path())
            ->env(['IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED' => 'true', 'APP_ENV' => 'testing'])
            ->timeout(60)
            ->run([PHP_BINARY, 'artisan', 'about', '--only=environment']);

        $this->assertFalse($result->successful());
        $this->assertStringContainsString('IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED', $result->output().$result->errorOutput());
    }
}
