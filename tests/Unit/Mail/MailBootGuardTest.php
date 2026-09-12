<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Modules\Mail\Boot\MailBootGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MailBootGuardTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(array $overrides = []): array
    {
        return array_replace_recursive([
            'domain' => 'mail.muellerhv.de',
            'production_domain' => 'mail.muellerhv.de',
            'flags' => ['import' => false, 'ai' => false, 'gmail_drafts' => false, 'gmail_send' => false, 'immoware_write' => false, 'lexware_write' => false],
            'staging_locked_flags' => ['gmail_send' => 'MAIL_GMAIL_SEND_ENABLED', 'immoware_write' => 'MAIL_IMMOWARE_WRITE_ENABLED', 'lexware_write' => 'MAIL_LEXWARE_WRITE_ENABLED'],
            'providers' => ['gmail' => null, 'ai' => null, 'lexware' => null, 'drive' => null],
        ], $overrides);
    }

    public function test_fake_provider_in_production_throws(): void
    {
        $guard = new MailBootGuard;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MAIL_GMAIL_PROVIDER=fake');

        $guard->assertSafe($this->config(['providers' => ['gmail' => 'fake']]), 'production');
    }

    public function test_fake_provider_outside_production_is_allowed(): void
    {
        $guard = new MailBootGuard;

        $this->assertSame([], $guard->violations($this->config(['providers' => ['gmail' => 'fake', 'ai' => 'fake']]), 'testing'));
        $this->assertSame([], $guard->violations($this->config(['providers' => ['gmail' => 'fake']]), 'local'));
    }

    public function test_send_flag_in_staging_throws(): void
    {
        $guard = new MailBootGuard;

        $violations = $guard->violations($this->config(['flags' => ['gmail_send' => true, 'lexware_write' => 'true']]), 'staging');

        $this->assertCount(2, $violations);
        $this->assertStringContainsString('MAIL_GMAIL_SEND_ENABLED', $violations[0]);
        $this->assertStringContainsString('MAIL_LEXWARE_WRITE_ENABLED', $violations[1]);
    }

    public function test_write_flags_on_foreign_domain_in_production_throw(): void
    {
        $guard = new MailBootGuard;

        $violations = $guard->violations($this->config(['domain' => 'mail-test.example.org', 'flags' => ['immoware_write' => true]]), 'production');

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('MAIL_IMMOWARE_WRITE_ENABLED', $violations[0]);
    }

    public function test_production_domain_with_send_flag_passes(): void
    {
        $guard = new MailBootGuard;

        $this->assertSame([], $guard->violations($this->config(['flags' => ['gmail_send' => true]]), 'production'));
        $this->assertSame([], $guard->violations($this->config(), 'staging'));
    }
}
