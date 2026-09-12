<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Core\DTO\SyncRequest;
use App\Core\Enums\SyncMode;
use App\Core\Exceptions\CircuitOpenException;
use App\Core\Exceptions\ConnectorException;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Enums\CircuitState;
use App\Modules\Connector\Http\HttpClientFactory;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\CircuitBreaker;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Services\RateLimitManager;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Services\CardDavConnector;
use App\Modules\Documents\Connectors\WebDavConnector;
use App\Modules\Documents\Http\WebDavClientFactory;
use App\Modules\Documents\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fehlerszenarien des Mock-Servers gegen den echten WebDavConnector (Http-Facade ohne Fake, echte
 * HttpClientFactory mit RateLimitManager und CircuitBreaker) und den echten CardDavConnector.
 * Der Mock läuft als eigener Prozess; Szenarien werden über das Pfadpräfix /s/<szenario>/ gewählt.
 */
final class MockScenarioTest extends TestCase
{
    use RefreshDatabase;

    private static ?MockServerProcess $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = new MockServerProcess;
        self::$server->start(['MOCK_TIMEOUT_SLEEP' => '3']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->server()->reset();

        // Client-Timeout unter der Wartezeit des Szenarios timeout (3 s), damit der Test schnell bleibt.
        config()->set('hub.connector.http.timeout_seconds', 1);
        config()->set('hub.connector.http.connect_timeout_seconds', 2);
        config()->set('hub.contacts.http.timeout_seconds', 1);
        config()->set('hub.contacts.http.connect_timeout_seconds', 2);
    }

    // ------------------------------------------------------------ WebDAV

