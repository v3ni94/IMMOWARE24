<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Services\DlqService;
use Illuminate\Console\Command;
use Throwable;

/**
 * hub:dlq:retry {id} oder --all-failed: plant die Wiederaufnahme über DlqService::retry (Queue-Job
 * ProcessDlqRetryJob, Audit dlq.retry_requested). --all-failed erfasst Einträge mit Status open und failed.
 * Einträge der Queue write werden ohne --include-write übersprungen (Runbook Störfall 5 Punkt 6).
 */
final class DlqRetryCommand extends Command
{
    protected $signature = 'hub:dlq:retry
        {id? : ID des DLQ-Eintrags}
        {--all-failed : Alle Einträge mit Status open oder failed erneut einplanen}
        {--connection= : Mit --all-failed nur Einträge dieser Connection-ID}
        {--include-write : Auch Einträge der Queue write einplanen (nur nach Prüfung der write_operation)}
        {--dry-run : Nur anzeigen, was eingeplant würde}';

    protected $description = 'Plant DLQ-Einträge zur Wiederaufnahme ein (einzeln oder alle offenen und fehlgeschlagenen).';

    public function handle(DlqService $dlq): int
    {
        $id = $this->argument('id');
        $all = (bool) $this->option('all-failed');

        if (($id === null) === ! $all) {
            $this->error('Entweder eine ID oder --all-failed angeben.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $includeWrite = (bool) $this->option('include-write');
        $writeQueue = (string) config('hub.core.queues.write', 'write');
        $connection = $this->option('connection');

        $query = DlqItem::query();

        if ($id !== null) {
            $query->whereKey((int) $id);
        } else {
            $query->whereIn('status', [DlqStatus::Open->value, DlqStatus::Failed->value]);

            if ($connection !== null && $connection !== '') {
                $query->where('connection_id', (int) $connection);
            }
        }

        $planned = $skippedWrite = $failed = 0;

        $query->lazyById(100)->each(function (DlqItem $item) use ($dlq, $dryRun, $includeWrite, $writeQueue, &$planned, &$skippedWrite, &$failed): void {
            $itemId = (int) $item->getKey();

            if ($item->getAttribute('queue') === $writeQueue && ! $includeWrite) {
                $skippedWrite++;
                $this->warn(sprintf('#%d übersprungen: Queue write, zuerst write_operation prüfen, dann --include-write.', $itemId));

                return;
            }

            if ($dryRun) {
                $planned++;
                $this->line(sprintf('#%d würde eingeplant (%s, %s).', $itemId, class_basename((string) $item->getAttribute('job_class')), $item->getAttribute('status')->value));

                return;
            }

            try {
                $dlq->retry($itemId);
                $planned++;
                $this->info(sprintf('#%d eingeplant (%s).', $itemId, class_basename((string) $item->getAttribute('job_class'))));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('#%d nicht eingeplant: %s', $itemId, $e->getMessage()));
            }
        });

        if ($id !== null && $planned + $skippedWrite + $failed === 0) {
            $this->error(sprintf('DLQ-Eintrag %d existiert nicht.', (int) $id));

            return self::FAILURE;
        }

        $this->info(sprintf('%d %s, %d write übersprungen, %d fehlgeschlagen.', $planned, $dryRun ? 'würden eingeplant' : 'eingeplant', $skippedWrite, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
