<?php

declare(strict_types=1);

namespace App\Modules\Connector\Services;

use App\Core\Contracts\CapabilityRegistryInterface;
use App\Core\Enums\CapabilityStatus;
use App\Modules\Connector\Enums\CapabilityKey;
use App\Modules\Connector\Models\Capability;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Liest die Tabelle capabilities je Connection. Eine Fähigkeit gilt nur dann als verfügbar, wenn
 * ihr Status verified oder tested ist UND das zugehörige Config-Flag sie freigibt UND sie nicht
 * hard_locked ist. hard_locked Fähigkeiten (documents.delete, documents.move, contacts.write,
 * calendar.write) können nie true werden, unabhängig von Datenbank und Konfiguration.
 */
final class CapabilityRegistry implements CapabilityRegistryInterface
{
    /** @var array<int, string> */
    public const array ACTIVATABLE_STATUSES = ['verified', 'tested'];

    private ?int $connectionId = null;

    /** @var array<string, Capability> */
    private array $loaded = [];

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * Setzt den Connection-Kontext und lädt deren Capabilities neu.
     */
    public function refresh(int $connectionId): void
    {
        $this->connectionId = $connectionId;
        $this->loaded = [];

        $rows = Capability::query()
            ->where('connection_id', $connectionId)
            ->whereIn('capability_key', $this->keys())
            ->get();

        foreach ($rows->all() as $row) {
            if ($row instanceof Capability) {
                $this->loaded[(string) $row->getAttribute('capability_key')] = $row;
            }
        }
    }

    public function has(string $capability): bool
    {
        if ($this->connectionId === null) {
            return false;
        }

        return $this->evaluate($capability, $this->loaded[$capability] ?? null);
    }

    public function hasFor(int $connectionId, string $capability): bool
    {
        if ($this->connectionId !== $connectionId) {
            $this->refresh($connectionId);
        }

        return $this->has($capability);
    }

    /**
     * @return array<string, array{available: bool, status: string|null, enabled: bool, hard_locked: bool, config_allowed: bool, tested_at: string|null}>
     */
    public function all(): array
    {
        $result = [];

        foreach ($this->keys() as $key) {
            $row = $this->loaded[$key] ?? null;
            $testedAt = $row?->getAttribute('tested_at');

            $result[$key] = [
                'available' => $this->connectionId !== null && $this->evaluate($key, $row),
                'status' => $row !== null ? $this->statusValue($row) : null,
                'enabled' => $row !== null && (bool) $row->getAttribute('enabled'),
                'hard_locked' => $this->isHardLocked($key),
                'config_allowed' => $this->configAllows($key),
                'tested_at' => $testedAt instanceof \DateTimeInterface ? $testedAt->format(DATE_ATOM) : null,
            ];
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        $configured = array_keys((array) $this->config->get('hub.connector.capabilities', []));

        return $configured !== [] ? array_map('strval', $configured) : CapabilityKey::keys();
    }

    public function isHardLocked(string $capability): bool
    {
        return (bool) ($this->definition($capability)['hard_locked'] ?? false);
    }

    /**
     * Capability-Schlüssel enthalten Punkte, deshalb kein Zugriff über Config-Dot-Notation.
     *
     * @return array<string, mixed>
     */
    private function definition(string $capability): array
    {
        $definitions = (array) $this->config->get('hub.connector.capabilities', []);
        $definition = $definitions[$capability] ?? [];

        return is_array($definition) ? $definition : [];
    }

    /**
     * Prüft das oder die Config-Flags der Fähigkeit. Ohne Flag (null) ist die Fähigkeit gesperrt.
     */
    public function configAllows(string $capability): bool
    {
        if ($this->isHardLocked($capability)) {
            return false;
        }

        $flags = $this->definition($capability)['config_flag'] ?? null;

        if ($flags === null) {
            return false;
        }

        foreach ((array) $flags as $flag) {
            if (! $this->truthy($this->config->get((string) $flag, false))) {
                return false;
            }
        }

        return true;
    }

    public function connectorFor(string $capability): ?string
    {
        $connector = $this->definition($capability)['connector'] ?? null;

        return is_string($connector) ? $connector : null;
    }

    /**
     * Schreibt ein Testergebnis (Probe). hard_locked Fähigkeiten werden immer als unavailable,
     * enabled = false, hard_locked = true persistiert.
     */
    public function recordTestResult(
        int $connectionId,
        string $capability,
        CapabilityStatus $status,
        ?string $protocol = null,
        ?int $testedBy = null,
    ): Capability {
        $hardLocked = $this->isHardLocked($capability);

        if ($hardLocked) {
            $status = CapabilityStatus::Unavailable;
        }

        $enabled = ! $hardLocked && $status->allowsActivation() && in_array($status->value, self::ACTIVATABLE_STATUSES, true);

        $row = Capability::query()->updateOrCreate(
            ['connection_id' => $connectionId, 'capability_key' => $capability],
            [
                'evidence_status' => $status,
                'enabled' => $enabled,
                'hard_locked' => $hardLocked,
                'tested_at' => CarbonImmutable::now(),
                'tested_by' => $testedBy,
                'test_protocol' => $protocol !== null ? substr($protocol, 0, 60000) : null,
            ],
        );

        if ($this->connectionId === $connectionId) {
            $this->loaded[$capability] = $row;
        }

        return $row;
    }

    /**
     * Legt fehlende hard_locked Zeilen einer Connection an, damit die Sperre in der Tabelle sichtbar ist.
     */
    public function ensureHardLocks(int $connectionId): void
    {
        foreach ($this->keys() as $key) {
            if (! $this->isHardLocked($key)) {
                continue;
            }

            Capability::query()->updateOrCreate(
                ['connection_id' => $connectionId, 'capability_key' => $key],
                ['evidence_status' => CapabilityStatus::Unavailable, 'enabled' => false, 'hard_locked' => true],
            );
        }

        if ($this->connectionId === $connectionId) {
            $this->refresh($connectionId);
        }
    }

    private function evaluate(string $capability, ?Capability $row): bool
    {
        if ($row === null || $this->isHardLocked($capability) || (bool) $row->getAttribute('hard_locked')) {
            return false;
        }

        if (! in_array($this->statusValue($row), self::ACTIVATABLE_STATUSES, true)) {
            return false;
        }

        return $this->configAllows($capability);
    }

    private function statusValue(Capability $row): ?string
    {
        $status = $row->getAttribute('evidence_status');

        return $status instanceof CapabilityStatus ? $status->value : (is_string($status) ? $status : null);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $value === 1;
    }
}
