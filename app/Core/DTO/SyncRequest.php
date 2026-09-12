<?php

declare(strict_types=1);

namespace App\Core\DTO;

use App\Core\Enums\SyncMode;
use Carbon\CarbonImmutable;

final readonly class SyncRequest
{
    public function __construct(
        public int $connectionId,
        public string $entityType,
        public SyncMode $mode,
        public ?CarbonImmutable $since = null,
        public ?string $cursor = null,
        public int $limit = 500,
    ) {}

    public function withCursor(?string $cursor): self
    {
        return new self($this->connectionId, $this->entityType, $this->mode, $this->since, $cursor, $this->limit);
    }
}
