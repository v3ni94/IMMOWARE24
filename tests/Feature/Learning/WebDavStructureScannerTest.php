<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Modules\Learning\Services\WebDavStructureScanner;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Documents\DocumentsTestHelpers;
use Tests\TestCase;

/**
 * Nutzt die Multistatus-Fixture des Moduls Documents (DocumentsTestHelpers), da beide denselben,
 * einzigen DAV-Multistatus-Parser des Hubs ansprechen.
 */
final class WebDavStructureScannerTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    public function test_scan_walks_the_tree_and_flags_folders_outside_configured_scope(): void
    {
        config()->set('hub.documents.scan.roots', ['/Posteingang/']);
        config()->set('hub.learning.webdav.max_depth', 4);
        config()->set('hub.learning.webdav.max_folders', 100);

        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);

        Http::fake(function (Request $request) use ($base): PromiseInterface {
            $this->assertSame('PROPFIND', $request->method());
            $path = $this->requestPath($request);
            $relative = str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

            if (rtrim($relative, '/') === '' || $relative === '/') {
                return Http::response($this->multistatus($base, [
                    ['href' => '/', 'collection' => true],
                    ['href' => '/Posteingang/', 'collection' => true],
                    ['href' => '/Neu/', 'collection' => true],
                ]), 207);
            }

            if ($relative === '/Posteingang/') {
                return Http::response($this->multistatus($base, [
                    ['href' => '/Posteingang/', 'collection' => true],
                    ['href' => '/Posteingang/rechnung_123.pdf', 'length' => 10],
                    ['href' => '/Posteingang/scan.jpg', 'length' => 20],
                ]), 207);
            }

            if ($relative === '/Neu/') {
                return Http::response($this->multistatus($base, [
                    ['href' => '/Neu/', 'collection' => true],
                    ['href' => '/Neu/unbekannt.xlsx', 'length' => 30],
                ]), 207);
            }

            return Http::response('', 404);
        });

        $facts = $this->app->make(WebDavStructureScanner::class)->scan($connection);

        $this->assertSame('webdav', $facts['kind']);
        $this->assertFalse($facts['truncated']);

        $byPath = [];
        foreach ($facts['folders'] as $folder) {
            $byPath[$folder['path']] = $folder;
        }

        $this->assertArrayHasKey('/', $byPath);
        $this->assertArrayHasKey('/Posteingang/', $byPath);
        $this->assertArrayHasKey('/Neu/', $byPath);

        $this->assertTrue($byPath['/Posteingang/']['in_configured_scope']);
        $this->assertFalse($byPath['/Neu/']['in_configured_scope']);

        $this->assertSame(2, $byPath['/Posteingang/']['entry_count']);
        $this->assertSame(1, $byPath['/Posteingang/']['extensions']['pdf']);
        $this->assertSame(1, $byPath['/Posteingang/']['extensions']['jpg']);
        $this->assertContains('rechnung_123.pdf', $byPath['/Posteingang/']['sample_names']);

        $this->assertSame(1, $byPath['/Neu/']['entry_count']);
        $this->assertSame(1, $byPath['/Neu/']['extensions']['xlsx']);
    }

    public function test_scan_stops_at_max_folders_and_marks_truncated(): void
    {
        config()->set('hub.documents.scan.roots', []);
        config()->set('hub.learning.webdav.max_depth', 4);
        config()->set('hub.learning.webdav.max_folders', 1);

        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);

        Http::fake(fn (): PromiseInterface => Http::response($this->multistatus($base, [
            ['href' => '/', 'collection' => true],
            ['href' => '/A/', 'collection' => true],
            ['href' => '/B/', 'collection' => true],
        ]), 207));

        $facts = $this->app->make(WebDavStructureScanner::class)->scan($connection);

        $this->assertTrue($facts['truncated']);
        $this->assertSame(1, $facts['folder_count']);
    }
}
