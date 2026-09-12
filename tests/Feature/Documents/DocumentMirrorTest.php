<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Core\DTO\SyncRequest;
use App\Core\Enums\SyncMode;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Documents\Connectors\WebDavConnector;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Estate\Models\Property;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Models\SyncEvent;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class DocumentMirrorTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    /** @var array<string, array<int, array<string, mixed>>> Ordnerpfad => Einträge */
    private array $tree = [];

    /** @var array<string, string> Dateipfad => Inhalt */
    private array $contents = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.documents.scan.roots', ['/Posteingang/', '/Dokumente/']);
        config()->set('hub.documents.content_hash.enabled', false);
    }

    public function test_connector_is_registered_and_first_pull_mirrors_folders_and_documents(): void
    {
        $connection = $this->readConnection();
        $this->fakeTree($connection, [
            '/Posteingang/' => [
                ['href' => '/Posteingang/', 'collection' => true],
                ['href' => '/Posteingang/Scan_001.pdf', 'etag' => 'p1', 'length' => 100, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT', 'type' => 'application/pdf'],
            ],
            '/Dokumente/' => [
                ['href' => '/Dokumente/', 'collection' => true],
                ['href' => '/Dokumente/0123 Musterstraße 1/', 'collection' => true],
            ],
            '/Dokumente/0123 Musterstraße 1/' => [
                ['href' => '/Dokumente/0123 Musterstraße 1/', 'collection' => true],
                ['href' => '/Dokumente/0123 Musterstraße 1/Rechnung Müller.pdf', 'etag' => 'r1', 'length' => 200, 'modified' => 'Thu, 10 Sep 2026 09:00:00 GMT'],
            ],
        ]);

        Property::factory()->create(['organization_id' => $connection->organization_id, 'immoware_object_number' => '0123']);

        $connector = $this->resolve($connection);
        $this->assertSame('webdav', $connector->name());
        $this->assertContains('documents.read', $connector->capabilities());

        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $this->assertNull($result->cursor);
        $this->assertSame(2, $result->created);
        $this->assertSame(0, $result->failed);

        $this->assertSame(3, DocumentFolder::query()->where('connection_id', $connection->getKey())->count());
        $this->assertSame(2, Document::query()->where('connection_id', $connection->getKey())->count());

        $invoice = Document::query()->where('filename', 'Rechnung Müller.pdf')->firstOrFail();
        $this->assertSame('/Dokumente/0123 Musterstraße 1/Rechnung Müller.pdf', $invoice->getAttribute('external_id'));
        $this->assertSame('/Dokumente/0123 Musterstraße 1/', $invoice->getAttribute('external_parent_id'));
        $this->assertSame('"r1"', $invoice->getAttribute('remote_etag'));
        $this->assertSame(200, $invoice->getAttribute('size_bytes'));
        $this->assertSame('application/pdf', $invoice->getAttribute('content_type'));
        $this->assertSame('invoice', $invoice->getAttribute('document_type'));
        $this->assertNotNull($invoice->getAttribute('property_id'), 'Zuordnung über Regel Objektnummer im Ordnernamen');
        $this->assertSame('derived', $invoice->getAttribute('assignment_confidence'));
        $this->assertFalse((bool) $invoice->getAttribute('content_stored'));
        $this->assertNotNull($invoice->getAttribute('checksum'));

        $scan = Document::query()->where('filename', 'Scan_001.pdf')->firstOrFail();
        $this->assertNull($scan->getAttribute('property_id'), 'ohne Regeltreffer keine Zuordnung');
        $this->assertSame('scan', $scan->getAttribute('document_type'));

        $this->assertSame(2, SyncEvent::query()->where('action', 'created')->count());
        $this->assertSame(1, SyncRun::query()->where('status', 'succeeded')->count());

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(1, $run->getAttribute('counters')['full_enumeration']);

        // AP 3.7: je gescanntem Ordner eine archivierte PROPFIND-Antwort (Datenbasis für hub:replay document).
        $payloads = ExternalPayload::query()->where('payload_type', 'propfind_xml')->get();
        $this->assertCount(3, $payloads);
        $payload = $payloads->firstWhere('external_id_hash', hash('sha256', '/Posteingang/'));
        $this->assertNotNull($payload);
        $this->assertSame((int) $run->getKey(), (int) $payload->getAttribute('sync_run_id'));
        $this->assertSame('/Posteingang/', $payload->getAttribute('import_metadata')['path']);
        $this->assertStringContainsString('Scan_001.pdf', (string) $this->app->make(ExternalPayloadArchiver::class)->contents($payload));
    }

    public function test_replay_from_archived_propfind_payloads_restores_identical_mirror_state(): void
    {
        $connection = $this->readConnection();
        $this->fakeTree($connection, [
            '/Posteingang/' => [
                ['href' => '/Posteingang/', 'collection' => true],
                ['href' => '/Posteingang/Scan_001.pdf', 'etag' => 'p1', 'length' => 100, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT', 'type' => 'application/pdf'],
            ],
            '/Dokumente/' => [
                ['href' => '/Dokumente/', 'collection' => true],
                ['href' => '/Dokumente/Vertrag.pdf', 'etag' => 'v1', 'length' => 300, 'modified' => 'Thu, 10 Sep 2026 09:00:00 GMT'],
            ],
        ]);

        $this->resolve($connection)->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $before = Document::query()->where('connection_id', $connection->getKey())->orderBy('external_id')->pluck('checksum', 'external_id')->all();
        $this->assertCount(2, $before);
        $requestsAfterScan = count(Http::recorded());

        // Verlust des Spiegels simulieren (nur Test; im Betrieb gibt es kein Hard Delete auf Spiegeldaten).
        Document::query()->withoutGlobalScopes()->where('connection_id', $connection->getKey())->forceDelete();
        DocumentFolder::query()->withoutGlobalScopes()->where('connection_id', $connection->getKey())->forceDelete();

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'document', '--all' => true, '--latest' => true, '--connection' => (int) $connection->getKey()]));

        $this->assertCount($requestsAfterScan, Http::recorded(), 'Replay sendet keinen Request an Immoware24.');
        $after = Document::query()->where('connection_id', $connection->getKey())->orderBy('external_id')->pluck('checksum', 'external_id')->all();
        $this->assertSame($before, $after, 'Replay aus external_payloads liefert identische checksum (07-sync-strategy.md Abschnitt 8).');
        $this->assertSame(2, DocumentFolder::query()->where('connection_id', $connection->getKey())->count());
        $this->assertSame(1, SyncRun::query()->where('run_type', SyncRun::TYPE_REPLAY)->where('status', 'succeeded')->count());
    }

    public function test_unchanged_etag_produces_no_write_and_changed_etag_produces_update(): void
    {
        $connection = $this->readConnection();
        $connection->forceFill(['probe_result' => ['etag_stable' => true]])->save();
        config()->set('hub.documents.scan.roots', ['/Posteingang/']);

        $this->fakeTree($connection, [
            '/Posteingang/' => [
                ['href' => '/Posteingang/', 'collection' => true],
                ['href' => '/Posteingang/a.pdf', 'etag' => 'v1', 'length' => 10, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'],
            ],
        ]);

        $connector = $this->resolve($connection);
        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $document = Document::query()->where('filename', 'a.pdf')->firstOrFail();
        $updatedAt = $document->getAttribute('updated_at');
        $checksum = $document->getAttribute('checksum');

        $this->travel(5)->minutes();

        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $document->refresh();
        $this->assertEquals($updatedAt, $document->getAttribute('updated_at'), 'unveränderter ETag: kein Write auf updated_at');
        $this->assertSame($checksum, $document->getAttribute('checksum'));
        $this->assertSame(1, $document->getAttribute('sync_version'));
        $this->assertSame(0, SyncEvent::query()->where('action', 'updated')->count());
        $this->assertNotNull($document->getAttribute('last_synced_at'));

        $this->tree['/Posteingang/'][1] = ['href' => '/Posteingang/a.pdf', 'etag' => 'v2', 'length' => 12, 'modified' => 'Sat, 12 Sep 2026 08:00:00 GMT'];

        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $this->assertSame(1, $result->updated);
        $document->refresh();
        $this->assertSame('"v2"', $document->getAttribute('remote_etag'));
        $this->assertSame(12, $document->getAttribute('size_bytes'));
        $this->assertSame(2, $document->getAttribute('sync_version'));
        $this->assertNotSame($checksum, $document->getAttribute('checksum'));

        $event = SyncEvent::query()->where('action', 'updated')->firstOrFail();
        $this->assertSame('etag', $event->getAttribute('detected_by'));
        $this->assertSame($checksum, $event->getAttribute('old_checksum'));
    }

    public function test_sweep_marks_missing_first_and_soft_deletes_only_in_second_healthy_run(): void
    {
        $connection = $this->readConnection();
        config()->set('hub.documents.scan.roots', ['/Posteingang/']);

        $this->fakeTree($connection, [
            '/Posteingang/' => [
                ['href' => '/Posteingang/', 'collection' => true],
                ['href' => '/Posteingang/a.pdf', 'etag' => 'a1', 'length' => 10, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'],
                ['href' => '/Posteingang/b.pdf', 'etag' => 'b1', 'length' => 20, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'],
            ],
        ]);

        $connector = $this->resolve($connection);
        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
        $this->assertSame(2, Document::query()->count());

        // Erstes Fehlen: nur missing_since, kein Soft Delete (07-sync-strategy.md Abschnitt 4 Punkt 3).
        $this->travel(1)->minute();
        unset($this->tree['/Posteingang/'][2]);
        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $this->assertSame(0, $result->deleted);
        $this->assertSame(2, Document::query()->count(), 'Ein einzelner unvollständiger PROPFIND darf nichts löschen.');
        $missing = Document::query()->where('filename', 'b.pdf')->firstOrFail();
        $this->assertNotNull($missing->getAttribute('missing_since'));
        $this->assertNull($missing->getAttribute('deleted_at'));
        $this->assertSame(1, SyncEvent::query()->where('action', 'missing')->where('entity_id', $missing->getKey())->count());

        // Zweites Fehlen ohne bestätigten Health-Check: weiterhin kein Soft Delete (Punkt 5).
        $this->travel(1)->minute();
        $connection->forceFill(['last_health_ok' => false])->save();
        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
        $this->assertSame(0, $result->deleted);
        $this->assertSame(2, Document::query()->count());

        // Zweites Fehlen im gesunden Folgelauf: Soft Delete mit deletion_reason missing_twice (Punkt 4).
        $this->travel(1)->minute();
        $connection->forceFill(['last_health_ok' => true])->save();
        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $this->assertSame(1, $result->deleted);
        $this->assertSame(1, Document::query()->count(), 'Soft Delete, kein Hard Delete');
        $this->assertSame(2, Document::query()->withTrashed()->count());

        $deleted = Document::query()->withTrashed()->where('filename', 'b.pdf')->firstOrFail();
        $this->assertNotNull($deleted->getAttribute('deleted_at'));
        $this->assertNotNull($deleted->getAttribute('missing_since'));
        $this->assertSame('missing_twice', $deleted->getAttribute('deletion_reason'));
        $this->assertSame(1, SyncEvent::query()->where('action', 'soft_deleted')->where('entity_id', $deleted->getKey())->count());

        $this->travel(1)->minute();
        $this->tree['/Posteingang/'][2] = ['href' => '/Posteingang/b.pdf', 'etag' => 'b1', 'length' => 20, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'];
        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));

        $deleted->refresh();
        $this->assertNull($deleted->getAttribute('deleted_at'));
        $this->assertNull($deleted->getAttribute('deletion_reason'));
        $this->assertNull($deleted->getAttribute('missing_since'));
        $this->assertSame(1, SyncEvent::query()->where('action', 'restored')->count());
        $this->assertSame(2, Document::query()->withTrashed()->count(), 'kein Duplikat beim Wiederauftauchen');
    }

    public function test_truncated_listing_never_triggers_sweep(): void
    {
        $connection = $this->readConnection();
        config()->set('hub.documents.scan.roots', ['/Posteingang/']);
        config()->set('hub.documents.scan.max_entries_per_folder', 2);

        $entries = [['href' => '/Posteingang/', 'collection' => true]];

        foreach (['a', 'b', 'c'] as $name) {
            $entries[] = ['href' => '/Posteingang/'.$name.'.pdf', 'etag' => $name.'1', 'length' => 10, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'];
        }

        $this->fakeTree($connection, ['/Posteingang/' => $entries]);
        $connector = $this->resolve($connection);

        // Erster Lauf: nur zwei Einträge verarbeitet (Kappung), c.pdf wird nie gelistet.
        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
        $this->assertSame(2, Document::query()->count());

        // Ohne Kappung wäre c.pdf jetzt bekannt; Grenze anheben, dann wieder senken: c.pdf darf nie gesweept werden.
        config()->set('hub.documents.scan.max_entries_per_folder', 10);
        $this->travel(1)->minute();
        $connector = $this->resolve($connection);
        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
        $this->assertSame(3, Document::query()->count());

        config()->set('hub.documents.scan.max_entries_per_folder', 2);
        $connector = $this->resolve($connection);

        for ($run = 0; $run < 3; $run++) {
            $this->travel(1)->minute();
            $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
            $this->assertSame(0, $result->deleted);
        }

        $this->assertSame(3, Document::query()->count(), 'gekappte Auflistung löst keinen Sweep aus');
        $this->assertNull(Document::query()->where('filename', 'c.pdf')->firstOrFail()->getAttribute('missing_since'));

        $folder = DocumentFolder::query()->where('path', '/Posteingang/')->firstOrFail();
        $conflict = Conflict::query()->where('conflict_type', 'listing_truncated')->where('entity_id', $folder->getKey())->firstOrFail();
        $this->assertSame(4, (int) $conflict->getAttribute('occurrences'), 'ein offener Konflikt je Ordner, Wiederholungen zählen hoch');
        $this->assertSame('active', ImmowareConnection::query()->findOrFail($connection->getKey())->getAttribute('status'), 'Kappung ist kein Massenfehlen');
    }

    public function test_mass_missing_blocks_sweep_degrades_connection_and_opens_conflict(): void
    {
        $connection = $this->readConnection();
        config()->set('hub.documents.scan.roots', ['/Posteingang/']);
        config()->set('hub.documents.sweep.min_count_for_ratio', 5);
        config()->set('hub.documents.sweep.max_missing_ratio', 0.2);

        $entries = [['href' => '/Posteingang/', 'collection' => true]];

        for ($i = 1; $i <= 10; $i++) {
            $entries[] = ['href' => '/Posteingang/d'.$i.'.pdf', 'etag' => 'e'.$i, 'length' => 10, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'];
        }

        $this->fakeTree($connection, ['/Posteingang/' => $entries]);
        $connector = $this->resolve($connection);
        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
        $this->assertSame(10, Document::query()->count());

        // Teilantwort: nur noch 5 von 10 Dateien gelistet (50 Prozent fehlen), zwei Läufe hintereinander.
        $this->tree['/Posteingang/'] = array_slice($entries, 0, 6);

        for ($run = 0; $run < 2; $run++) {
            $this->travel(1)->minute();
            $result = $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Incremental));
            $this->assertSame(0, $result->deleted);
        }

        $this->assertSame(10, Document::query()->count(), 'Schutzgrenze: kein Soft Delete');
        $this->assertSame(5, Document::query()->whereNotNull('missing_since')->count());

        $fresh = ImmowareConnection::query()->findOrFail($connection->getKey());
        $this->assertSame('degraded', $fresh->getAttribute('status'));
        $this->assertSame('mass_missing', $fresh->getAttribute('degraded_reason'));

        $folder = DocumentFolder::query()->where('path', '/Posteingang/')->firstOrFail();
        $this->assertSame(1, Conflict::query()->where('conflict_type', 'uncertain_identity')->where('entity_type', 'document_folder')->where('entity_id', $folder->getKey())->count());
    }

    public function test_move_is_detected_via_content_hash_and_pull_chunks_with_cursor(): void
    {
        $connection = $this->readConnection();
        config()->set('hub.documents.content_hash.enabled', true);
        config()->set('hub.documents.scan.folders_per_run', 1);
        config()->set('hub.documents.scan.roots', ['/Dokumente/']);

        $this->contents['/Dokumente/Alt/vertrag.pdf'] = 'vertragsinhalt';
        $this->contents['/Dokumente/Neu/vertrag.pdf'] = 'vertragsinhalt';

        $this->fakeTree($connection, [
            '/Dokumente/' => [
                ['href' => '/Dokumente/', 'collection' => true],
                ['href' => '/Dokumente/Alt/', 'collection' => true],
                ['href' => '/Dokumente/Neu/', 'collection' => true],
            ],
            '/Dokumente/Alt/' => [
                ['href' => '/Dokumente/Alt/', 'collection' => true],
                ['href' => '/Dokumente/Alt/vertrag.pdf', 'etag' => 'x1', 'length' => 14, 'modified' => 'Fri, 11 Sep 2026 10:00:00 GMT'],
            ],
            '/Dokumente/Neu/' => [
                ['href' => '/Dokumente/Neu/', 'collection' => true],
            ],
        ]);

        $connector = $this->resolve($connection);

        // Chunking: drei Ordner, ein Ordner je Lauf, Cursor trägt die Queue.
        $request = new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Full);
        $first = $connector->pull($request);
        $this->assertNotNull($first->cursor);
        $this->assertStringContainsString('/Dokumente/Alt/', $first->cursor);

        $second = $connector->pull($request->withCursor($first->cursor));
        $this->assertNotNull($second->cursor);
        $third = $connector->pull($request->withCursor($second->cursor));
        $this->assertNull($third->cursor);

        $document = Document::query()->where('filename', 'vertrag.pdf')->firstOrFail();
        $this->assertSame(hash('sha256', 'vertragsinhalt'), $document->getAttribute('content_hash'));
        $this->assertSame('/Dokumente/Alt/vertrag.pdf', $document->getAttribute('path'));
        $this->assertSame(1, SyncRun::query()->count(), 'ein Lauf über alle Chunks');

        // Datei wird verschoben: Alt leer, Neu enthält die Datei mit gleichem Inhalt, alter Pfad antwortet 404.
        $this->travel(1)->minute();
        unset($this->tree['/Dokumente/Alt/'][1]);
        $this->tree['/Dokumente/Neu/'][] = ['href' => '/Dokumente/Neu/vertrag.pdf', 'etag' => 'x2', 'length' => 14, 'modified' => 'Sat, 12 Sep 2026 08:00:00 GMT'];
        unset($this->contents['/Dokumente/Alt/vertrag.pdf']);
        config()->set('hub.documents.scan.folders_per_run', 10);

        $connector->pull(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Full));

        $this->assertSame(1, Document::query()->withTrashed()->count(), 'Move statt delete plus create');
        $document->refresh();
        $this->assertSame('/Dokumente/Neu/vertrag.pdf', $document->getAttribute('path'));
        $this->assertSame('/Dokumente/Neu/vertrag.pdf', $document->getAttribute('external_id'));
        $this->assertNull($document->getAttribute('deleted_at'));
        $this->assertSame(1, SyncEvent::query()->where('action', 'moved')->count());
        $this->assertSame(1, Document::query()->count(), 'kein zweiter aktiver Datensatz, kein Soft Delete verbleibt');
    }

    public function test_push_is_blocked(): void
    {
        $connection = $this->readConnection();
        Http::fake();

        $this->expectException(WriteBlockedException::class);
        $this->resolve($connection)->push(new SyncRequest((int) $connection->getKey(), 'document', SyncMode::Event));
    }

    private function resolve(ImmowareConnection $connection): WebDavConnector
    {
        $connector = $this->app->make(ConnectorManager::class)->resolve($connection);
        $this->assertInstanceOf(WebDavConnector::class, $connector);

        return $connector;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $tree
     */
    private function fakeTree(ImmowareConnection $connection, array $tree): void
    {
        $this->tree = $tree;
        $base = $this->basePathOf($connection);

        Http::fake(function (Request $request) use ($connection, $base): PromiseInterface {
            $path = $this->relativePath($request, $connection);

            if ($request->method() === 'PROPFIND') {
                $depth = $request->header('Depth')[0] ?? '1';

                if ($depth === '0') {
                    foreach ($this->tree as $folder => $entries) {
                        foreach ($entries as $entry) {
                            if (rtrim((string) $entry['href'], '/') === rtrim($path, '/')) {
                                return Http::response($this->multistatus($base, [$entry]), 207);
                            }
                        }
                    }

                    return Http::response('', 404);
                }

                $folder = rtrim($path, '/').'/';

                if (! isset($this->tree[$folder])) {
                    return Http::response('', 404);
                }

                return Http::response($this->multistatus($base, array_values($this->tree[$folder])), 207);
            }

            if ($request->method() === 'GET') {
                return isset($this->contents[$path])
                    ? Http::response($this->contents[$path], 200, ['Content-Type' => 'application/pdf'])
                    : Http::response('', 404);
            }

            return Http::response('', 405);
        });
    }
}
