<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Support\ConnectorResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Sync\Support\FakeConnector;
use Tests\TestCase;

abstract class SyncTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeConnector $connector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connector = new FakeConnector;
        $this->app->instance(ConnectorResolver::bindingKey('webdav'), $this->connector);
        $this->app->instance(ConnectorResolver::bindingKey('carddav'), $this->connector);
        $this->app->instance(ConnectorResolver::bindingKey('caldav'), $this->connector);
        config()->set('hub.sync.jobs.jitter_enabled', false);
    }

    protected function activeConnection(string $connectorType = 'webdav_documents'): ImmowareConnection
    {
        return $this->createConnection(null, ['status' => 'active', 'connector_type' => $connectorType]);
    }

    /**
     * Führt den Job mit Container-Injektion synchron aus (ohne Queue-Worker).
     */
    protected function runJob(object $job): void
    {
        $this->app->call([$job, 'handle']);
    }
}
