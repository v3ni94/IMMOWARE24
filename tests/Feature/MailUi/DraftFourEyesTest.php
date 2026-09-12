<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vier-Augen beim Versand an Externe (docs/mail/05 Abschnitt 5): Autor und Freigebende sind verschiedene Personen,
 * ohne Freigabe kein Versand, auch nicht mit can_draft und can_send in einer Hand.
 */
final class DraftFourEyesTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_author_cannot_send_own_gmail_draft_without_second_person(): void
    {
        config()->set('hub.mail.flags.gmail_send', true);
        config()->set('hub.mail.flags.gmail_drafts', true);
        $author = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => $author->getKey()]);
        $draft = $this->createPushedDraft($case, $author);
        $url = self::BASE.'/cases/'.$case->getKey();

        // Autor mit can_draft und can_send: Versand ohne Freigabe ist 403.
        $this->post($url.'/drafts/'.$draft->getKey().'/send')->assertForbidden();
        $this->assertSame('pushed_to_gmail', $draft->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.draft.send_refused')->exists());

        // Selbstfreigabe ist 403, auch mit Freigaberecht (agent hat keins, lead hätte eins).
        $lead = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($lead, $this->mailbox, 'lead');
        $ownDraft = $this->createPushedDraft($case, $lead);

        $this->actingAs($lead)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post($url.'/drafts/'.$ownDraft->getKey().'/approve')
            ->assertForbidden();
        $this->assertNull($ownDraft->fresh()->approved_by);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.draft.approval_rejected_self')->exists());

        // Freigabe durch eine zweite Person mit mail.approve.standard, danach darf der Autor senden (Controller-Gate).
        $this->post($url.'/drafts/'.$draft->getKey().'/approve')->assertRedirect($url)->assertSessionHas('status');
        $this->assertSame($lead->getKey(), (int) $draft->fresh()->approved_by);

        // Freigebende Person darf den freigegebenen Entwurf nicht selbst senden (Freigabe ungleich Sendende).
        $this->post($url.'/drafts/'.$draft->getKey().'/send')->assertForbidden();

        // Der Autor sendet nach Fremdfreigabe: das Gate lässt durch, der Fake-Provider liefert kein verifiziertes Ergebnis.
        $this->actingAs($author)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post($url.'/drafts/'.$draft->getKey().'/send')
            ->assertRedirect($url);
        $this->assertNotSame('sent_verified', $draft->fresh()->status, 'Ein HTTP-Erfolg ist kein Versand.');
    }

    public function test_agent_without_approval_right_cannot_approve(): void
    {
        $author = $this->actingAsMailRole('agent');
        $case = $this->createCase($this->mailbox, ['assignee_user_id' => $author->getKey()]);
        $draft = $this->createPushedDraft($case, $author);
        $otherAgent = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($otherAgent, $this->mailbox, 'agent');

        $this->actingAs($otherAgent)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => now()->toIso8601String()])
            ->post(self::BASE.'/cases/'.$case->getKey().'/drafts/'.$draft->getKey().'/approve')
            ->assertForbidden();
        $this->assertNull($draft->fresh()->approved_by);
    }

    private function createPushedDraft(MailCase $case, User $author): MailDraft
    {
        $alias = MailboxAlias::query()->firstOrCreate(
            ['mailbox_id' => $this->mailbox->getKey(), 'send_as_email' => 'info@muellerhv.de'],
            ['legal_entity_code' => 'HVM', 'verification_status' => 'accepted', 'is_default' => true],
        );

        return MailDraft::query()->create([
            'organization_id' => $this->mailbox->organization_id,
            'mailbox_id' => $this->mailbox->getKey(),
            'case_id' => $case->getKey(),
            'alias_id' => $alias->getKey(),
            'to_json' => ['mieter@example.com'],
            'cc_json' => [],
            'subject' => 'AW: Test',
            'body_text' => 'Text',
            'status' => 'pushed_to_gmail',
            'gmail_draft_id' => 'r'.random_int(1000, 9999),
            'created_by' => $author->getKey(),
        ]);
    }
}
