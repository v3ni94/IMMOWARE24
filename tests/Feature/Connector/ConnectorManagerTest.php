<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\Exceptions\ConnectorException;
use App\Modules\Connector\Connectors\RestApiSlotConnector;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Services\ConnectorManager;
use App\Modules\Connector\Support\ConnectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ConnectorManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolves_rest_api_slot_and_rejects_unregistered_adapter(): void
    {
        // Frische Instanz ohne die Adapter der Fachmodule, damit die Ablehnung eines fehlenden Adapters prüfbar bleibt.
        $manager = new ConnectorManager;
        $manager->register('rest_api_slot', static fn (ConnectorContext $context): ImmowareConnectorInterface => new RestApiSlotConnector($context));
        $this->assertTrue($manager->isRegistered('rest_api_slot'));

        $slot = $this->createConnection(null, ['connector_type' => 'rest_api_slot', 'name' => 'API-Slot']);
        $this->assertInstanceOf(RestApiSlotConnector::class, $manager->resolve($slot));

        $webdav = $this->createConnection();
        $this->assertSame(ConnectorType::WebDav, $manager->adapterNameFor($webdav));

        $this->expectException(ConnectorException::class);
        $manager->resolve($webdav);
    }

    public function test_application_manager_knows_all_adapters(): void
    {
        $manager = $this->app->make(ConnectorManager::class);

        foreach (['webdav', 'carddav', 'caldav', 'file_import', 'rest_api_slot'] as $adapter) {
            $this->assertTrue($manager->isRegistered($adapter), $adapter);
        }
    }

    public function test_register_validates_name_and_context_carries_decrypted_credentials_in_memory_only(): void
    {
        $manager = $this->app->make(ConnectorManager::class);

        $manager->register('webdav', fn (ConnectorContext $context): ImmowareConnectorInterface => new RestApiSlotConnector($context));
        $this->assertContains('webdav', $manager->registeredNames());

        $connection = $this->createConnection(null, ['credentials' => ['username' => 'hub-read', 'password' => 'p4ss']]);
        $context = $manager->contextFor($connection);

        $this->assertSame('hub-read', $context->credentials->username);
        $this->assertSame('p4ss', $context->credentials->password());
        $this->assertSame('dav.example.test', $context->host());
        $this->assertStringNotContainsString('p4ss', json_encode($context->toLogContext(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('p4ss', (string) $connection->getRawOriginal('credentials'), 'verschlüsselt in der DB');

        $fallback = $this->createConnection(null, ['credentials' => null]);
        $this->assertSame($fallback->technicalUser?->getAttribute('username'), $manager->contextFor($fallback)->credentials->username);
        $this->assertSame('test-secret', $manager->contextFor($fallback)->credentials->password());

        $this->expectException(ConnectorException::class);
        $manager->register('graphql', fn (ConnectorContext $c): ImmowareConnectorInterface => new RestApiSlotConnector($c));
    }
}
