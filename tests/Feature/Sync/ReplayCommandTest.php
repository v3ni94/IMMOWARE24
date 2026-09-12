<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Models\DocumentFolder;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Models\SyncEvent;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Services\ExternalPayloadArchiver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

final class ReplayCommandTest extends SyncTestCase
{
    private const string VCARD = "BEGIN:VCARD\r\nVERSION:4.0\r\nUID:urn:uuid:replay-1\r\nFN:Anna Beispiel\r\nN:Beispiel;Anna;;;\r\nEMAIL;TYPE=work:anna.beispiel@example.com\r\nEND:VCARD\r\n";

    private const string ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:ev-replay-1\r\nDTSTART;VALUE=DATE:20261003\r\nSUMMARY:Eigentümerversammlung\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private const string PROPFIND = '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:">'
        .'<D:response><D:href>/Posteingang/</D:href><D:propstat><D:prop><D:resourcetype><D:collection/></D:resourcetype></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
        .'<D:response><D:href>/Posteingang/Rechnung%201.pdf</D:href><D:propstat><D:prop><D:resourcetype/><D:getetag>"r1"</D:getetag>'
        .'<D:getlastmodified>Fri, 11 Sep 2026 10:00:00 GMT</D:getlastmodified><D:getcontentlength>1234</D:getcontentlength><D:getcontenttype>application/pdf</D:getcontenttype>'
        .'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
        .'<D:response><D:href>/Posteingang/Archiv/</D:href><D:propstat><D:prop><D:resourcetype><D:collection/></D:resourcetype></D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
        .'</D:multistatus>';

