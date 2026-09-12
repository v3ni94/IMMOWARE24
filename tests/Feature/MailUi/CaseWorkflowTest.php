<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CaseWorkflowTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_assign_status_note_and_task_on_case(): void
    {
        $lead = $this->actingAsMailRole('lead');
        $agent = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($agent, $this->mailbox, 'agent');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => null]);
        $url = self::BASE.'/cases/'.$case->getKey();

        $this->post($url.'/assign', ['assignee_user_id' => $agent->getKey(), 'next_step' => 'Rückruf', 'due_at' => '15.09.2026 09:00'])->assertRedirect($url);
        $this->assertSame($agent->getKey(), (int) $case->fresh()->assignee_user_id);

        // Abschluss aus offen wird vom Modul Cases verweigert (nur aus gelöst, sonst Ausnahmeabschluss): Fehler, kein Erfolg.
        $this->post($url.'/status', ['status' => 'closed'])->assertRedirect($url)->assertSessionHas('error');
        $this->assertSame('open', $case->fresh()->status_processing->value);

        $this->post($url.'/status', ['status' => 'in_progress'])->assertRedirect($url)->assertSessionHas('status');
        $this->assertSame('in_progress', $case->fresh()->status_processing->value);

        $this->post($url.'/notes', ['note' => 'Rückruf vereinbart'])->assertRedirect($url);
        $this->assertTrue(CaseStatusLog::query()->where('case_id', $case->getKey())->where('dimension', 'note')->exists());
        $this->get($url)->assertOk()->assertSee('Rückruf vereinbart');

        $this->post($url.'/tasks', ['title' => 'Bankdaten in Lexware prüfen'])->assertRedirect($url);
        $this->get($url)->assertOk()->assertSee('Bankdaten in Lexware prüfen');
    }

    public function test_draft_is_stored_locally_send_is_locked_and_alias_must_belong_to_mailbox(): void
    {
        $agent = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => $agent->getKey()]);
        $url = self::BASE.'/cases/'.$case->getKey();
        $foreign = $this->createMailbox(null, null, null, ['organization_id' => $this->mailbox->organization_id, 'label' => 'MHAG']);
        $foreignAlias = MailboxAlias::query()->create(['mailbox_id' => $foreign->getKey(), 'send_as_email' => 'vorstand@mueller-holding.ag', 'legal_entity_code' => 'MHAG']);

        $this->post($url.'/drafts', ['alias_id' => $foreignAlias->getKey(), 'to' => 'mieter@example.com', 'subject' => 'AW: Test', 'body_text' => 'Text'])->assertRedirect($url)->assertSessionHas('error');
        $this->assertSame(0, MailDraft::query()->count());

        $this->post($url.'/drafts', ['to' => 'mieter@example.com', 'subject' => 'AW: Test', 'body_text' => 'Sehr geehrte Damen und Herren'])->assertRedirect($url)->assertSessionHas('status');
        $draft = MailDraft::query()->first();
        $this->assertSame('local', $draft->status);
        $this->assertNull($draft->gmail_draft_id);

        $this->get($url)->assertOk()->assertSee('Zur Prüfung geben')->assertSee('Senden gesperrt');

        $this->post($url.'/drafts/'.$draft->getKey().'/send')->assertRedirect($url)->assertSessionHas('warning');
        $this->assertSame('local', $draft->fresh()->status);

        $this->post($url.'/drafts/'.$draft->getKey().'/review')->assertRedirect($url);
        $this->assertSame('pending_approval', $draft->fresh()->status);
    }

    public function test_send_with_flag_still_requires_reauth_and_never_reports_success_without_live_service(): void
    {
        config()->set('hub.mail.flags.gmail_send', true);
        $agent = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => $agent->getKey()]);
        $url = self::BASE.'/cases/'.$case->getKey();
        $this->post($url.'/drafts', ['to' => 'mieter@example.com', 'subject' => 'AW', 'body_text' => 'Text'])->assertRedirect($url);
        $draft = MailDraft::query()->first();

        $this->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->subHour()->toIso8601String()])
            ->post($url.'/drafts/'.$draft->getKey().'/send')
            ->assertRedirect(route('security.confirm.show'));

        $this->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post($url.'/drafts/'.$draft->getKey().'/send')
            ->assertRedirect($url)
            ->assertSessionHas('warning');
        $this->assertSame('local', $draft->fresh()->status);
    }
}
