<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Modules\Mail\Boot\MailBootGuard;
use App\Modules\Mail\MailServiceProvider;
use RuntimeException;
use Tests\TestCase;

final class MailBootGuardBootTest extends TestCase
{
    public function test_test_environment_has_all_mail_flags_disabled(): void
    {
        foreach ((array) config('hub.mail.flags') as $flag => $value) {
            $this->assertFalse($value, sprintf('Flag %s muss in Tests false sein.', $flag));
        }

        $this->assertSame([], $this->app->make(MailBootGuard::class)->violations((array) config('hub.mail'), 'testing'));
    }

    public function test_provider_boot_throws_when_fake_is_configured_in_production(): void
    {
        config(['hub.mail.providers.gmail' => 'fake', 'app.env' => 'production']);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_GMAIL_PROVIDER=fake');

        (new MailServiceProvider($this->app))->boot();
    }
}
