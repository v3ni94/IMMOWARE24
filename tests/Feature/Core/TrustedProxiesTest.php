<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * bootstrap/app.php: X-Forwarded-*-Header werden nur von Proxies aus TRUSTED_PROXIES ausgewertet (Standard leer).
 */
final class TrustedProxiesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        putenv('TRUSTED_PROXIES');

        parent::tearDown();
    }

    public function test_forwarded_headers_are_ignored_without_trusted_proxies(): void
    {
        Route::middleware('web')->get('/_test/ip', static fn (Request $request): array => ['ip' => $request->ip()]);

        $this->call('GET', '/_test/ip', [], [], [], ['REMOTE_ADDR' => '10.20.5.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])
            ->assertOk()
            ->assertJson(['ip' => '10.20.5.5']);

        $this->assertSame([], Request::getTrustedProxies());
    }

    public function test_forwarded_headers_are_honoured_only_from_configured_proxy(): void
    {
        $_ENV['TRUSTED_PROXIES'] = $_SERVER['TRUSTED_PROXIES'] = '10.20.0.0/16, *';
        $this->refreshApplication();

        Route::middleware('web')->get('/_test/ip', static fn (Request $request): array => ['ip' => $request->ip()]);

        $this->call('GET', '/_test/ip', [], [], [], ['REMOTE_ADDR' => '10.20.5.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.7']);

        $this->assertSame(['10.20.0.0/16'], Request::getTrustedProxies(), 'Wildcard * wird nie übernommen.');

        $this->call('GET', '/_test/ip', [], [], [], ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '203.0.113.7'])
            ->assertOk()
            ->assertJson(['ip' => '198.51.100.9']);
    }
}
