<?php

declare(strict_types=1);

namespace App\Core\DTO;

use App\Core\Enums\CheckStatus;

final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $message = '',
        public ?int $latencyMs = null,
    ) {}

    public static function ok(string $message = '', ?int $latencyMs = null): self
    {
        return new self(CheckStatus::Ok, $message, $latencyMs);
    }

    public static function failed(string $message, ?int $latencyMs = null): self
    {
        return new self(CheckStatus::Failed, $message, $latencyMs);
    }

    public static function disabled(string $message = ''): self
    {
        return new self(CheckStatus::Disabled, $message);
    }

    public static function skipped(string $message = ''): self
    {
        return new self(CheckStatus::Skipped, $message);
    }

    public static function unknown(string $message = ''): self
    {
        return new self(CheckStatus::Unknown, $message);
    }

    public function isOk(): bool
    {
        return $this->status === CheckStatus::Ok;
    }

    /**
     * @return array{status: string, message: string, latency_ms: int|null}
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'message' => $this->message,
            'latency_ms' => $this->latencyMs,
        ];
    }
}
