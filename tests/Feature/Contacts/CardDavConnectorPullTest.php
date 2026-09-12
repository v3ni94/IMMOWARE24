<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\RemoteRequest;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Contacts\Dav\HttpDavTransport;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactIdentifier;
use App\Modules\Contacts\Models\ContactMerge;
use App\Modules\Contacts\Models\ContactRole;
use App\Modules\Contacts\Services\CardDavConnector;
use App\Modules\Contacts\Services\DavClientFactory;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Models\SyncState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CardDavConnectorPullTest extends TestCase
{
    use RefreshDatabase;

    private const string PATH = '/carddav/addressbooks/hub-read/kontakte/';

    private FakeDavServer $server;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new FakeDavServer('dav.immoware.test', self::PATH);
        $this->connection = $this->createConnection(null, [
            'connector_type' => 'carddav_contacts',
            'base_url' => $this->server->url(),
            'base_url_hash' => hash('sha256', $this->server->url()),
            'status' => 'active',
            'rate_limit_rps' => 50,
            'last_health_ok' => true,
        ]);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Unit/Contacts/Fixtures/'.$name));
    }

    private function pull(SyncMode $mode = SyncMode::Incremental): SyncResult
    {
        return $this->app->make(CardDavConnector::class)->pull(new SyncRequest((int) $this->connection->getKey(), 'contact', $mode));
    }

    private function requestCount(string $method, ?string $bodyContains = null): int
    {
        $count = 0;
        Http::assertSent(function (Request $request) use ($method, $bodyContains, &$count): bool {
            if ($request->method() === $method && ($bodyContains === null || str_contains($request->body(), $bodyContains))) {
                $count++;
            }

            return true;
        });

        return $count;
    }

    public function test_initial_pull_mirrors_contacts_with_identifiers(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->put(self::PATH.'c2.vcf', 'e2', $this->fixture('quoted-printable.vcf'));
        $this->server->install();

        $result = $this->pull();

        $this->assertSame(2, $result->processed);
        $this->assertSame(2, $result->created);
        $this->assertFalse($result->hasErrors());

        $contact = Contact::query()->where('vcard_uid', 'c1-mueller')->firstOrFail();
        $this->assertSame('Jürgen', $contact->first_name);
        $this->assertSame('Müller-Lüdenscheidt', $contact->last_name);
        $this->assertSame('Hausverwaltung Müller GmbH', $contact->company_name);
        $this->assertSame('c1-mueller', $contact->external_id);
        $this->assertSame(self::PATH.'c1.vcf', $contact->vcard_href);
        $this->assertSame('"e1"', $contact->remote_etag);
        $this->assertSame('exact', $contact->identity_confidence);
        $this->assertSame('immoware24', $contact->source_system);
        $this->assertNotNull($contact->checksum);
        $this->assertSame(1, $contact->sync_version);
        $this->assertSame(['Eigentümer', 'Mieter'], $contact->categories);
        $this->assertSame('01719876543', $contact->phones[1]['value']);

        $identifiers = ContactIdentifier::query()->where('contact_id', $contact->getKey())->pluck('value_normalized')->all();
        $this->assertContains('+4902103123456', $identifiers);
        $this->assertContains('01719876543', $identifiers);
        $this->assertContains('juergen.mueller@example.de', $identifiers, 'Domain kleingeschrieben');
        $this->assertCount(5, $identifiers);

        $this->assertSame(1, $this->requestCount('PROPFIND'));
        $this->assertSame(1, $this->requestCount('REPORT', 'addressbook-query'));
        $this->assertSame(1, $this->requestCount('REPORT', 'addressbook-multiget'));
        $this->assertSame(0, ContactRole::query()->count(), 'Ohne Mapping-Regel entstehen keine Rollen');
    }

    public function test_unchanged_ctag_causes_no_requests_besides_propfind(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->install();
        $this->pull();

        Http::fake();
        $this->server->install();
        $result = $this->pull();

        $this->assertSame(0, $result->processed);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PROPFIND');
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_etag_diff_loads_only_changed_resources(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->put(self::PATH.'c2.vcf', 'e2', $this->fixture('quoted-printable.vcf'));
        $this->server->install();
        $this->pull();

        $this->server->ctag = 'ctag-2';
        $this->server->put(self::PATH.'c2.vcf', 'e2b', str_replace('rene@example.com', 'rene.neu@example.com', $this->fixture('quoted-printable.vcf')));
        $this->server->put(self::PATH.'c3.vcf', 'e3', $this->fixture('folded-v4.vcf'));
        Http::fake();
        $this->server->install();

        $result = $this->pull();

        $this->assertSame(2, $result->processed);
        $this->assertSame(1, $result->created);
        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $result->deleted);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'REPORT' || ! str_contains($request->body(), 'multiget')) {
                return true;
            }

            $this->assertStringContainsString('c2.vcf', $request->body());
            $this->assertStringContainsString('c3.vcf', $request->body());
            $this->assertStringNotContainsString('c1.vcf', $request->body(), 'unveränderter ETag wird nicht geladen');

            return true;
        });

        $updated = Contact::query()->where('vcard_uid', 'c2-qp')->firstOrFail();
        $this->assertSame(2, $updated->sync_version);
        $this->assertSame('rene.neu@example.com', $updated->emails[0]['value']);
        $this->assertSame(1, Contact::query()->where('vcard_uid', 'c1-mueller')->firstOrFail()->sync_version);
    }

    public function test_multiget_is_batched_in_chunks_of_50(): void
    {
        for ($i = 1; $i <= 120; $i++) {
            $this->server->put(self::PATH."m{$i}.vcf", "e{$i}", "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:m-{$i}\r\nFN:Kontakt {$i}\r\nN:{$i};Kontakt;;;\r\nEND:VCARD\r\n");
        }
        $this->server->install();

        $result = $this->pull();

        $this->assertSame(120, $result->created);
        $this->assertSame(3, $this->requestCount('REPORT', 'addressbook-multiget'));
    }

    public function test_sweep_marks_missing_first_and_soft_deletes_only_in_second_healthy_run(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->put(self::PATH.'c2.vcf', 'e2', $this->fixture('quoted-printable.vcf'));
        $this->server->install();
        $this->pull();

        // Erstes Fehlen: nur missing_since (07-sync-strategy.md Abschnitt 4 Punkt 3), kein Soft Delete.
        unset($this->server->resources[self::PATH.'c2.vcf']);
        $this->server->ctag = 'ctag-2';
        Http::fake();
        $this->server->install();
        $result = $this->pull();

        $this->assertSame(0, $result->deleted, 'Eine einzelne unvollständige Liste darf nichts löschen.');
        $this->assertSame(2, Contact::query()->count());
        $missing = Contact::query()->where('vcard_uid', 'c2-qp')->firstOrFail();
        $this->assertNotNull($missing->missing_since);
        $this->assertNull($missing->deleted_at);

        // Zweites Fehlen ohne bestätigten Health-Check: weiterhin kein Soft Delete (Punkt 5).
        $this->connection->forceFill(['last_health_ok' => false])->save();
        $this->server->ctag = 'ctag-3';
        Http::fake();
        $this->server->install();
        $this->assertSame(0, $this->pull()->deleted);
        $this->assertSame(2, Contact::query()->count());

        // Zweites Fehlen im gesunden Lauf: Soft Delete mit deletion_reason missing_twice (Punkt 4).
        $this->connection->forceFill(['last_health_ok' => true])->save();
        $this->server->ctag = 'ctag-4';
        Http::fake();
        $this->server->install();
        $result = $this->pull();

        $this->assertSame(1, $result->deleted);
        $this->assertSame(1, Contact::query()->count());
        $gone = Contact::query()->withTrashed()->where('vcard_uid', 'c2-qp')->firstOrFail();
        $this->assertNotNull($gone->deleted_at);
        $this->assertNotNull($gone->missing_since);
        $this->assertSame('missing_twice', $gone->deletion_reason);
        $this->assertSame(2, Contact::query()->withTrashed()->count(), 'kein Hard Delete');
    }

    public function test_sweep_counts_misses_even_without_state_row(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->put(self::PATH.'c2.vcf', 'e2', $this->fixture('quoted-printable.vcf'));
        $this->server->install();
        $this->pull();

        // Ressourcenzeile fehlt (z. B. älterer Spiegelstand): markMissing legt sie an, statt dauerhaft 1 zu liefern.
        SyncState::query()->where('scope', 'resource')->delete();
        unset($this->server->resources[self::PATH.'c2.vcf']);

        foreach (['ctag-2', 'ctag-3'] as $ctag) {
            $this->server->ctag = $ctag;
            Http::fake();
            $this->server->install();
            $result = $this->pull();
        }

        $this->assertSame(1, $result->deleted);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_mass_missing_blocks_sweep_degrades_connection_and_opens_conflict(): void
    {
        config()->set('hub.contacts.sweep.min_count_for_ratio', 5);

        for ($i = 1; $i <= 10; $i++) {
            $this->server->put(self::PATH.'m'.$i.'.vcf', 'e'.$i, str_replace('c1-mueller', 'm-'.$i, $this->fixture('umlaute.vcf')));
        }

        $this->server->install();
        $this->pull();
        $this->assertSame(10, Contact::query()->count());

        // Teilantwort: nur noch 5 von 10 Kontakten gelistet, zwei Läufe hintereinander.
        for ($i = 6; $i <= 10; $i++) {
            unset($this->server->resources[self::PATH.'m'.$i.'.vcf']);
        }

        foreach (['ctag-2', 'ctag-3'] as $ctag) {
            $this->server->ctag = $ctag;
            Http::fake();
            $this->server->install();
            $result = $this->pull();
            $this->assertSame(0, $result->deleted);
        }

        $this->assertSame(10, Contact::query()->count(), 'Schutzgrenze: kein Soft Delete');
        $this->assertSame(5, Contact::query()->whereNotNull('missing_since')->count());
        $this->assertSame('sweep_blocked_mass_missing', $result->errors[0]['reason'] ?? null);

        $fresh = ImmowareConnection::query()->findOrFail($this->connection->getKey());
        $this->assertSame('degraded', $fresh->getAttribute('status'));
        $this->assertSame('mass_missing', $fresh->getAttribute('degraded_reason'));
        $this->assertSame(1, Conflict::query()->where('conflict_type', 'uncertain_identity')->where('entity_type', 'contact_collection')->count());
    }

    public function test_missing_uid_uses_href_as_external_id_with_flag(): void
    {
        $this->server->put(self::PATH.'nouid.vcf', 'e9', $this->fixture('missing-uid.vcf'));
        $this->server->install();

        $this->pull();

        $contact = Contact::query()->firstOrFail();
        $this->assertNull($contact->vcard_uid);
        $this->assertSame('href:'.self::PATH.'nouid.vcf', $contact->external_id);
        $this->assertSame('uid_missing', $contact->identity_confidence);
    }

    public function test_duplicate_candidates_are_proposed_but_never_merged(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $duplicate = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1-dublette\r\nFN:J. Müller\r\nN:Müller;J.;;;\r\nEMAIL;TYPE=WORK:juergen.mueller@example.de\r\nEND:VCARD\r\n";
        $this->server->put(self::PATH.'dup.vcf', 'e2', $duplicate);
        $phoneDuplicate = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1-telefon\r\nFN:Handy Müller\r\nTEL;TYPE=CELL:0171 / 98 76 543\r\nEND:VCARD\r\n";
        $this->server->put(self::PATH.'tel.vcf', 'e3', $phoneDuplicate);
        $this->server->install();

        $this->pull();

        $this->assertSame(3, Contact::query()->count());
        $this->assertSame(2, ContactMerge::query()->proposed()->count());
        $this->assertSame(['email', 'phone'], ContactMerge::query()->orderBy('id')->pluck('match_kind')->all());

        $proposal = ContactMerge::query()->proposed()->firstOrFail();
        $this->assertFalse($proposal->isActive());
        $this->assertNull($proposal->is_active);
        $this->assertNull($proposal->merged_at);
        $this->assertNull($proposal->merged_by);

        $this->assertSame(0, Contact::query()->whereNotNull('merged_into_id')->count(), 'nie automatisch mergen');

        // Zweiter Lauf legt keine doppelten Vorschläge an
        $this->server->ctag = 'ctag-2';
        Http::fake();
        $this->server->install();
        $this->pull(SyncMode::Full);
        $this->assertSame(2, ContactMerge::query()->count());
    }

    public function test_roles_from_categories_only_with_mapping_rule_and_person_stays_single_contact(): void
    {
        config()->set('hub.contacts.category_roles', ['Eigentümer' => 'owner', 'Mieter' => 'tenant']);
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->put(self::PATH.'c3.vcf', 'e3', $this->fixture('folded-v4.vcf')); // CATEGORIES:Beirat ohne Regel
        $this->server->install();

        $this->pull();

        $this->assertSame(2, Contact::query()->count());
        $contact = Contact::query()->where('vcard_uid', 'c1-mueller')->firstOrFail();
        $roles = ContactRole::query()->where('contact_id', $contact->getKey())->pluck('role')->map(fn ($r) => $r->value)->sort()->values()->all();
        $this->assertSame(['owner', 'tenant'], $roles);
        $this->assertSame(2, ContactRole::query()->count(), 'Beirat ohne Regel erzeugt keine Rolle');
        $this->assertSame($contact->external_id, ContactRole::query()->first()?->external_parent_id);
    }

    public function test_sync_collection_is_used_when_probe_reports_support(): void
    {
        $this->connection->forceFill(['probe_result' => ['sync_token_supported' => true]])->save();
        $this->server->supportsSyncCollection = true;
        $this->server->syncToken = 'tok-1';
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->put(self::PATH.'c2.vcf', 'e2', $this->fixture('quoted-printable.vcf'));
        $this->server->install();
        $first = $this->pull();
        $this->assertNull($first->cursor, 'sync-token ist kein Fortsetzungscursor (RunSyncJob würde sonst endlos weiterlaufen)');
        $this->assertSame('tok-1', $first->tokens['sync_token']);

        // Delta: c2 geändert, c1 gelöscht laut Server
        $this->server->syncToken = 'tok-2';
        $this->server->ctag = 'ctag-2';
        $this->server->put(self::PATH.'c2.vcf', 'e2b', str_replace('rene@example.com', 'rene.sync@example.com', $this->fixture('quoted-printable.vcf')));
        $this->server->syncChanged = [self::PATH.'c2.vcf'];
        $this->server->syncDeleted = [self::PATH.'c1.vcf'];
        Http::fake();
        $this->server->install();

        $result = $this->pull();

        $this->assertNull($result->cursor);
        $this->assertSame('tok-2', $result->tokens['sync_token']);
        $this->assertSame(1, $result->updated);
        $this->assertSame(0, $this->requestCount('REPORT', 'addressbook-query'), 'kein Depth-1-Vergleich bei sync-collection');
        $this->assertSame(1, $this->requestCount('REPORT', 'sync-collection'));

        $c1 = Contact::query()->where('vcard_uid', 'c1-mueller')->firstOrFail();
        $this->assertNotNull($c1->missing_since, 'Löschung aus sync-collection nur als missing_since');
        $this->assertNull($c1->deleted_at, 'Soft Delete erst nach Bestätigung durch vollständige Enumeration');
    }

    public function test_push_is_blocked(): void
    {
        $this->expectException(WriteBlockedException::class);

        $this->app->make(CardDavConnector::class)->push(new SyncRequest((int) $this->connection->getKey(), 'contact', SyncMode::Incremental));
    }

    public function test_test_connection_reports_propfind_result(): void
    {
        $this->server->install();

        $connector = $this->app->make(CardDavConnector::class)->forConnection((int) $this->connection->getKey());

        $result = $connector->testConnection();

        $this->assertTrue($result->ok);
        $this->assertTrue($connector->authenticate());
        $this->assertSame(['contacts.read'], $connector->capabilities());
        $this->assertSame('carddav', $connector->name());
    }

    public function test_transport_rejects_write_methods(): void
    {
        $this->server->install();
        $transport = $this->app->make(DavClientFactory::class)->transport($this->connection);
        $this->assertInstanceOf(HttpDavTransport::class, $transport);

        try {
            $transport->request('PUT', $this->server->url().'x.vcf', ['If-None-Match' => '*'], 'BEGIN:VCARD');
            $this->fail('PUT muss für CardDAV gesperrt sein.');
        } catch (WriteBlockedException) {
            Http::assertNothingSent();
        }

        // Der Methoden-Guard der HttpClientFactory sperrt PUT für CardDAV-Kontexte auch ohne den Transport.
        $context = $this->app->make(ConnectorManager::class)->contextFor($this->connection);
        $this->expectException(WriteBlockedException::class);
        $this->app->make(HttpClientFactory::class)->for($context, 'write')->withHeaders(['If-None-Match' => '*'])->withBody('x', 'text/vcard')->send('PUT', '/x.vcf');
    }

    public function test_transport_runs_through_connector_infrastructure_and_respects_auth_scheme(): void
    {
        $this->server->put(self::PATH.'c1.vcf', 'e1', $this->fixture('umlaute.vcf'));
        $this->server->install();

        $this->pull();

        // Jeder Request ist in remote_requests protokolliert (RemoteRequestLogger über HttpClientFactory), mit User-Agent
        // und Basic-Auth aus dem ConnectorContext (auth_scheme unknown oder basic).
        $logged = RemoteRequest::query()->where('connection_id', $this->connection->getKey())->get();
        $this->assertGreaterThanOrEqual(3, $logged->count(), 'PROPFIND, addressbook-query, multiget');
        $this->assertSame(['carddav:read'], $logged->pluck('connector_name')->unique()->values()->all());
        Http::assertSent(fn (Request $request): bool => str_starts_with((string) ($request->header('User-Agent')[0] ?? ''), 'ImmowareHub/') && str_starts_with((string) ($request->header('Authorization')[0] ?? ''), 'Basic '));

        // auth_scheme der Connection (Probe-Ergebnis) fließt in den Kontext des Transports ein, nichts ist fest verdrahtet.
        $this->connection->forceFill(['auth_scheme' => 'digest'])->save();
        $context = $this->app->make(ConnectorManager::class)->contextFor($this->connection->fresh() ?? $this->connection);
        $this->assertSame('digest', $context->authScheme);
        $this->assertInstanceOf(HttpDavTransport::class, $this->app->make(DavClientFactory::class)->transport($this->connection->fresh() ?? $this->connection));
    }
}