    public function test_contact_replay_rebuilds_mirror_row_and_is_idempotent(): void
    {
        $connection = $this->activeConnection('carddav_contacts');
        $payload = $this->archive($connection->getKey(), 'vcard', self::VCARD, 'urn:uuid:replay-1', ['href' => '/addressbooks/hub/replay-1.vcf', 'etag' => '"e1"'], true);

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'contact', 'external_id' => 'urn:uuid:replay-1']));
        $output = Artisan::output();
        $this->assertStringContainsString('1 Nutzlasten vom Typ vcard', $output);

        $contact = Contact::query()->withoutGlobalScope('organization')->firstOrFail();
        $this->assertSame('replay-1', $contact->getAttribute('external_id'), 'Mapper entfernt das Präfix urn:uuid:.');
        $this->assertSame('Anna', $contact->getAttribute('first_name'));
        $this->assertSame((int) $connection->getKey(), (int) $contact->getAttribute('connection_id'));
        $this->assertSame((int) $connection->getAttribute('organization_id'), (int) $contact->getAttribute('organization_id'));
        $this->assertSame('"e1"', $contact->getAttribute('remote_etag'));
        $checksum = $contact->getAttribute('checksum');

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'contact', '--all' => true]));
        $this->assertStringContainsString('unchanged', Artisan::output());
        $this->assertSame(1, Contact::query()->withoutGlobalScope('organization')->count());
        $this->assertSame($checksum, Contact::query()->withoutGlobalScope('organization')->firstOrFail()->getAttribute('checksum'));

        $audit = AuditLog::query()->where('action', 'sync.replay')->get();
        $this->assertCount(2, $audit);
        $this->assertNotNull($payload->getKey());
    }

    public function test_dry_run_counts_without_writing_and_pseudonymized_payloads_are_skipped(): void
    {
        $connection = $this->activeConnection('carddav_contacts');
        $this->archive($connection->getKey(), 'vcard', self::VCARD, 'urn:uuid:replay-1', ['href' => '/a/1.vcf'], true);
        $pseudonymized = $this->archive($connection->getKey(), 'vcard', self::VCARD, 'urn:uuid:replay-2', ['href' => '/a/2.vcf'], true);
        $pseudonymized->forceFill(['pseudonymized_at' => CarbonImmutable::now(), 'content_inline' => null])->save();

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'contact', '--all' => true, '--dry-run' => true]));
        $this->assertStringContainsString('Dry-Run', Artisan::output());
        $this->assertSame(0, Contact::query()->withoutGlobalScope('organization')->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'sync.replay')->count());

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'contact', '--all' => true]));
        $this->assertStringContainsString('pseudonymisiert', Artisan::output());
        $this->assertSame(1, Contact::query()->withoutGlobalScope('organization')->count());
    }

    public function test_latest_only_replays_newest_payload_per_external_id(): void
    {
        $connection = $this->activeConnection('carddav_contacts');
        $this->archive($connection->getKey(), 'vcard', self::VCARD, 'urn:uuid:replay-1', ['href' => '/a/1.vcf'], true);
        $newer = str_replace('FN:Anna Beispiel', 'FN:Anna Neu', str_replace('N:Beispiel;Anna;;;', 'N:Neu;Anna;;;', self::VCARD));
        $this->archive($connection->getKey(), 'vcard', $newer, 'urn:uuid:replay-1', ['href' => '/a/1.vcf'], true);

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'contact', '--all' => true, '--latest' => true]));
        $this->assertStringContainsString('1 Nutzlasten', Artisan::output());
        $this->assertSame('Neu', Contact::query()->withoutGlobalScope('organization')->firstOrFail()->getAttribute('last_name'));
    }

    public function test_calendar_replay_requires_collection_path_and_creates_event(): void
    {
        $connection = $this->activeConnection('caldav_calendar');
        $this->archive($connection->getKey(), 'ical', self::ICS, 'ev-replay-1', ['href' => '/calendars/hub/ev-replay-1.ics', 'collection_path' => '/calendars/hub/'], true);

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'calendar_event', '--all' => true]));
        $event = CalendarEvent::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ev-replay-1', $event->getAttribute('external_id'));
        $this->assertSame('Eigentümerversammlung', $event->getAttribute('summary'));

        $this->archive($connection->getKey(), 'ical', self::ICS, 'ev-replay-2', ['href' => '/calendars/hub/ev-replay-2.ics'], true);
        $this->assertSame(1, Artisan::call('hub:replay', ['entity' => 'calendar_event', 'external_id' => 'ev-replay-2']));
        $this->assertStringContainsString('collection_path', Artisan::output());
    }

    public function test_document_replay_restores_folder_and_document_without_remote_requests(): void
    {
        Http::fake();
        $connection = $this->activeConnection();
        $this->archive($connection->getKey(), 'propfind_xml', self::PROPFIND, '/Posteingang/', ['path' => '/Posteingang/', 'base_url' => (string) $connection->getAttribute('base_url')], false);

        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'document', '--all' => true]));
        Http::assertNothingSent();

        $this->assertSame(2, DocumentFolder::query()->withoutGlobalScopes()->count());
        $document = Document::query()->withoutGlobalScopes()->firstOrFail();
        $this->assertSame('/Posteingang/Rechnung 1.pdf', $document->getAttribute('external_id'));
        $this->assertSame(1234, (int) $document->getAttribute('size_bytes'));
        $this->assertSame('"r1"', $document->getAttribute('remote_etag'));

        $run = SyncRun::query()->firstOrFail();
        $this->assertSame(SyncRun::TYPE_REPLAY, $run->getAttribute('run_type'));
        $this->assertSame('replay', $run->getAttribute('trigger_source'));
        $this->assertSame('succeeded', $run->getAttribute('status')->value);
        $this->assertSame(1, SyncEvent::query()->where('action', 'created')->count());

        // Zweiter Replay derselben Nutzlast: keine neue Version, kein Soft Delete.
        $this->assertSame(0, Artisan::call('hub:replay', ['entity' => 'document', '--all' => true]));
        $this->assertSame(1, Document::query()->withoutGlobalScopes()->count());
        $this->assertNull(Document::query()->withoutGlobalScopes()->firstOrFail()->getAttribute('deleted_at'));
        $this->assertSame(1, SyncEvent::query()->where('action', 'created')->count());
    }

    public function test_rejects_unknown_entity_and_missing_selector(): void
    {
        $this->assertSame(1, Artisan::call('hub:replay', ['entity' => 'invoice', '--all' => true]));
        $this->assertStringContainsString('keinen Replay', Artisan::output());

        $this->assertSame(1, Artisan::call('hub:replay', ['entity' => 'contact']));
        $this->assertStringContainsString('external_id oder --all', Artisan::output());

        $this->assertSame(1, Artisan::call('hub:replay', ['entity' => 'contact', '--all' => true, '--from' => 'api']));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function archive(mixed $connectionId, string $type, string $content, string $externalId, array $metadata, bool $personal): ExternalPayload
    {
        return $this->app->make(ExternalPayloadArchiver::class)->archive((int) $connectionId, $type, $content, $externalId, null, $metadata, 207, $metadata['etag'] ?? null, null, $personal);
    }
}
