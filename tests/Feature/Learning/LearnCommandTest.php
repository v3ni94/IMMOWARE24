<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Modules\Learning\Jobs\RunLearningJob;
use App\Modules\Learning\Models\LearningRun;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Documents\DocumentsTestHelpers;
use Tests\TestCase;

final class LearnCommandTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    public function test_without_kind_it_queues_a_run_per_applicable_kind_and_skips_kinds_without_a_connection(): void
    {
        Queue::fake();
        $connection = $this->readConnection();
        // Nur eine WebDAV-Connection vorhanden: carddav, caldav werden übersprungen, imports braucht keine.

        $this->artisan('hub:immoware:learn', ['--organization' => $connection->organization->getKey()])
            ->assertSuccessful();

        Queue::assertPushed(RunLearningJob::class, 2);
        $this->assertDatabaseCount('learning_runs', 2);
    }

    public function test_sync_option_executes_immediately_and_reports_status(): void
    {
        config()->set('hub.documents.scan.roots', []);
        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);

        Http::fake(fn (): PromiseInterface => Http::response($this->multistatus($base, [['href' => '/', 'collection' => true]]), 207));

        $this->artisan('hub:immoware:learn', [
            '--organization' => $connection->organization->getKey(),
            '--kind' => 'webdav',
            '--connection' => $connection->getKey(),
            '--sync' => true,
        ])->assertSuccessful()
            ->expectsOutputToContain('Abgeschlossen');

        $run = LearningRun::query()->first();
        $this->assertNotNull($run);
        $this->assertSame('succeeded', $run->getAttribute('status'));
    }

    public function test_invalid_kind_is_rejected(): void
    {
        $organization = $this->createOrganization();

        $this->artisan('hub:immoware:learn', ['--organization' => $organization->getKey(), '--kind' => 'unbekannt'])
            ->assertFailed();
    }

    public function test_without_any_organization_it_fails_with_a_clear_message(): void
    {
        $this->artisan('hub:immoware:learn', ['--organization' => 999])->assertFailed();
    }
}