    public function test_ok_webdav_spiegelt_fixtures_und_sendet_nur_lesende_methoden(): void
    {
        $connection = $this->webdavConnection('ok');
        $connector = $this->webdav($connection);

        $this->assertTrue($connector->testConnection()->ok);

        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), WebDavConnector::ENTITY_TYPE, SyncMode::Full));

        $this->assertSame(0, $result->failed, json_encode($result->errors));
        $this->assertGreaterThanOrEqual(4, Document::query()->count(), 'Posteingang (2) und Dokumente (2) müssen gespiegelt sein.');
        $this->assertSame([], $this->server()->violations());

        $methods = array_unique(array_column($this->server()->log(), 'method'));
        sort($methods);
        $this->assertSame(['OPTIONS', 'PROPFIND'], $methods, 'Lesender Spiegel darf nur OPTIONS und PROPFIND senden.');
    }

    public function test_unauthorized_oeffnet_breaker_sofort_und_meldet_fehlgeschlagene_verbindung(): void
    {
        $connection = $this->webdavConnection('unauthorized');
        $connector = $this->webdav($connection);

        $this->assertFalse($connector->authenticate());
        $this->assertSame(CircuitState::Open, $this->breaker()->state(CircuitBreaker::keyFor((int) $connection->getKey())), '401 muss den Breaker sofort öffnen.');

        $this->expectException(CircuitOpenException::class);
        $connector->client()->propfind('/', 0);
    }

    public function test_forbidden_und_notfound_sind_fachliche_signale_ohne_breaker(): void
    {
        foreach (['forbidden' => 403, 'notfound' => 404] as $scenario => $status) {
            $connection = $this->webdavConnection($scenario);
            $result = $this->webdav($connection)->client()->propfind('/Posteingang/', 1);

            $this->assertSame($status, $result->status, $scenario);
            $this->assertSame([], $result->entries);
            $this->assertSame(CircuitState::Closed, $this->breaker()->state(CircuitBreaker::keyFor((int) $connection->getKey())), $scenario.' darf den Breaker nicht öffnen.');
        }
    }

    public function test_ratelimited_drosselt_rate_ohne_breaker_bei_retry_after(): void
    {
        $connection = $this->webdavConnection('ratelimited');
        $key = RateLimitManager::keyFor((int) $connection->getKey());

        $result = $this->webdav($connection)->client()->propfind('/', 0);

        $this->assertSame(429, $result->status);
        $this->assertTrue($this->rateLimiter()->isThrottled($key), 'RateLimitManager muss nach 429 drosseln.');
        $this->assertSame(25.0, $this->rateLimiter()->effectiveRps($key), 'Rate muss von 50 auf 25 rps halbiert sein.');
        $this->assertSame(1, $this->rateLimiter()->metrics($key)['429_count']);
        $this->assertSame(CircuitState::Closed, $this->breaker()->state(CircuitBreaker::keyFor((int) $connection->getKey())), '429 mit Retry-After zählt nicht als Breaker-Fehler.');
    }

    public function test_servererror_serie_oeffnet_breaker_und_blockiert_weitere_requests(): void
    {
        $connection = $this->webdavConnection('servererror');
        $client = $this->webdav($connection)->client();
        $key = CircuitBreaker::keyFor((int) $connection->getKey());

        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(500, $client->propfind('/', 0)->status, 'Request '.$i);
        }

        $this->assertSame(CircuitState::Open, $this->breaker()->state($key), 'Fünf 500er innerhalb des Fensters müssen den Breaker öffnen.');
        $this->assertTrue($this->rateLimiter()->isThrottled(RateLimitManager::keyFor((int) $connection->getKey())));

        try {
            $client->propfind('/', 0);
            $this->fail('Sechster Request wurde trotz offenem Breaker gesendet.');
        } catch (CircuitOpenException) {
            $this->addToAssertionCount(1);
        }

        $this->assertCount(5, $this->server()->log(), 'Bei offenem Breaker darf kein Request den Server erreichen.');
    }

    public function test_timeout_wird_als_verbindungsfehler_gemeldet_und_gezaehlt(): void
    {
        $connection = $this->webdavConnection('timeout');
        $client = $this->webdav($connection)->client();

        try {
            $client->propfind('/', 0);
            $this->fail('Timeout hat keine ConnectionException ausgelöst.');
        } catch (ConnectionException) {
            $this->addToAssertionCount(1);
        }

        $metrics = $this->rateLimiter()->metrics(RateLimitManager::keyFor((int) $connection->getKey()));
        $this->assertSame(1, $metrics['timeout_count']);
        $this->assertTrue($metrics['throttled']);
        $this->assertCount(1, $this->breaker()->snapshot(CircuitBreaker::keyFor((int) $connection->getKey()))['failures'], 'Timeout zählt als ein Breaker-Fehler.');
    }

    public function test_invalidxml_erzeugt_connector_exception_statt_leerem_ordner(): void
    {
        $connection = $this->webdavConnection('invalidxml');

        $this->expectException(ConnectorException::class);
        $this->expectExceptionMessage('ohne gültiges Multistatus-XML');

        $this->webdav($connection)->client()->propfind('/Posteingang/', 1);
    }

    public function test_etagunstable_liefert_je_propfind_andere_etags(): void
    {
        $client = $this->webdav($this->webdavConnection('etagunstable'))->client();

        $first = $client->head('/Dokumente/HUBTEST_Protokoll_Muster.pdf')['entry']?->etag;
        $second = $client->head('/Dokumente/HUBTEST_Protokoll_Muster.pdf')['entry']?->etag;

        $this->assertNotNull($first);
        $this->assertNotSame($first, $second, 'Szenario etagunstable muss den ETag je Request ändern.');
    }

    public function test_put_create_only_liefert_201_dann_412_und_client_sendet_nie_put_ohne_if_none_match(): void
    {
        $read = $this->webdavConnection('ok');
        $write = $this->webdavConnection('ok', [
            'name' => 'WebDAV Schreib-Connection Mock',
            'purpose' => 'write',
            'write_enabled' => true,
            'allowed_write_prefix' => '/Posteingang/',
            'paired_read_connection_id' => $read->getKey(),
        ]);
        $client = $this->app->make(WebDavClientFactory::class)->forConnection($write);
        $path = '/Posteingang/HUBTEST_Upload_'.bin2hex(random_bytes(4)).'.pdf';

        $this->assertSame(201, $client->putCreateOnly($path, '%PDF-1.4 mock', 'application/pdf')->status());
        $this->assertSame(412, $client->putCreateOnly($path, '%PDF-1.4 mock', 'application/pdf')->status(), 'Zweites PUT auf denselben Pfad muss 412 liefern.');
        $head = $client->head($path);
        $this->assertSame(207, $head['status']);
        $this->assertNotNull($head['entry'], 'Hochgeladene Datei muss per PROPFIND sichtbar sein.');

        // PUT ohne If-None-Match sowie DELETE und MOVE werden vom Client abgebrochen, bevor sie den Server erreichen.
        $context = $this->app->make(ConnectorManager::class)->contextFor($write);

        try {
            $this->app->make(HttpClientFactory::class)->for($context, 'write')->withBody('x', 'application/pdf')->send('PUT', '/Posteingang/HUBTEST_ohne_header.pdf');
            $this->fail('PUT ohne If-None-Match wurde gesendet.');
        } catch (WriteBlockedException $e) {
            $this->assertSame('PUT', $e->operation);
        }

        try {
            $client->delete($path);
        } catch (WriteBlockedException $e) {
            $this->assertSame('DELETE', $e->operation);
        }

        try {
            $client->move($path, '/Posteingang/x.pdf');
        } catch (WriteBlockedException $e) {
            $this->assertSame('MOVE', $e->operation);
        }

        $this->assertSame([], $this->server()->violations(), 'Der Mock darf keinen Verstoß protokolliert haben.');
        $puts = array_filter($this->server()->log(), static fn (array $entry): bool => $entry['method'] === 'PUT');
        $this->assertCount(2, $puts);

        foreach ($puts as $put) {
            $this->assertSame('*', $put['if_none_match']);
        }
    }

    public function test_conflict_bei_put_in_fehlenden_zielordner(): void
    {
        $write = $this->webdavConnection('conflict', [
            'purpose' => 'write',
            'write_enabled' => true,
            'allowed_write_prefix' => '/Posteingang/',
        ]);
        $client = $this->app->make(WebDavClientFactory::class)->forConnection($write);

        $this->assertSame(409, $client->putCreateOnly('/Posteingang/HUBTEST_conflict.pdf', 'x', 'application/pdf')->status());
        $this->assertSame(CircuitState::Closed, $this->breaker()->state(CircuitBreaker::keyFor((int) $write->getKey(), 'write')));
    }

    // ----------------------------------------------------------- CardDAV

    public function test_ok_carddav_spiegelt_drei_kontakte(): void
    {
        $connection = $this->carddavConnection('ok');
        $connector = $this->app->make(CardDavConnector::class);

        $this->assertTrue($connector->forConnection((int) $connection->getKey())->testConnection()->ok);

        $result = $connector->pull(new SyncRequest((int) $connection->getKey(), CardDavConnector::ENTITY_TYPE, SyncMode::Incremental));

        $this->assertSame(3, $result->processed, json_encode($result->errors));
        $this->assertSame(3, $result->created);
        $this->assertSame(3, Contact::query()->count());
        $this->assertSame([], $this->server()->violations());

        $methods = array_unique(array_column($this->server()->log(), 'method'));
        sort($methods);
        $this->assertSame(['PROPFIND', 'REPORT'], $methods);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function carddavFehlerszenarien(): array
    {
        return [
            'unauthorized' => ['unauthorized', '401'],
            'forbidden' => ['forbidden', '403'],
            'notfound' => ['notfound', '404'],
            'conflict' => ['conflict', 'unerwarteter Status 409'],
            'ratelimited' => ['ratelimited', 'unerwarteter Status 429'],
            'servererror' => ['servererror', 'unerwarteter Status 500'],
            'invalidxml' => ['invalidxml', 'ohne gültiges Multistatus-XML'],
            'timeout' => ['timeout', 'Verbindungsfehler'],
        ];
    }

    #[DataProvider('carddavFehlerszenarien')]
    public function test_carddav_fehlerszenario_erzeugt_connector_exception(string $scenario, string $messagePart): void
    {
        $connection = $this->carddavConnection($scenario);

        try {
            $this->app->make(CardDavConnector::class)->pull(new SyncRequest((int) $connection->getKey(), CardDavConnector::ENTITY_TYPE, SyncMode::Incremental));
            $this->fail('Szenario '.$scenario.' hat keine ConnectorException ausgelöst.');
        } catch (ConnectorException $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage(), $scenario);
        }

        $this->assertSame(0, Contact::query()->count(), 'Im Fehlerfall darf nichts gespiegelt oder gesweept werden.');
        $this->assertSame([], $this->server()->violations());
    }

    // ------------------------------------------------------------ Helfer

    private function server(): MockServerProcess
    {
        if (self::$server === null) {
            $this->fail('Mock-Server läuft nicht.');
        }

        return self::$server;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function webdavConnection(string $scenario, array $attributes = []): ImmowareConnection
    {
        $url = $this->server()->scenarioBaseUrl($scenario).'/files';

        return $this->createConnection(null, [
            'connector_type' => 'webdav_documents',
            'base_url' => $url,
            'base_url_hash' => hash('sha256', $url),
            'credentials' => ['username' => MockServerProcess::USER, 'password' => MockServerProcess::PASSWORD],
            'auth_scheme' => 'basic',
            'status' => 'active',
            'rate_limit_rps' => 50,
            ...$attributes,
        ]);
    }

    private function carddavConnection(string $scenario): ImmowareConnection
    {
        $url = $this->server()->scenarioBaseUrl($scenario).'/addressbooks/kontakte/';

        return $this->createConnection(null, [
            'connector_type' => 'carddav_contacts',
            'base_url' => $url,
            'base_url_hash' => hash('sha256', $url),
            'credentials' => ['username' => MockServerProcess::USER, 'password' => MockServerProcess::PASSWORD],
            'status' => 'active',
        ]);
    }

    private function webdav(ImmowareConnection $connection): WebDavConnector
    {
        $connector = $this->app->make(ConnectorManager::class)->resolve($connection);
        $this->assertInstanceOf(WebDavConnector::class, $connector);

        return $connector;
    }

    private function breaker(): CircuitBreaker
    {
        return $this->app->make(CircuitBreaker::class);
    }

    private function rateLimiter(): RateLimitManager
    {
        return $this->app->make(RateLimitManager::class);
    }
}
