<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Models\DlqItem;
use Illuminate\Console\Command;

/**
 * hub:dlq:list: Einträge der Dead Letter Queue, Standard nur offene und fehlgeschlagene.
 */
final class DlqListCommand extends Command
{
    protected $signature = 'hub:dlq:list
        {--status=* : Status (open, retrying, replayed, ignored, failed), Standard open und failed}
        {--connection= : Nur Einträge dieser Connection-ID}
        {--limit=50 : Höchstzahl Zeilen}
        {--json : Ausgabe als JSON}';

    protected $description = 'Listet Einträge der Dead Letter Queue (dlq_items).';

    public function handle(): int
    {
        $statuses = $this->statuses();

        if ($statuses === null) {
            return self::FAILURE;
        }

        $limit = max(1, min(1000, (int) $this->option('limit')));
        $connection = $this->option('connection');

        $query = DlqItem::query()
            ->whereIn('status', array_map(static fn (DlqStatus $s): string => $s->value, $statuses))
            ->when($connection !== null && $connection !== '', static fn ($q) => $q->where('connection_id', (int) $connection))
            ->orderBy('id');

        $total = $query->count();
        $rows = [];

        foreach ($query->limit($limit)->get() as $item) {
            /** @var DlqItem $item */
            $failedAt = $item->getAttribute('failed_at');
            $exception = (string) $item->getAttribute('exception');
            $rows[] = [
                'id' => (int) $item->getKey(),
                'status' => $item->getAttribute('status')->value,
                'job' => class_basename((string) $item->getAttribute('job_class')),
                'connection_id' => $item->getAttribute('connection_id') !== null ? (int) $item->getAttribute('connection_id') : null,
                'entity_type' => $item->getAttribute('entity_type'),
                'queue' => $item->getAttribute('queue'),
                'attempts' => (int) $item->getAttribute('attempts'),
                'failed_at' => $failedAt instanceof \DateTimeInterface ? $failedAt->format('d.m.Y H:i:s') : null,
                'correlation_id' => $item->getAttribute('correlation_id'),
                'error' => mb_substr(strtok($exception, "\n") ?: '', 0, 120),
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['data' => $rows, 'meta' => ['total' => $total, 'limit' => $limit]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($rows === []) {
            $this->info('Keine DLQ-Einträge mit Status '.implode(', ', array_map(static fn (DlqStatus $s): string => $s->value, $statuses)).'.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Status', 'Job', 'Connection', 'Entität', 'Queue', 'Versuche', 'Fehlgeschlagen am', 'Correlation-ID', 'Fehler'],
            array_map(static fn (array $r): array => array_map(static fn (mixed $v): string => (string) ($v ?? ''), array_values($r)), $rows),
        );
        $this->info(sprintf('%d von %d Einträgen angezeigt.', count($rows), $total));

        return self::SUCCESS;
    }

    /**
     * @return array<int, DlqStatus>|null
     */
    private function statuses(): ?array
    {
        $given = array_filter(array_map('strval', (array) $this->option('status')), static fn (string $s): bool => $s !== '');

        if ($given === []) {
            return [DlqStatus::Open, DlqStatus::Failed];
        }

        $result = [];

        foreach ($given as $value) {
            $status = DlqStatus::tryFrom($value);

            if ($status === null) {
                $this->error(sprintf('Unbekannter Status "%s". Erlaubt: %s.', $value, implode(', ', array_map(static fn (DlqStatus $s): string => $s->value, DlqStatus::cases()))));

                return null;
            }

            $result[] = $status;
        }

        return $result;
    }
}
