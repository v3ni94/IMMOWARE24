<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Boot\BootGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BootGuardTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function safeConfig(): array
    {
        return ['write' => [
            'enabled' => false,
            'webdav_create_enabled' => false,
            'webdav_overwrite_enabled' => false,
            'webdav_delete_enabled' => false,
            'webdav_move_enabled' => false,
            'carddav_enabled' => false,
            'caldav_enabled' => false,
        ]];
    }

    public function test_safe_configuration_passes(): void
    {
        $guard = new BootGuard;
        $guard->assertSafe($this->safeConfig());

        $this->assertSame([], $guard->violations($this->safeConfig()));
    }

    public function test_create_flag_true_is_allowed(): void
    {
        $config = $this->safeConfig();
        $config['write']['enabled'] = true;
        $config['write']['webdav_create_enabled'] = true;

        (new BootGuard)->assertSafe($config);
        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function lockedFlags(): iterable
    {
        yield 'overwrite' => ['webdav_overwrite_enabled', 'IMMOWARE_WRITE_WEBDAV_OVERWRITE_ENABLED'];
        yield 'delete' => ['webdav_delete_enabled', 'IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED'];
        yield 'move' => ['webdav_move_enabled', 'IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED'];
        yield 'carddav' => ['carddav_enabled', 'IMMOWARE_WRITE_CARDDAV_ENABLED'];
        yield 'caldav' => ['caldav_enabled', 'IMMOWARE_WRITE_CALDAV_ENABLED'];
    }

    #[DataProvider('lockedFlags')]
    public function test_locked_flag_true_throws(string $key, string $envName): void
    {
        $config = $this->safeConfig();
        $config['write'][$key] = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($envName);

        (new BootGuard)->assertSafe($config);
    }

    public function test_string_true_is_treated_as_violation(): void
    {
        $config = $this->safeConfig();
        $config['write']['webdav_delete_enabled'] = 'true';

        $this->assertSame(['IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED'], (new BootGuard)->violations($config));
    }

    /**
     * @return iterable<string, array{bool, string, bool}>
     */
    public static function environments(): iterable
    {
        yield 'production, Flag false' => [false, 'production', true];
        yield 'staging, Flag false' => [false, 'staging', true];
        yield 'production, Flag true' => [true, 'production', true];
        yield 'testing, Flag false' => [false, 'testing', false];
        yield 'local, Flag false' => [false, 'local', false];
        yield 'testing, Flag true' => [true, 'testing', true];
        yield 'unbekannte Umgebung, Flag false' => [false, 'irgendwas', true];
    }

    #[DataProvider('environments')]
    public function test_guard_cannot_be_disabled_outside_testing_and_local(bool $configured, string $environment, bool $expected): void
    {
        $this->assertSame($expected, BootGuard::shouldRun($configured, $environment));
    }
}
