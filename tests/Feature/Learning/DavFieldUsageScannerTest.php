<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\DavClientFactory;
use App\Modules\Learning\Services\CalDavFieldUsageScanner;
use App\Modules\Learning\Services\CardDavFieldUsageScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Contacts\FakeDavServer;
use Tests\TestCase;

final class DavFieldUsageScannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_carddav_scan_reports_reachability_and_field_usage_of_mirrored_contacts(): void
    {
        $server = new FakeDavServer('dav.immoware.test', '/carddav/addressbooks/hub-read/kontakte/');
        $server->ctag = 'ctag-42';
        $server->install();

        $connection = $this->createConnection(null, [
            'connector_type' => 'carddav_contacts',
            'base_url' => $server->url(),
            'base_url_hash' => hash('sha256', $server->url()),
            'status' => 'active',
        ]);

        Contact::factory()->for($connection->organization)->create(['salutation' => 'Herr', 'emails' => ['a@example.invalid']]);
        Contact::factory()->for($connection->organization)->create(['salutation' => null, 'emails' => null]);

        $scanner = new CardDavFieldUsageScanner($this->app->make(DavClientFactory::class), (int) $connection->organization->getKey());
        $facts = $scanner->scan($connection);

        $this->assertSame('carddav', $facts['kind']);
        $this->assertTrue($facts['collection']['reachable']);
        $this->assertSame(2, $facts['mirrored_contacts']);
        $this->assertSame(1, $facts['field_usage']['salutation']);
        $this->assertSame(1, $facts['field_usage']['emails']);
    }

    public function test_carddav_scan_reports_unreachable_collection_without_throwing(): void
    {
        Http::fake(fn () => Http::response('nicht verfügbar', 503));

        $connection = $this->createConnection(null, [
            'connector_type' => 'carddav_contacts',
            'base_url' => 'https://dav.immoware.test/carddav/addressbooks/hub-read/kontakte/',
            'base_url_hash' => hash('sha256', 'x'),
            'status' => 'active',
        ]);

        $scanner = new CardDavFieldUsageScanner($this->app->make(DavClientFactory::class), (int) $connection->organization->getKey());
        $facts = $scanner->scan($connection);

        $this->assertFalse($facts['collection']['reachable']);
        $this->assertArrayHasKey('error', $facts['collection']);
    }

    public function test_caldav_scan_reports_field_usage_of_mirrored_events(): void
    {
        $server = new FakeDavServer('dav.immoware.test', '/caldav/calendars/hub-read/termine/', 'calendar-data');
        $server->install();

        $connection = $this->createConnection(null, [
            'connector_type' => 'caldav_calendar',
            'base_url' => $server->url(),
            'base_url_hash' => hash('sha256', $server->url()),
            'status' => 'active',
        ]);

        CalendarEvent::query()->create([
            'organization_id' => $connection->organization->getKey(), 'external_id' => 'event-1',
            'location' => 'Objekt A', 'all_day' => true,
        ]);
        CalendarEvent::query()->create([
            'organization_id' => $connection->organization->getKey(), 'external_id' => 'event-2',
            'location' => null, 'all_day' => false,
        ]);

        $scanner = new CalDavFieldUsageScanner($this->app->make(DavClientFactory::class), (int) $connection->organization->getKey());
        $facts = $scanner->scan($connection);

        $this->assertSame('caldav', $facts['kind']);
        $this->assertTrue($facts['collection']['reachable']);
        $this->assertSame(2, $facts['mirrored_events']);
        $this->assertSame(1, $facts['field_usage']['location']);
        $this->assertSame(1, $facts['field_usage']['all_day']);
    }
}
