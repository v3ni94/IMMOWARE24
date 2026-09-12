<?php

declare(strict_types=1);

namespace App\Modules\Connector\Probe;

use App\Core\DTO\CheckResult;
use App\Core\DTO\ConnectionResult;
use App\Core\Enums\CheckStatus;
use App\Modules\Connector\Enums\SyncStrategy;

final readonly class ProbeReport
{
    /**
     * @param  array<string, mixed>  $facts  Gemessene Serverfakten ohne Secrets
     */
    public function __construct(
        public int $connectionId,
        public string $connector,
        public ConnectionResult $result,
        public array $facts,
        public ?SyncStrategy $strategy,
        public ?string $serverFingerprint,
        public bool $fingerprintChanged,
    ) {}

    public static function symbol(CheckStatus $status): string
    {
        return match ($status) {
            CheckStatus::Ok => '✓',
            CheckStatus::Failed => '✗',
            CheckStatus::Disabled, CheckStatus::Skipped => '⚠',
            CheckStatus::Unknown => '?',
        };
    }

    /**
     * @return array<int, string> Zeilen für die Konsolenausgabe
     */
    public function lines(): array
    {
        $lines = [];

        foreach ($this->result->checks as $name => $check) {
            $lines[] = sprintf(
                '%s %-28s %s%s',
                self::symbol($check->status),
                ProbeService::label($name),
                $check->message,
                $check->latencyMs !== null ? sprintf(' (%d ms)', $check->latencyMs) : '',
            );
        }

        return $lines;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'connection_id' => $this->connectionId,
            'connector' => $this->connector,
            'ok' => $this->result->ok,
            'checks' => array_map(static fn (CheckResult $c): array => $c->toArray(), $this->result->checks),
            'facts' => $this->facts,
            'strategy' => $this->strategy?->value,
            'server_fingerprint' => $this->serverFingerprint,
            'fingerprint_changed' => $this->fingerprintChanged,
        ];
    }
}
