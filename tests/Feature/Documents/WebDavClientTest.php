<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Documents\Http\WebDavClientFactory;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WebDavClientTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    public function test_delete_move_copy_and_mkcol_throw_write_blocked_without_any_request(): void
    {
        Http::fake();
        $client = $this->app->make(WebDavClientFactory::class)->forConnection($this->writeConnection());

        $calls = [
            'DELETE' => static fn () => $client->delete('/Posteingang/x.pdf'),
            'MOVE' => static fn () => $client->move('/Posteingang/x.pdf', '/Posteingang/y.pdf'),
            'COPY' => static fn () => $client->copy('/Posteingang/x.pdf', '/Posteingang/y.pdf'),
            'MKCOL' => static fn () => $client->mkcol('/Posteingang/neu/'),
        ];

        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail($method.' wurde nicht blockiert.');
            } catch (WriteBlockedException $e) {
                $this->assertSame($method, $e->operation);
            }
        }

        Http::assertNothingSent();
    }

    public function test_put_outside_prefix_or_on_read_connection_is_blocked_and_valid_put_carries_if_none_match(): void
    {
        Http::fake(fn () => Http::response('', 201));
        $factory = $this->app->make(WebDavClientFactory::class);

        $readClient = $factory->forConnection($this->readConnection());

        try {
            $readClient->putCreateOnly('/Posteingang/a.pdf', 'x', 'application/pdf');
            $this->fail('PUT auf Lese-Connection wurde nicht blockiert.');
        } catch (WriteBlockedException $e) {
            $this->assertSame('PUT', $e->operation);
        }

        $writeClient = $factory->forConnection($this->writeConnection());

        try {
            $writeClient->putCreateOnly('/Dokumente/a.pdf', 'x', 'application/pdf');
            $this->fail('PUT außerhalb des Präfixes wurde nicht blockiert.');
        } catch (WriteBlockedException $e) {
            $this->assertSame('PUT', $e->operation);
        }

        try {
            $writeClient->putCreateOnly('/Posteingang/../Dokumente/a.pdf', 'x', 'application/pdf');
            $this->fail('PUT mit Punktsegmenten wurde nicht blockiert.');
        } catch (WriteBlockedException) {
            $this->addToAssertionCount(1);
        }

        Http::assertNothingSent();

        $response = $writeClient->putCreateOnly('/Posteingang/Müller a.pdf', 'inhalt', 'application/pdf');
        $this->assertSame(201, $response->status());

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'PUT'
                && ($request->header('If-None-Match')[0] ?? null) === '*'
                && str_ends_with($request->url(), '/Posteingang/M%C3%BCller%20a.pdf')
                && $request->body() === 'inhalt';
        });
    }

    public function test_propfind_sends_depth_and_body_and_download_streams_hash(): void
    {
        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);

        Http::fake(function (Request $request) use ($base): PromiseInterface {
            if ($request->method() === 'PROPFIND') {
                return Http::response($this->multistatus($base, [
                    ['href' => '/Posteingang/', 'collection' => true],
                    ['href' => '/Posteingang/scan.pdf', 'etag' => 'e1', 'length' => 6, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'],
                ]), 207);
            }

            if ($request->method() === 'OPTIONS') {
                return Http::response('', 200, ['DAV' => '1, 2', 'Allow' => 'OPTIONS, PROPFIND, GET, PUT']);
            }

            return Http::response('inhalt', 200, ['Content-Type' => 'application/pdf']);
        });

        $client = $this->app->make(WebDavClientFactory::class)->forConnection($connection);

        $options = $client->options();
        $this->assertTrue($options->supportsClass('1'));
        $this->assertContains('PROPFIND', $options->allow);

        $result = $client->propfind('/Posteingang/', 1);
        $this->assertSame(207, $result->status);
        $this->assertCount(1, $result->children());
        $this->assertSame('/Posteingang/scan.pdf', $result->children()[0]->path);
        $this->assertTrue($result->self()?->isCollection);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PROPFIND'
            && ($request->header('Depth')[0] ?? null) === '1'
            && str_contains($request->body(), '<D:getcontenttype/>'));

        $download = $client->download('/Posteingang/scan.pdf');
        $this->assertTrue($download->isOk());
        $this->assertSame(hash('sha256', 'inhalt'), $download->sha256);
        $this->assertSame(6, $download->sizeBytes);
        $this->assertSame('application/pdf', $download->contentType);

        $limited = $client->download('/Posteingang/scan.pdf', 3);
        $this->assertNull($limited->sha256);
    }
}
