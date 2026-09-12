<?php

declare(strict_types=1);

namespace App\Modules\Actions\Services;

use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use Illuminate\Contracts\Config\Repository;

/**
 * Serverseitige Allowlist der Aktionstypen je Zielsystem (config hub.actions.action_types). Alles außerhalb wird
 * im Service abgewiesen (blocked_capability), unabhängig vom aufrufenden Controller.
 */
final class ActionAllowlist
{
    /** @var array<int, string> */
    public const array TYPES = ['address_change', 'bank_change', 'note', 'manual_task'];

    public function __construct(private readonly Repository $config) {}

    public function isAllowed(TargetSystem $system, string $type): bool
    {
        return $this->definition($system, $type) !== null;
    }

    /**
     * @return array{risk_class: string, mode: string, flag: ?string, fields: array<int, string>}|null
     */
    public function definition(TargetSystem $system, string $type): ?array
    {
        $types = (array) $this->config->get('hub.actions.action_types.'.$system->value, []);
        $definition = $types[$type] ?? null;

        if (! is_array($definition) || ! in_array($type, self::TYPES, true)) {
            return null;
        }

        return [
            'risk_class' => (string) ($definition['risk_class'] ?? 'medium'),
            'mode' => (string) ($definition['mode'] ?? 'manual_task'),
            'flag' => isset($definition['flag']) ? (string) $definition['flag'] : null,
            'fields' => array_values(array_map('strval', (array) ($definition['fields'] ?? []))),
        ];
    }

    /**
     * @return array{risk_class: string, mode: string, flag: ?string, fields: array<int, string>}
     *
     * @throws ActionPolicyException
     */
    public function require(TargetSystem $system, string $type): array
    {
        $definition = $this->definition($system, $type);

        if ($definition === null) {
            throw new ActionPolicyException(
                sprintf('Aktionstyp "%s" ist für %s nicht zugelassen (Allowlist).', $type, $system->label()),
                'blocked_capability',
            );
        }

        return $definition;
    }

    public function riskClass(TargetSystem $system, string $type): RiskClass
    {
        return RiskClass::tryFrom($this->require($system, $type)['risk_class']) ?? RiskClass::Medium;
    }

    /**
     * Höchste Risikoklasse mehrerer Schritte (bank > high > medium > low).
     *
     * @param  array<int, RiskClass>  $classes
     */
    public function highest(array $classes): RiskClass
    {
        $order = [RiskClass::Low->value => 0, RiskClass::Medium->value => 1, RiskClass::High->value => 2, RiskClass::Bank->value => 3];
        $result = RiskClass::Low;

        foreach ($classes as $class) {
            if ($order[$class->value] > $order[$result->value]) {
                $result = $class;
            }
        }

        return $result;
    }
}
