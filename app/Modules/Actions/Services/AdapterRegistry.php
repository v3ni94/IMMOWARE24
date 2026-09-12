<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Modules\Actions\Contracts\StepAwareAdapterInterface;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use Illuminate\Contracts\Container\Container;

/**
 * Zuordnung Zielsystem zu Adapterklasse. Zielsysteme ohne registrierten Adapter (Gmail, Drive in diesem Abschnitt)
 * sind nicht ausführbar und werden im Service abgewiesen.
 */
final class AdapterRegistry
{
    /** @var array<string, class-string<StepAwareAdapterInterface>> */
    private array $adapters = [];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  class-string<StepAwareAdapterInterface>  $class
     */
    public function register(TargetSystem $system, string $class): void
    {
        $this->adapters[$system->value] = $class;
    }

    public function has(TargetSystem $system): bool
    {
        return isset($this->adapters[$system->value]);
    }

    public function for(TargetSystem $system): StepAwareAdapterInterface
    {
        $class = $this->adapters[$system->value] ?? null;

        if ($class === null) {
            throw new ActionPolicyException(sprintf('Für %s ist kein Adapter registriert.', $system->label()), 'adapter_missing');
        }

        /** @var StepAwareAdapterInterface $adapter */
        $adapter = $this->container->make($class);

        return $adapter;
    }

    /**
     * @return array<int, TargetSystem>
     */
    public function systems(): array
    {
        return array_values(array_filter(array_map(static fn (string $v): ?TargetSystem => TargetSystem::tryFrom($v), array_keys($this->adapters))));
    }
}
