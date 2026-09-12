<?php

declare(strict_types=1);

namespace App\Modules\Imports\Console;

use App\Core\Support\GermanDate;
use App\Modules\Imports\Services\ExportScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Listet überfällige manuelle Exporte (export_schedules) mit verantwortlicher Person.
 * Der Versand von Erinnerungen erfolgt nicht automatisch, der Befehl protokolliert und markiert nur.
 */
final class RemindExportsCommand extends Command
{
    protected $signature = 'hub:imports:remind {--mark : reminder_sent_at setzen}';

    protected $description = 'Listet überfällige Immoware24-Exporte gemäß export_schedules.';

    public function handle(ExportScheduleService $schedules): int
    {
        $overdue = $schedules->overdue();

        if ($overdue->isEmpty()) {
            $this->info('Keine überfälligen Exporte.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($overdue as $schedule) {
            $rows[] = [
                (int) $schedule->connection_id,
                (string) $schedule->export_type,
                (string) ($schedule->responsibleUser->name ?? 'nicht zugewiesen'),
                (int) $schedule->interval_days,
                GermanDate::format($schedule->last_import_at) ?? 'nie',
                GermanDate::format($schedule->next_due_at) ?? 'sofort',
            ];

            Log::info('Export überfällig.', ['connection_id' => $schedule->connection_id, 'export_type' => $schedule->export_type]);

            if ($this->option('mark')) {
                $schedules->markReminded($schedule);
            }
        }

        $this->table(['Verbindung', 'Exporttyp', 'Verantwortlich', 'Intervall (Tage)', 'Letzter Import', 'Fällig seit'], $rows);

        return self::SUCCESS;
    }
}
