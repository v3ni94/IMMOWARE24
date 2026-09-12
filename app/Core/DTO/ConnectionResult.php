<?php

declare(strict_types=1);

namespace App\Core\DTO;

use App\Core\Enums\CheckStatus;

final readonly class ConnectionResult
{
    /**
     * @param  array<string, CheckResult>  $checks
     */
    public function __construct(
        public bool $ok,
        public array $checks = [],
    ) {}

    /**
     * Leitet ok aus den Einzelprüfungen ab: nur failed und unknown gelten als Fehler.
     *
     * @param  array<string, CheckResult>  $checks
     */
    public static function fromChecks(array $checks): self
    {
        foreach ($checks as $check) {
            if (in_array($check->status, [CheckStatus::Failed, CheckStatus::Unknown], true)) {
                return new self(false, $checks);
            }
        }

        return new self(true, $checks);
    }

    /**
     * @return array{ok: bool, checks: array<string, array{status: string, message: string, latency_ms: int|null}>}
     */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'checks' => array_map(static fn (CheckResult $c): array => $c->toArray(), $this->checks),
        ];
    }
}
