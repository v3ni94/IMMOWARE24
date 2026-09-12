<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Core\Support\SecretMasker;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Jobs\ProcessDlqRetryJob;
use App\Modules\Sync\Models\DlqItem;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Dead Letter Queue: Ablage erschöpfter Jobs mit maskiertem Payload, Fehler und Stacktrace sowie
 * Wiederaufnahme (retry), Ignorieren (ignore) und Payload-Einsicht (payload).
 */
final class DlqService
{
    public function __construct(
        private readonly SecretMasker $masker,
        private readonly CorrelationId $correlationId,
        private readonly Dispatcher $bus,
        private readonly AuditLoggerInterface $audit,
        private readonly SyncMetrics $metrics,
    ) {}

    /**
     * Legt einen Eintrag für einen endgültig gescheiterten Job an.
     *
     * @param  array<string, mixed>  $arguments  Konstruktorargumente (benannt), um den Job neu zu bauen
     */
    public function store(string $jobClass, array $arguments, Throwable $exception, ?int $connectionId = null, ?string $entityType = null, ?string $queue = null): DlqItem
    {
        $item = new DlqItem;
        $item->forceFill([
            'job_class' => $jobClass,
            'connection_id' => $connectionId,
            'entity_type' => $entityType,
            'queue' => $queue,
            'correlation_id' => $this->correlationId->current(),
            'payload_json' => [
                'job' => $jobClass,
                'arguments' => $this->masker->maskArray($arguments),
            ],
            'exception' => $this->describe($exception),
            'failed_at' => CarbonImmutable::now(),
            'status' => DlqStatus::Open,
            'attempts' => 0,
        ]);
        $item->save();

        $this->metrics->increment(SyncMetrics::FAILED_JOBS, 1, ['job' => class_basename($jobClass)]);

        return $item;
    }

    /**
     * Einzelner fehlgeschlagener Datensatz aus einem Chunk-Ergebnis.
     *
     * @param  array<string, mixed>  $error
     * @param  array<string, mixed>  $retryArguments
     */
    public function storeRecordFailure(string $jobClass, array $retryArguments, array $error, int $connectionId, string $entityType): DlqItem
    {
        $message = (string) ($error['message'] ?? 'Datensatz konnte nicht verarbeitet werden.');

        return $this->store($jobClass, $retryArguments, new RuntimeException($message), $connectionId, $entityType, (string) config('hub.sync.queue', 'sync'));
    }

    /**
     * Plant die Wiederaufnahme ein (Retry-Button).
     */
    public function retry(int $id, ?User $user = null): DlqItem
    {
        $item = $this->open($id);

        if ($item->getAttribute('status') === DlqStatus::Ignored) {
            throw new InvalidArgumentException('Ignorierte DLQ-Einträge können nicht erneut ausgeführt werden.');
        }

        $item->forceFill(['status' => DlqStatus::Retrying, 'replayed_by' => $user?->getKey()]);
        $item->save();

        $this->audit->log('dlq.retry_requested', $item, [], ['job_class' => $item->getAttribute('job_class')], AuditSource::User->value, $this->correlationId->current());

        $this->bus->dispatch(new ProcessDlqRetryJob((int) $item->getKey(), $user !== null ? (int) $user->getKey() : null, $this->correlationId->current()));

        return $item;
    }

    public function ignore(int $id, ?User $user = null, ?string $note = null): DlqItem
    {
        $item = $this->open($id);
        $item->forceFill([
            'status' => DlqStatus::Ignored,
            'replayed_at' => CarbonImmutable::now(),
            'replayed_by' => $user?->getKey(),
            'replay_result' => $note !== null ? mb_substr('ignored: '.$note, 0, 40) : 'ignored',
        ]);
        $item->save();

        $this->audit->log('dlq.ignored', $item, [], ['note' => $note], AuditSource::User->value, $this->correlationId->current());

        return $item;
    }

    /**
     * Maskierter Payload zur Einsicht.
     *
     * @return array<string, mixed>
     */
    public function payload(int $id): array
    {
        $item = $this->open($id);
        $payload = $item->getAttribute('payload_json');

        return is_array($payload) ? $this->masker->maskArray($payload) : [];
    }

    /**
     * Baut den ursprünglichen Job aus dem Payload und führt ihn synchron aus. Wird von ProcessDlqRetryJob aufgerufen.
     */
    public function replay(DlqItem $item): void
    {
        $job = $this->rebuild($item);
        $item->forceFill(['attempts' => ((int) $item->getAttribute('attempts')) + 1]);
        $item->save();

        try {
            $this->bus->dispatchNow($job);
        } catch (Throwable $exception) {
            $item->forceFill([
                'status' => DlqStatus::Failed,
                'replayed_at' => CarbonImmutable::now(),
                'replay_result' => 'failed',
                'exception' => $this->describe($exception),
            ]);
            $item->save();

            throw $exception;
        }

        $item->forceFill([
            'status' => DlqStatus::Replayed,
            'replayed_at' => CarbonImmutable::now(),
            'replay_result' => 'succeeded',
        ]);
        $item->save();
    }

    public function rebuild(DlqItem $item): ShouldQueue
    {
        $payload = (array) $item->getAttribute('payload_json');
        $class = (string) ($payload['job'] ?? $item->getAttribute('job_class'));
        $arguments = (array) ($payload['arguments'] ?? []);

        if (! class_exists($class) || ! is_subclass_of($class, ShouldQueue::class)) {
            throw new InvalidArgumentException(sprintf('Job-Klasse "%s" ist nicht wiederherstellbar.', $class));
        }

        if (method_exists($class, 'fromDlqArguments')) {
            /** @var ShouldQueue $job */
            $job = $class::fromDlqArguments($arguments);

            return $job;
        }

        /** @var ShouldQueue $job */
        $job = new $class(...$arguments);

        return $job;
    }

    public function describe(Throwable $exception): string
    {
        $max = (int) config('hub.sync.dlq.trace_max_chars', 8000);
        $text = sprintf("%s: %s\n%s", $exception::class, $exception->getMessage(), $exception->getTraceAsString());

        return mb_substr($this->masker->maskString($text), 0, $max);
    }

    private function open(int $id): DlqItem
    {
        /** @var DlqItem|null $item */
        $item = DlqItem::query()->find($id);

        if ($item === null) {
            throw new InvalidArgumentException(sprintf('DLQ-Eintrag %d existiert nicht.', $id));
        }

        return $item;
    }
}
