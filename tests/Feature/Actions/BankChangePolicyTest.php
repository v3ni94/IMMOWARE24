<?php

declare(strict_types=1);

namespace Tests\Feature\Actions;

use App\Modules\Actions\Enums\ActionStatus;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Exceptions\ActionPolicyException;
use App\Modules\Actions\Models\ActionTarget;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ActionPlanService;
use App\Modules\Actions\Services\ExecutionService;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Cases\Models\Task;
use App\Modules\Sync\Models\ProposedChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Actions\Concerns\BuildsActionPlans;
use Tests\TestCase;

/**
 * Abnahmefälle 6 und 7: gültige IBAN ohne Identitätsprüfung gesperrt; Bankänderung ohne zweite Freigabe auch per
 * direktem Service-Aufruf unmöglich; Immoware24 ohne Schreibfähigkeit liefert manuelle Aufgabe, Verifikation nur
 * bei tatsächlichem Wert-Match im Spiegel.
 */
final class BankChangePolicyTest extends TestCase
{
    use BuildsActionPlans, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpActionFixtures();
    }

    public function test_bank_change_requires_assignment_of_contract_and_role(): void
    {
        $account = $this->seedBankAccount();

        $this->expectException(ActionPolicyException::class);
        $this->expectExceptionMessage('Vertrag und Rolle');
        $this->propose([$this->bankChangeStep($account, withAssignment: false)]);
    }

    public function test_invalid_iban_is_rejected_before_any_plan_exists(): void
    {
        $account = $this->seedBankAccount();

        $this->expectException(\InvalidArgumentException::class);
        $this->propose([$this->bankChangeStep($account, 'DE88370400440532013000')]);
    }

    public function test_valid_iban_without_identity_check_cannot_be_approved(): void
    {
        $account = $this->seedBankAccount();
        $version = $this->propose([$this->bankChangeStep($account)]);

        $this->assertSame(RiskClass::Bank, $version->plan->risk_class);
        $this->assertSame(2, $version->required_approvals);
        $this->assertTrue($version->requires_identity_check);
        $this->assertSame('DE89370400440532013000', $version->old_values[0]['iban'], 'Alt-Wert aus dem Spiegel, verschlüsselt gespeichert.');
        $this->assertSame('DE02 **** **** **** **20 51', $this->app->make(ActionPlanService::class)->preview($version)[0]['diff']['iban']['new']);

        try {
            $this->approveBy($version, $this->approverOne);
            $this->fail('Freigabe ohne Identitätsprüfung darf nicht möglich sein.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('identity_check_missing', $e->code_key);
        }

        $this->assertDatabaseCount('mail_approvals', 0);
        $this->assertDatabaseCount('mail_tasks', 0);
        $this->assertNotSame(ActionStatus::Approved, $version->plan->fresh()->status);
    }

    public function test_bank_change_needs_two_distinct_approvers_even_via_direct_service_call(): void
    {
        $account = $this->seedBankAccount();
        $version = $this->propose([$this->bankChangeStep($account)]);
        $this->approvals()->recordIdentityCheck($version, $this->approverOne, 'phone_callback');

        // Selbstfreigabe des Autors
        try {
            $this->approveBy($version, $this->author);
            $this->fail('Autor darf nicht freigeben.');
        } catch (ActionPolicyException $e) {
            $this->assertContains($e->code_key, ['self_approval', 'approval_forbidden']);
        }

        $this->approveBy($version, $this->approverOne);
        $this->assertSame(ActionStatus::ApprovalRequired, $version->plan->fresh()->status, 'Eine Freigabe reicht nicht.');

        // Dieselbe Person ein zweites Mal
        try {
            $this->approveBy($version, $this->approverOne);
            $this->fail('Zweite Freigabe derselben Person darf nicht zählen.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('duplicate_approval', $e->code_key);
        }

        // Direkter Service-Aufruf am Controller vorbei: Vorbedingung Freigabe fehlt, keine Aufgabe, kein proposed_change
        $result = $this->app->make(ExecutionService::class)->executeStep($version->fresh(['plan']), 0, $this->author->getKey(), 'test');
        $this->assertNull($result);
        $this->assertDatabaseCount('mail_tasks', 0);
        $this->assertDatabaseCount('proposed_changes', 0);
        $this->assertSame(ActionStatus::ManualReview, $version->plan->fresh()->status);
        $this->assertSame(ActionTarget::BLOCKED, ActionTarget::query()->where('action_plan_version_id', $version->getKey())->value('status'));
    }

    public function test_second_approver_triggers_manual_task_and_verification_follows_mirror(): void
    {
        $account = $this->seedBankAccount();
        $version = $this->propose([$this->bankChangeStep($account)]);
        $this->approvals()->recordIdentityCheck($version, $this->approverOne, 'phone_callback');
        $this->approveBy($version, $this->approverOne);
        $this->approveBy($version, $this->approverTwo);

        // Queue sync: ExecuteActionJob lief bereits. Immoware24 ist nicht schreibbar, also manuelle Aufgabe.
        $execution = Execution::query()->where('action_plan_version_id', $version->getKey())->firstOrFail();
        $this->assertSame(ExecutionService::STATUS_MANUAL_TASK, $execution->status);
        $this->assertSame(VerificationStatus::Unverified, $execution->verification_status);
        $this->assertEqualsCanonicalizing([$this->approverOne->getKey(), $this->approverTwo->getKey()], $execution->approved_by_json);
        $this->assertSame(ActionStatus::Executed, $version->plan->fresh()->status, 'Ausgeführt heißt nicht verifiziert.');

        $task = Task::query()->findOrFail($execution->task_id);
        $this->assertSame('manual_change_immoware', $task->task_type);
        $this->assertSame('DE02120300000000202051', $task->new_value_json['iban']);
        $this->assertSame('DE89370400440532013000', $task->old_value_json['iban']);
        $this->assertStringNotContainsString('DE02120300000000202051', (string) $task->instructions);
        $this->assertStringNotContainsString('Link:', (string) $task->instructions, 'Kein Deep-Link ohne konfiguriertes Muster.');

        $change = ProposedChange::query()->findOrFail($execution->proposed_change_id);
        $this->assertSame('bank_account', $change->entity_type);
        $this->assertStringContainsString('DE02 **** **** **** **20 51', (string) $change->new_value, 'proposed_changes nur maskiert.');
        $this->assertStringNotContainsString('DE02120300000000202051', (string) $change->new_value);

        // Nutzer bestätigt: manuell bestätigt, Plan verifiziert, proposed_change übertragen
        $service = $this->app->make(ManualTaskService::class);
        $verification = $service->confirm($task, $this->approverTwo);
        $this->assertSame(VerificationStatus::ManuallyConfirmed, $verification->result);
        $this->assertSame(ActionStatus::Verified, $version->plan->fresh()->status);
        $this->assertSame('transferred', $change->fresh()->status->value);

        // Nachlesen vor dem nächsten Sync: Spiegel zeigt noch alt, bleibt manuell bestätigt
        $recheck = $service->recheck($execution->fresh());
        $this->assertSame(VerificationStatus::ManuallyConfirmed, $recheck->result);
        $this->assertSame(ManualTaskService::TASK_DONE_MANUAL, $task->fresh()->status);

        // Nach dem Sync: Spiegel trägt neue IBAN, jetzt api_verified
        $account->forceFill(['iban' => 'DE02120300000000202051', 'iban_masked' => 'DE02***2051'])->save();
        $recheck = $service->recheck($execution->fresh());
        $this->assertSame(VerificationStatus::ApiVerified, $recheck->result);
        $this->assertSame(ManualTaskService::TASK_DONE_VERIFIED, $task->fresh()->status);
        $this->assertSame(VerificationStatus::ApiVerified, $execution->fresh()->verification_status);
    }

    public function test_unknown_action_type_and_field_are_blocked_by_allowlist(): void
    {
        $contact = $this->seedMirrorContact();

        try {
            $this->propose([['target_system' => 'immoware24', 'action_type' => 'delete_contact', 'external_ref' => [], 'new_values' => ['x' => 1]]]);
            $this->fail('Unbekannter Aktionstyp muss gesperrt sein.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('blocked_capability', $e->code_key);
        }

        try {
            $step = $this->immowareAddressStep($contact);
            $step['new_values'] = ['iban' => 'DE89370400440532013000'];
            $this->propose([$step]);
            $this->fail('Feld außerhalb des Aktionstyps muss gesperrt sein.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('blocked_field', $e->code_key);
        }

        try {
            $this->propose([['target_system' => 'gmail', 'action_type' => 'manual_task', 'external_ref' => [], 'new_values' => []]]);
            $this->fail('Gmail hat in diesem Abschnitt keinen Adapter und keine Allowlist.');
        } catch (ActionPolicyException $e) {
            $this->assertSame('blocked_capability', $e->code_key);
        }
    }
}
