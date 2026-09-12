<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Services\DataAgeService;
use Illuminate\Console\Command;

final class RefreshStalenessCommand extends Command
{
    protected $signature = 'hub:sync:stale';

    protected $description = 'Setzt stale_since auf Sync-Zuständen, deren letzter Erfolg älter als die Schwelle ist.';

    public function handle(DataAgeService $dataAge): int
    {
        $count = $dataAge->refreshStaleness();
        $this->info(sprintf('%d Zustände als veraltet markiert.', $count));

        return self::SUCCESS;
    }
}
