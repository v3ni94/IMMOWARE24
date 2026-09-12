<?php

declare(strict_types=1);

namespace App\Modules\Sync\Console;

use App\Modules\Sync\Support\Heartbeat;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Container-Healthcheck (compose.yaml): Exit 0, wenn das Lebenszeichen jünger als --max-age Sekunden ist, sonst 1.
 */
final class HeartbeatCheckCommand extends Command
{
    protected $signature = 'hub:heartbeat:check {name : worker oder scheduler} {--max-age=180 : Höchstalter in Sekunden}';

    protected $description = 'Prüft das Alter des Heartbeats worker oder scheduler (Healthcheck).';

    public function handle(Heartbeat $heartbeat): int
    {
        $name = (string) $this->argument('name');
        $maxAge = max(1, (int) $this->option('max-age'));

        try {
            $age = $heartbeat->ageSeconds($name);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        if ($age === null) {
            $this->error(sprintf('Heartbeat %s: kein Lebenszeichen vorhanden.', $name));

            return self::FAILURE;
        }

        if ($age > $maxAge) {
            $this->error(sprintf('Heartbeat %s: letztes Lebenszeichen vor %d s (Grenze %d s).', $name, $age, $maxAge));

            return self::FAILURE;
        }

        $this->line(sprintf('Heartbeat %s: %d s alt.', $name, $age));

        return self::SUCCESS;
    }
}
