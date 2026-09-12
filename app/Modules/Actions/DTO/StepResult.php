<?php

declare(strict_types=1);

namespace App\Modules\Actions\DTO;

/**
 * Ergebnis eines Adapteraufrufs für einen Planschritt. "executed" ist ein technischer Zustand, kein Geschäftsergebnis.
 */
final readonly class StepResult
{
    public const string EXECUTED = 'executed';

    public const string MANUAL_TASK = 'manual_task';

    public const string FAILED = 'failed';

    public const string RESULT_UNCLEAR = 'result_unclear';

    /**
     * @param  array<string, mixed>  $resultMasked  maskiertes Ergebnis (nie Klartext-IBAN, nie Secrets)
     * @param  array<string, mixed>  $requestSummary
     */
    public function __construct(
        public string $status,
        public ?int $responseStatus = null,
        public array $resultMasked = [],
        public array $requestSummary = [],
        public ?int $taskId = null,
        public ?int $proposedChangeId = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $resultMasked
     * @param  array<string, mixed>  $requestSummary
     */
    public static function executed(?int $status, array $resultMasked = [], array $requestSummary = []): self
    {
        return new self(self::EXECUTED, $status, $resultMasked, $requestSummary);
    }

    /**
     * @param  array<string, mixed>  $resultMasked
     */
    public static function manualTask(int $taskId, ?int $proposedChangeId, array $resultMasked = []): self
    {
        return new self(self::MANUAL_TASK, null, $resultMasked, [], $taskId, $proposedChangeId);
    }

    public static function failed(string $error, ?int $status = null): self
    {
        return new self(self::FAILED, $status, [], [], null, null, $error);
    }

    public static function unclear(string $error): self
    {
        return new self(self::RESULT_UNCLEAR, null, [], [], null, null, $error);
    }
}
