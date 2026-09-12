<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use App\Core\Support\UrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function urls(): iterable
    {
        yield 'öffentlicher Host' => ['https://dav.immoware24.de/share/abc', null];
        yield 'öffentliches IP-Literal' => ['https://93.184.216.34/dav', null];
        yield 'http' => ['http://dav.immoware24.de/', 'scheme_not_https'];
        yield 'Zugangsdaten in URL' => ['https://user:pass@dav.immoware24.de/', 'credentials_in_url'];
        yield 'localhost' => ['https://localhost/dav', 'host_blocked'];
        yield 'internal' => ['https://intranet.internal/dav', 'host_blocked'];
        yield 'local' => ['https://nas.local/dav', 'host_blocked'];
        yield 'Loopback v4' => ['https://127.0.0.1/dav', 'ip_blocked'];
        yield 'privat 10/8' => ['https://10.0.0.5/dav', 'ip_blocked'];
        yield 'privat 172.16/12' => ['https://172.31.255.1/dav', 'ip_blocked'];
        yield 'privat 192.168/16' => ['https://192.168.1.1/dav', 'ip_blocked'];
        yield 'Link-Local' => ['https://169.254.169.254/latest/meta-data', 'ip_blocked'];
        yield 'Carrier NAT' => ['https://100.64.0.1/dav', 'ip_blocked'];
        yield 'Loopback v6' => ['https://[::1]/dav', 'ip_blocked'];
        yield 'ULA v6' => ['https://[fd00::1]/dav', 'ip_blocked'];
        yield 'IPv4-mapped v6' => ['https://[::ffff:10.0.0.1]/dav', 'ip_blocked'];
        yield 'ohne Punkt' => ['https://intranet/dav', 'host_invalid'];
        yield 'ungültig' => ['nicht-eine-url', 'url_invalid'];
    }

    #[DataProvider('urls')]
    public function test_reason_without_resolver(string $url, ?string $expected): void
    {
        $this->assertSame($expected, (new UrlGuard)->reason($url));
    }

    public function test_dns_rebinding_to_private_address_is_rejected(): void
    {
        $guard = new UrlGuard(static fn (string $host): array => $host === 'boese.example' ? ['10.0.0.9'] : ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

        $this->assertSame('resolves_to_blocked_ip', $guard->reason('https://boese.example/hook'));
        $this->assertNull($guard->reason('https://gut.example/hook'));
        $this->assertTrue($guard->allows('https://gut.example/hook'));
    }

    public function test_unresolvable_host_is_rejected(): void
    {
        $guard = new UrlGuard(static fn (string $host): array => []);

        $this->assertSame('dns_unresolved', $guard->reason('https://gibt-es-nicht.example/'));
    }

    public function test_forbidden_host_helper(): void
    {
        $this->assertTrue(UrlGuard::isForbiddenHost('LOCALHOST'));
        $this->assertTrue(UrlGuard::isForbiddenHost('[::1]'));
        $this->assertTrue(UrlGuard::isForbiddenHost('192.168.0.10'));
        $this->assertFalse(UrlGuard::isForbiddenHost('dav.immoware24.de'));
        $this->assertFalse(UrlGuard::isForbiddenHost('93.184.216.34'));
    }
}
