<?php

declare(strict_types=1);

namespace App\Modules\Sync\Support;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Modules\Connector\Enums\ConnectorType;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Services\ConnectorManager;
use Illuminate\Contracts\Container\Container;

/**
 * Löst den Adapter einer Connection auf. Vorrang hat eine explizite Container-Bindung
 * ImmowareConnectorInterface::class.':'.<adapter> (z. B. Fake-Adapter in Tests), danach der ConnectorManager.
 */
final class ConnectorResolver
{
    public function __construct(
        private readonly Container $container,
        private readonly ConnectorManager $manager,
    ) {}

    public static function bindingKey(string $adapter): string
    {
        return ImmowareConnectorInterface::class.':'.strtolower($adapter);
    }

    public function resolve(ImmowareConnection $connection): ImmowareConnectorInterface
    {
        $adapter = ConnectorType::fromConnectorType((string) $connection->getAttribute('connector_type'))->value;
        $key = self::bindingKey($adapter);

        if ($this->container->bound($key)) {
            /** @var ImmowareConnectorInterface $connector */
            $connector = $this->container->make($key);

            return $connector;
        }

        return $this->manager->resolve($connection);
    }
}
