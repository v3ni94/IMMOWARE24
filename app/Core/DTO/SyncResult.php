<?php

declare(strict_types=1);

namespace App\Core\DTO;

final readonly class SyncResult
{
    /**
     * @param  array<int, array<string, mixed>>  $errors
     */
    public function __construct(
        public int $processed = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $deleted = 0,
        public int $failed = 0,
        public ?string $cursor = null,
        public array $errors = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function merge(self $other): self
    {
        return new self(
            $this->processed + $other->processed,
            $this->created + $other->created,
            $this->updated + $other->updated,
            $this->deleted + $other->deleted,
            $this->failed + $other->failed,
            $other->cursor ?? $this->cursor,
            [...$this->errors, ...$other->errors],
        );
    }

    public function hasErrors(): bool
    {
        return $this->failed > 0 || $this->errors !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'created' => $this->created,
            'updated' => $this->updated,
            'deleted' => $this->deleted,
            'failed' => $this->failed,
            'cursor' => $this->cursor,
            'errors' => $this->errors,
        ];
    }
}
