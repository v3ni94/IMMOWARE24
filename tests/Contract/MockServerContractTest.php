<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * Startet den Mock-Server (tests/mock-immoware/server.php) per Process auf einem freien Port und
 * führt die Contract-Tests dagegen aus. Der Fingerprint liegt unter snapshots/mock.json und
 * dokumentiert die im Mock getroffenen Annahmen; ändert sich der Mock, wird der Test rot.
 */
final class MockServerContractTest extends DavContractTestCase
{
    private static ?MockServerProcess $server = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$server = new MockServerProcess;
        self::$server->start();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
        self::$server = null;

        parent::tearDownAfterClass();
    }

    protected function baseUrl(): ?string
    {
        return self::$server?->davBaseUrl();
    }

    protected function snapshotName(): string
    {
        return 'mock';
    }

    protected function username(): string
    {
        return MockServerProcess::USER;
    }

    protected function password(): string
    {
        return MockServerProcess::PASSWORD;
    }

    protected function filesPath(): string
    {
        return '/files/Posteingang/';
    }

    protected function addressbookPath(): string
    {
        return '/addressbooks/kontakte/';
    }

    protected function calendarPath(): string
    {
        return '/calendars/termine/';
    }

    public function test_mock_protokolliert_nur_lesende_methoden(): void
    {
        $this->fingerprint();

        $this->assertSame([], self::$server?->violations() ?? ['server fehlt'], 'Contract-Tests dürfen keine schreibenden Methoden senden.');
    }
}
