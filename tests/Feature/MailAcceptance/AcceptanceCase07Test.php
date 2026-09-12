<?php

declare(strict_types=1);

namespace Tests\Feature\MailAcceptance;

use App\Core\Support\CorrelationId;
use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\TestCase;

/**
 * Abnahmefall 7: Bankdatenänderung ohne notwendige zweite Freigabe ist auch per direktem API-Aufruf unmöglich.
 *
 * Geprüft werden alle Pfade am Controller vorbei: Worker-Job (ExecuteActionJob) mit nur einer Freigabe, direkter
 * Aufruf des ExecutionService, HTTP-Freigabe derselben Person zweimal, und dass die REST-API des Hubs keinen
 * Endpunkt für Pläne, Freigaben oder Ausführungen kennt. Ergänzt BankChangePolicyTest.
 */
final class AcceptanceCase07Test extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionFixtures();
    }

    public function test_worker_job_with_single_approval_never_creates_task_or_change(): void
    {
        $account = $this->seedBankAccount();
        $version = $this->propose([$this->bankChangeStep($account)]);
        $this->approvals()->recordIdentityCheck($version, $this->approverOne, 'phone_callback');
        $this->approveBy($version, $this->approverOne);

        $this->assertSame(2, $version->required_approvals);
        $this->assertSame(ActionStatus::ApprovalRequired, $version->plan->fresh()->status);
        $this->assertDatabaseCount('mail_executions', 0);

        // Direkter Job-Lauf, wie ihn ein manipulierter Dispatch oder ein Worker-Neustart auslösen könnte.
        $job = ExecuteActionJob::forStep((int) $version->getKey(), 0, $this->approverOne->getKey());
        $job->handle($this->app->make(ExecutionService::class), $this->app->make('cache'), $this->app->make(CorrelationId::class));

        $this->assertDatabaseCount('mail_tasks', 0);
        $this->assertDatabaseCount('proposed_changes', 0);
        $this->assertSame(0, $this->countExecutedOrVerified($version->getKey()));
        $this->assertNotContains($version->plan->fresh()->status, [ActionStatus::Approved, ActionStatus::Executed, ActionStatus::Verified]);
        $this->assertSame(ActionTarget::BLOCKED, ActionTarget::query()->where('action_plan_version_id', $version->getKey())->value('status'));
        $this->assertSame('DE89370400440532013000', $account->fresh()->iban, 'Spiegel unverändert.');

        // Zweiter Job-Lauf ändert daran nichts.
        $job->handle($this->app->make(ExecutionService::class), $this->app->make('cache'), $this->app->make(CorrelationId::class));
        $this->assertDatabaseCount('mail_tasks', 0);
        $this->assertDatabaseCount('proposed_changes', 0);
    }

    public function test_http_approval_of_same_person_twice_is_refused_and_rest_api_has_no_plan_endpoints(): void
    {
        // Die REST-API des Hubs (api/*) kennt keinen Endpunkt für Pläne, Freigaben oder Ausführungen.
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $uri = (string) $route->uri();

            if (! str_starts_with($uri, 'api/')) {
                continue;
            }

            $this->assertDoesNotMatchRegularExpression('/approv|action[-_]?plan|execut|bank/i', $uri, sprintf('API-Route %s darf keinen Freigabe- oder Ausführungspfad bieten.', $uri));
        }

        $account = $this->seedBankAccount();
        $version = $this->propose([$this->bankChangeStep($account)]);
        $this->approvals()->recordIdentityCheck($version, $this->approverOne, 'phone_callback');
        $this->approvals()->requestApproval($version, $this->author);
        $plan = $version->plan;
        $this->assertSame(ActionStatus::ApprovalRequired, $plan->fresh()->status);

        $this->actingAs($this->approverOne)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post(self::BASE.'/approvals/'.$plan->getKey().'/approve', ['comment' => 'Identität geprüft'])
            ->assertRedirect(self::BASE.'/approvals/'.$plan->getKey());

        $this->assertDatabaseCount('mail_approvals', 1);
        $this->assertSame(ActionStatus::ApprovalRequired, $plan->fresh()->status, 'Eine Freigabe ist keine Ausführungsfreigabe.');
        $this->assertDatabaseCount('mail_executions', 0);
        $this->assertDatabaseCount('mail_tasks', 0);

        // Autor darf über HTTP nicht freigeben.
        $this->actingAs($this->author)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post(self::BASE.'/approvals/'.$plan->getKey().'/approve')
            ->assertForbidden();

        // Dieselbe Person ein zweites Mal: Policy verweigert (duplicate_approval), keine zweite Freigabe, keine Ausführung.
        $response = $this->actingAs($this->approverOne)
            ->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post(self::BASE.'/approvals/'.$plan->getKey().'/approve', ['comment' => 'noch einmal']);
        $response->assertRedirect(self::BASE.'/approvals/'.$plan->getKey());
        $flash = collect($response->baseResponse->getSession()?->all() ?? [])->flatten()->filter(static fn ($v): bool => is_string($v))->implode(' ');
        $this->assertStringContainsString('duplicate_approval', $flash);

        $this->assertDatabaseCount('mail_approvals', 1);
        $this->assertSame(ActionStatus::ApprovalRequired, $plan->fresh()->status);
        $this->assertDatabaseCount('mail_executions', 0);
        $this->assertDatabaseCount('mail_tasks', 0);
        $this->assertSame('DE89370400440532013000', $account->fresh()->iban);
    }

    private function countExecutedOrVerified(int|string $versionId): int
    {
        return (int) Execution::query()
            ->where('action_plan_version_id', $versionId)
            ->whereIn('status', [ExecutionService::STATUS_MANUAL_TASK, ExecutionService::STATUS_VERIFIED])
            ->count();
    }
}
