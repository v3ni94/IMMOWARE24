<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Actions\Jobs\ExecuteActionJob;
use App\Modules\Actions\Models\Approval;
use App\Modules\Actions\Services\ApprovalService;
use App\Modules\Mail\Models\MailboxPermission;
use App\Modules\Security\Http\Middleware\RequireFreshTwoFactor;
use App\Modules\Security\Http\Middleware\RequireTwoFactor;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

final class ApprovalCenterTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_iban_is_masked_without_bank_data_right_and_revealed_with_right_and_audit(): void
    {
        $lead = $this->actingAsMailRole('lead');
        $author = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($author, $this->mailbox, 'agent');
        $case = $this->createCase($this->mailbox);
        $plan = $this->createBankPlan($case, $author);

        // Approver ohne Bankdatenrecht am Postfach: maskiert, kein Button.
        $approver = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($approver, $this->mailbox, 'approver');
        MailboxPermission::query()->where('user_id', $approver->getKey())->update(['can_view_bank_data' => false]);

        $this->actingAs($approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get(self::BASE.'/approvals/'.$plan->getKey())
            ->assertOk()
            ->assertSee('DE02 **** **** **** **** 51')
            ->assertDontSee('DE02120300000000202051')
            ->assertDontSee('DE02500105170137075030')
            ->assertSee('Bankdaten maskiert');

        // Teamleitung mit Recht: erst maskiert, nach Klick voll sichtbar und protokolliert.
        $this->actingAs($lead)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->get(self::BASE.'/approvals/'.$plan->getKey())
            ->assertOk()
            ->assertDontSee('DE02120300000000202051')
            ->assertSee('Volle IBAN anzeigen');

        $this->post(self::BASE.'/approvals/'.$plan->getKey().'/reveal-bank-data')
            ->assertRedirect(self::BASE.'/approvals/'.$plan->getKey());

        $this->get(self::BASE.'/approvals/'.$plan->getKey())->assertOk()->assertSee('DE02500105170137075030');
        $this->assertTrue(AuditLog::query()->where('action', 'mail.bank_data.revealed')->exists());

        // Beim nächsten Aufruf wieder maskiert.
        $this->get(self::BASE.'/approvals/'.$plan->getKey())->assertOk()->assertDontSee('DE02500105170137075030');
    }

    public function test_approval_without_session_marker_is_rejected_even_without_middleware(): void
    {
        $author = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox);
        $plan = $this->createBankPlan($case, $author);
        $approver = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($approver, $this->mailbox, 'approver');

        // Ohne 2fa.fresh (z. B. geänderte Routengruppe) darf der Controller keinen Reauth-Zeitpunkt erfinden.
        $this->flushSession();
        $this->withoutMiddleware([RequireFreshTwoFactor::class, RequireTwoFactor::class])
            ->actingAs($approver)
            ->post(self::BASE.'/approvals/'.$plan->getKey().'/approve')
            ->assertForbidden();

        $this->assertSame(0, Approval::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'mail.approval.reauth_missing')->exists());
    }

    public function test_author_cannot_reject_own_plan_and_self_approval_is_audited(): void
    {
        $author = $this->actingAsMailRole('lead');
        $case = $this->createCase($this->mailbox);
        $plan = $this->createBankPlan($case, $author);

        $this->post(self::BASE.'/approvals/'.$plan->getKey().'/approve')->assertForbidden();
        $this->assertTrue(AuditLog::query()->where('action', 'mail.approval.rejected_self')->exists());

        $this->post(self::BASE.'/approvals/'.$plan->getKey().'/reject', ['reason' => 'Eigener Plan zurückgezogen'])->assertForbidden();
        $this->assertSame('approval_required', $plan->fresh()->status->value);
    }

    public function test_approval_requires_fresh_reauthentication(): void
    {
        $author = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox);
        $plan = $this->createBankPlan($case, $author);
        $approver = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($approver, $this->mailbox, 'approver');

        $stale = now()->subMinutes(60)->toIso8601String();

        $this->actingAs($approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale])
            ->post(self::BASE.'/approvals/'.$plan->getKey().'/approve')
            ->assertRedirect(route('security.confirm.show'));

        $this->assertSame(0, Approval::query()->count());
        $this->assertSame('approval_required', $plan->fresh()->status->value);
    }

    public function test_single_approval_with_reauth_records_decision_and_enforces_four_eyes(): void
    {
        // Ausführung läuft als Job (Queue mail-high) und ist nicht Gegenstand dieses Oberflächentests.
        Bus::fake([ExecuteActionJob::class]);
        $author = $this->actingAsMailRole('lead');
        $case = $this->createCase($this->mailbox);
        $plan = $this->createBankPlan($case, $author);
        // Bankänderung: ohne dokumentierte Identitätsprüfung verweigert ActionPolicy jede Freigabe.
        $checker = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->app->make(ApprovalService::class)->recordIdentityCheck($plan->currentVersion, $checker, 'phone_callback');

        // Autor darf nicht freigeben.
        $this->post(self::BASE.'/approvals/'.$plan->getKey().'/approve')->assertForbidden();

        $approver = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($approver, $this->mailbox, 'approver');

        $this->actingAs($approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post(self::BASE.'/approvals/'.$plan->getKey().'/approve', ['comment' => 'Identität telefonisch geprüft'])
            ->assertRedirect(self::BASE.'/approvals/'.$plan->getKey());

        $approval = Approval::query()->first();
        $this->assertNotNull($approval);
        $this->assertSame('approved', $approval->decision);
        $this->assertSame($plan->currentVersion->steps_hash, $approval->steps_hash);
        $this->assertNotNull($approval->reauth_confirmed_at);
        $this->assertSame('approved', $plan->fresh()->status->value);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.approval.approved')->exists());
        $this->get(self::BASE.'/approvals/'.$plan->getKey())->assertOk()->assertSee('kein Ergebnis ist damit erreicht');
    }

    public function test_reject_requires_reason_and_moves_plan_to_manual_review(): void
    {
        $author = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox);
        $plan = $this->createBankPlan($case, $author);
        $approver = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($approver, $this->mailbox, 'approver');

        $this->actingAs($approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()]);
        $this->post(self::BASE.'/approvals/'.$plan->getKey().'/reject', ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(self::BASE.'/approvals/'.$plan->getKey().'/reject', ['reason' => 'Identitätsnachweis fehlt'])->assertRedirect();

        $this->assertSame('manual_review', $plan->fresh()->status->value);
        $this->assertSame('rejected', Approval::query()->first()?->decision);
    }
}
