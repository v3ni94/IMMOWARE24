<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Core\Boot\BootGuard;
use Tests\TestCase;

final class BootGuardBootTest extends TestCase
{
    public function test_test_environment_has_all_write_flags_disabled_and_guard_active(): void
    {
        $this->assertTrue(config('hub.core.boot_guard'));
        $this->assertFalse(config('hub.core.write.enabled'));
        $this->assertFalse(config('hub.core.write.webdav_create_enabled'));
        $this->assertSame([], $this->app->make(BootGuard::class)->violations((array) config('hub.core')));
    }
}
