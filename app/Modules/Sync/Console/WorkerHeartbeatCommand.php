<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Jobs\WorkerHeartbeatJob;
use App\Modules\Sync\Support\Heartbeat;
use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Minütlich aus dem Scheduler: schreibt das Scheduler-Lebenszeichen direkt (der Aufruf beweist, dass schedule:work
 * läuft) und stellt den WorkerHeartbeatJob auf die Queue high, der das Worker-Lebenszeichen schreibt.
 */
final class WorkerHeartbeatCommand extends Command
{
    protected $signature = 'hub:worker:heartbeat';

    protected $description = 'Schreibt den Scheduler-Heartbeat und stellt den Worker-Heartbeat-Job auf die Queue high.';

    public function handle(Heartbeat $heartbeat, Dispatcher $bus): int
    {
        $at = $heartbeat->beat(Heartbeat::SCHEDULER);
        $bus->dispatch(new WorkerHeartbeatJob);

        $this->line('Heartbeat scheduler '.$at->toIso8601ZuluString().', Worker-Job eingereiht.');

        return self::SUCCESS;
    }
}
