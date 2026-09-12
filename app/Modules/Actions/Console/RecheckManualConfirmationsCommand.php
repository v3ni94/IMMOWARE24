<?php

declare(strict_types=1);

namespace App\Modules\Actions\Console;

use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\ManualTaskService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Manuell bestätigte Ausführungen nach einem Spiegellauf nachlesen: zeigt der Spiegel den Zielwert, wird die
 * Bestätigung auf api_verified und die Aufgabe auf done_verified gehoben (ManualTaskService::recheck). Ohne Wert-Match
 * ändert sich nichts, es entsteht kein Beleg. Zeitplan in routes/console.php.
 */
final class RecheckManualConfirmationsCommand extends Command
{
    protected $signature = 'mail:actions:recheck-manual {--limit=200 : Höchstzahl je Lauf}';

    protected $description = 'Manuell bestätigte Aktionen gegen den Spiegel nachlesen und bei Wert-Match auf api_verified heben.';

    public function handle(ManualTaskService $tasks): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $checked = 0;
        $upgraded = 0;
        $errors = 0;

        Execution::query()
            ->where('status', ExecutionService::STATUS_VERIFIED)
            ->where('verification_status', VerificationStatus::ManuallyConfirmed->value)
            ->whereNotNull('task_id')
            ->lazyById(100)
            ->each(function (mixed $execution) use (&$checked, &$upgraded, &$errors, $limit, $tasks): bool {
                if ($checked >= $limit) {
                    return false;
                }

                if (! $execution instanceof Execution) {
                    return true;
                }

                $checked++;

                try {
                    if ($tasks->recheck($execution) !== null) {
                        $upgraded++;
                    }
                } catch (Throwable $e) {
                    $errors++;
                    Log::warning('Nachlesen einer manuell bestätigten Ausführung fehlgeschlagen.', ['execution_id' => $execution->getKey(), 'error' => $e::class]);
                }

                return true;
            });

        $this->info(sprintf('Geprüft: %d, auf api_verified gehoben: %d, Fehler: %d.', $checked, $upgraded, $errors));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
