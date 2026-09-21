<?php

declare(strict_types=1);

namespace App\Modules\Playbooks\Listeners;

use App\Modules\Cases\Events\CaseOpened;
use App\Modules\Playbooks\Jobs\MatchPlaybookJob;
use Illuminate\Contracts\Config\Repository;

final class QueuePlaybookMatch
{
    public function __construct(private readonly Repository $config) {}

    public function handle(CaseOpened $event): void
    {
        if (! (bool) $this->config->get('hub.playbooks.flags.enabled', false)) {
            return;
        }

        MatchPlaybookJob::dispatch($event->case->getKey());
    }
}
