<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\Support\CorrelationId;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Tests\TestCase;

/**
 * Reproduziert das Finding "Correlation-ID klebt im Worker": ohne Reset trägt jeder Folgejob die ID des ersten Jobs.
 */
final class QueueCorrelationResetTest extends TestCase
{
    public function test_worker_resets_correlation_id_before_each_job_and_clears_it_afterwards(): void
    {
        $correlation = $this->app->make(CorrelationId::class);
        $correlation->set('request-abc');
        $job = $this->createMock(Job::class);

        event(new JobProcessing('sync', $job));
        $first = $correlation->current();
        $this->assertNotSame('request-abc', $first, 'Job darf nicht die ID des vorherigen Requests erben.');

        event(new JobProcessed('sync', $job));
        $this->assertFalse($correlation->has(), 'Nach dem Job ist der Kontext leer.');

        event(new JobProcessing('sync', $job));
        $this->assertNotSame($first, $correlation->current(), 'Jeder Job erhält eine eigene ID.');
        event(new JobProcessed('sync', $job));
    }
}
