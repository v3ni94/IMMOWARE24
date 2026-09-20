<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Core\Enums\Role;
use App\Modules\Ai\Enums\SuggestionStatus;
use App\Modules\Ai\Models\AiRun;
use App\Modules\Ai\Models\AiSuggestion;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Learning\Enums\LearningKind;
use App\Modules\Learning\Enums\LearningRunStatus;
use App\Modules\Learning\Jobs\RunLearningJob;
use App\Modules\Learning\Models\LearningRun;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class LearningControllerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ImmowareConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = Organization::factory()->create();
        $this->connection = ImmowareConnection::factory()->for($this->organization)->active()->create(['connector_type' => 'webdav_documents']);
    }

    private function loginAs(Role $role): User
    {
        $user = User::factory()->role($role)->for($this->organization)->create();
        $this->actingAs($user)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);

        return $user;
    }

    private function makeRun(array $attributes = []): LearningRun
    {
        return LearningRun::query()->create(array_merge([
            'organization_id' => $this->organization->getKey(),
            'connection_id' => $this->connection->getKey(),
            'kind' => LearningKind::WebDav->value,
            'status' => LearningRunStatus::Succeeded->value,
            'facts_json' => ['kind' => 'webdav', 'folders' => []],
            'diff_json' => ['changed' => false],
            'started_at' => now(),
            'finished_at' => now(),
        ], $attributes));
    }

    public function test_index_requires_the_learning_permission(): void
    {
        $this->loginAs(Role::ReadOnly);

        $this->get(route('admin.learning.index'))->assertForbidden();
    }

    public function test_index_renders_for_administrator(): void
    {
        $this->loginAs(Role::Administrator);
        $this->makeRun();

        $this->get(route('admin.learning.index'))->assertOk()->assertSeeText('Lernphase Immoware24');
    }

    public function test_store_queues_a_run_and_redirects(): void
    {
        Queue::fake();
        $this->loginAs(Role::Administrator);

        $this->post(route('admin.learning.store'), ['kind' => 'webdav', 'connection_id' => $this->connection->getKey()])
            ->assertRedirect(route('admin.learning.index'));

        $this->assertDatabaseHas('learning_runs', ['kind' => 'webdav', 'status' => 'running']);
        Queue::assertPushed(RunLearningJob::class, 1);
    }

    public function test_store_without_a_matching_connection_warns_instead_of_erroring(): void
    {
        $this->loginAs(Role::Administrator);

        $this->post(route('admin.learning.store'), ['kind' => 'carddav'])
            ->assertRedirect(route('admin.learning.index'))
            ->assertSessionHas('warning');

        $this->assertDatabaseCount('learning_runs', 0);
    }

    public function test_show_renders_facts_and_diff(): void
    {
        $this->loginAs(Role::Administrator);
        $run = $this->makeRun(['diff_json' => ['changed' => true, 'new_folders' => ['/Neu/']]]);

        $this->get(route('admin.learning.show', ['run' => $run->getKey()]))
            ->assertOk()
            ->assertSeeText('/Neu/');
    }

    public function test_decide_updates_the_linked_suggestion_and_redirects(): void
    {
        $user = $this->loginAs(Role::Administrator);

        $aiRun = AiRun::query()->create([
            'organization_id' => $this->organization->getKey(),
            'task' => 'learning_document_rules',
            'provider' => 'openai',
            'input_hash' => hash('sha256', 'a'),
            'schema_hash' => hash('sha256', 'b'),
            'status' => 'succeeded',
        ]);
        $suggestion = AiSuggestion::query()->create([
            'ai_run_id' => $aiRun->getKey(),
            'suggestion_type' => 'learning_document_rules',
            'payload_json' => ['new_rules' => [], 'unmatched_folders' => [], 'notes' => []],
            'status' => SuggestionStatus::Proposed->value,
        ]);
        $run = $this->makeRun(['ai_suggestion_id' => $suggestion->getKey()]);

        $this->post(route('admin.learning.decide', ['run' => $run->getKey()]), ['decision' => 'accepted'])
            ->assertRedirect(route('admin.learning.show', ['run' => $run->getKey()]))
            ->assertSessionHas('status');

        $suggestion->refresh();
        $this->assertSame(SuggestionStatus::Accepted->value, $suggestion->status);
        $this->assertSame($user->getKey(), $suggestion->decided_by);
    }

    public function test_decide_without_a_linked_suggestion_warns(): void
    {
        $this->loginAs(Role::Administrator);
        $run = $this->makeRun();

        $this->post(route('admin.learning.decide', ['run' => $run->getKey()]), ['decision' => 'accepted'])
            ->assertSessionHas('warning');
    }
}
