<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Services\DlqService;
use Illuminate\Console\Command;
use Throwable;

/**
 * hub:dlq:ignore {id} --reason=: markiert einen Eintrag als ignoriert (Audit dlq.ignored). Begründung ist Pflicht.
 */
final class DlqIgnoreCommand extends Command
{
    protected $signature = 'hub:dlq:ignore {id : ID des DLQ-Eintrags} {--reason= : Begründung (Pflicht, z. B. Ticketnummer)}';

    protected $description = 'Markiert einen DLQ-Eintrag als ignoriert; ignorierte Einträge können nicht erneut ausgeführt werden.';

    public function handle(DlqService $dlq): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('--reason ist Pflicht (Begründung oder Ticketnummer).');

            return self::FAILURE;
        }

        try {
            $item = $dlq->ignore((int) $this->argument('id'), null, $reason);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('#%d ignoriert (%s).', (int) $item->getKey(), $reason));

        return self::SUCCESS;
    }
}
