<?php

declare(strict_types=1);

namespace App\Modules\Sync\Jobs;

use App\Core\Enums\SyncMode;
use App\Modules\Sync\Enums\SyncEntity;

/**
 * Dünner Wrapper um RunSyncJob für den Dokumentenspiegel (WebDAV).
 */
final class FetchImmowareDocumentsJob extends RunSyncJob
{
    public function __construct(
        int $connectionId,
        SyncMode $mode = SyncMode::Incremental,
        ?string $cursor = null,
        ?int $runId = null,
        ?string $lockOwner = null,
        ?string $correlationId = null,
        string $triggerSource = 'schedule',
        ?int $limit = null,
        bool $singleRecord = false,
        ?int $startedBy = null,
    ) {
        parent::__construct($connectionId, SyncEntity::Document->value, $mode, $cursor, $runId, $lockOwner, $correlationId, $triggerSource, $limit, $singleRecord, $startedBy);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function fromDlqArguments(array $arguments): static
    {
        $mode = $arguments['mode'] ?? SyncMode::Incremental->value;

        return new self(
            (int) ($arguments['connectionId'] ?? 0),
            $mode instanceof SyncMode ? $mode : (SyncMode::tryFrom((string) $mode) ?? SyncMode::Incremental),
            isset($arguments['cursor']) ? (string) $arguments['cursor'] : null,
            null,
            null,
            isset($arguments['correlationId']) ? (string) $arguments['correlationId'] : null,
            'recovery',
            isset($arguments['limit']) ? (int) $arguments['limit'] : null,
            (bool) ($arguments['singleRecord'] ?? false),
        );
    }

    protected function next(?string $cursor, int $runId, ?string $lockOwner, string $correlationId): RunSyncJob
    {
        return new self($this->connectionId, $this->mode, $cursor, $runId, $lockOwner, $correlationId, $this->triggerSource, $this->limit, false, $this->startedBy);
    }
}
