<?php

declare(strict_types=1);

namespace Tests\Feature\Learning;

use App\Modules\Ai\Enums\SuggestionStatus;
use App\Modules\Ai\Testing\FakeAiProvider;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Jobs\RunLearningJob;
use App\Modules\Learning\Services\LearningAiAdvisor;
use App\Modules\Learning\Services\LearningRunService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Documents\DocumentsTestHelpers;
use Tests\TestCase;

final class RunLearningJobTest extends TestCase
{
    use DocumentsTestHelpers, RefreshDatabase;

    public function test_handle_executes_the_run_and_leaves_it_succeeded(): void
    {
        config()->set('hub.documents.scan.roots', []);
        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);

        Http::fake(fn (): PromiseInterface => Http::response($this->multistatus($base, [['href' => '/', 'collection' => true]]), 207));

        $runs = $this->app->make(LearningRunService::class);
        $run = $runs->start((int) $connection->organization->getKey(), LearningKind::WebDav, $connection->getKey(), null);

        (new RunLearningJob($run->getKey()))->handle($runs, $this->app->make(LearningAiAdvisor::class));

        $run->refresh();
        $this->assertSame('succeeded', $run->getAttribute('status'));
        $this->assertNull($run->getAttribute('ai_suggestion_id'), 'Ohne withAi darf keine KI-Auswertung stattfinden.');
    }

    public function test_handle_with_ai_flag_runs_the_advisor_and_links_the_suggestion(): void
    {
        config()->set('hub.mail.flags.ai', true);
        config()->set('hub.mail.providers.ai', 'fake');
        config()->set('hub.documents.scan.roots', []);

        $connection = $this->readConnection();
        $base = $this->basePathOf($connection);

        Http::fake(fn (): PromiseInterface => Http::response($this->multistatus($base, [['href' => '/', 'collection' => true]]), 207));

        /** @var FakeAiProvider $fake */
        $fake = $this->app->make(FakeAiProvider::class);
        $fake->setDefault('learning_document_rules', ['new_rules' => [], 'unmatched_folders' => [], 'notes' => ['ok']]);

        $runs = $this->app->make(LearningRunService::class);
        $run = $runs->start((int) $connection->organization->getKey(), LearningKind::WebDav, $connection->getKey(), null);

        (new RunLearningJob($run->getKey(), withAi: true))->handle($runs, $this->app->make(LearningAiAdvisor::class));

        $run->refresh();
        $this->assertSame('succeeded', $run->getAttribute('status'));
        $this->assertNotNull($run->getAttribute('ai_suggestion_id'));
        $this->assertSame(SuggestionStatus::Proposed->value, $run->aiSuggestion->status);
    }

    public function test_missing_run_is_logged_and_does_not_throw(): void
    {
        $runs = $this->app->make(LearningRunService::class);
        $advisor = $this->app->make(LearningAiAdvisor::class);

        (new RunLearningJob(999999))->handle($runs, $advisor);
        $this->addToAssertionCount(1);
    }

    public function test_failed_marks_the_run_as_failed_with_the_exception_message(): void
    {
        $connection = $this->readConnection();
        $runs = $this->app->make(LearningRunService::class);
        $run = $runs->start((int) $connection->organization->getKey(), LearningKind::WebDav, $connection->getKey(), null);

        (new RunLearningJob($run->getKey()))->failed(new \RuntimeException('Warteschlange dauerhaft nicht verfügbar.'));

        $run->refresh();
        $this->assertSame('failed', $run->getAttribute('status'));
        $this->assertSame('Warteschlange dauerhaft nicht verfügbar.', $run->getAttribute('error_message'));
    }
}
