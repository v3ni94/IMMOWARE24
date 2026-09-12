<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProbeCommandTest extends TestCase
{
    use DavFixtures;
    use RefreshDatabase;

    public function test_command_prints_symbols_and_strategy(): void
    {
        Http::fake(function (Request $request) {
            $headers = $this->davHeaders();

            if (! $request->hasHeader('Authorization')) {
                return Http::response('', 401, $headers + ['WWW-Authenticate' => 'Basic realm="dav"']);
            }

            return match ($request->method()) {
                'OPTIONS' => Http::response('', 200, $headers),
                'PROPFIND' => Http::response($this->multistatus(['/share/a.pdf' => 'a1'], true, false, false), 207, $headers),
                default => Http::response('', 405, $headers),
            };
        });

        $connection = $this->createConnection(null, ['rate_limit_rps' => 50, 'name' => 'WebDAV Test']);

        $this->artisan('hub:probe', ['connection' => 'WebDAV Test', '--delay' => 0])
            ->expectsOutputToContain('✓ Authentifizierung (PROPFIND 0)')
            ->expectsOutputToContain('✓ ETag-Stabilität')
            ->expectsOutputToContain('⚠ REPORT sync-collection')
            ->expectsOutputToContain('Strategie: ctag_etag')
            ->assertSuccessful();

        $this->artisan('hub:probe', ['connection' => (string) $connection->getKey(), '--delay' => 0, '--json' => true])
            ->expectsOutputToContain('"strategy": "ctag_etag"')
            ->assertSuccessful();
    }

    public function test_command_fails_for_unknown_connection_and_on_failed_probe(): void
    {
        $this->artisan('hub:probe', ['connection' => '9999'])->assertFailed();

        Http::fake(fn () => Http::response('', 404));
        $connection = $this->createConnection(null, ['rate_limit_rps' => 50]);

        $this->artisan('hub:probe', ['connection' => (string) $connection->getKey(), '--delay' => 0])
            ->expectsOutputToContain('✗ Authentifizierung (PROPFIND 0)')
            ->expectsOutputToContain('? If-None-Match (nur Header)')
            ->assertFailed();
    }
}
