<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Security\Http\Middleware\RequireFreshTwoFactor;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entwurfsfreigabe und Versand nur mit frischer Zwei-Faktor-Sitzung (docs/mail/05 Abschnitt 6, docs/mail/08
 * Abschnitt 11.2 Punkt 8). Ohne aktuellen Nachweis leitet die Middleware 2fa.fresh zur Bestätigung um; fällt die
 * Middleware weg, verweigert der Controller mit 403 und Auditeintrag. Mit frischer Sitzung wird freigegeben.
 */
final class DraftReauthTest extends TestCase
{
    use CreatesMailCases;
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    private User $author;

    private User $approver;

    private MailCase $case;

    private MailDraft $draft;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.mail.flags.gmail_send', true);
        config()->set('hub.mail.flags.gmail_drafts', true);
        $this->author = $this->actingAsMailRole('agent');
        $this->case = $this->createCase($this->mailbox, ['assignee_user_id' => $this->author->getKey()]);
        $this->draft = $this->createPushedDraft($this->case, $this->author);
        $this->approver = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($this->approver, $this->mailbox, 'lead');
    }

    public function test_approval_without_fresh_two_factor_session_is_redirected_to_reauthentication(): void
    {
        $stale = now()->subMinutes(60)->toIso8601String();

        // Abgelaufene 2FA-Bestätigung: Umleitung zur erneuten Bestätigung, keine Freigabe.
        $this->actingAs($this->approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale])
            ->post($this->url('approve'))
            ->assertRedirect(route('security.confirm.show'));
        $this->assertNull($this->draft->fresh()->approved_by);

        // Ohne jeden Marker in der Sitzung ebenso.
        $this->actingAs($this->approver)->post($this->url('approve'))->assertRedirect(route('security.confirm.show'));
        $this->assertNull($this->draft->fresh()->approved_by);
        $this->assertFalse(AuditLog::query()->where('action', 'mail.draft.approved')->exists());
    }

    public function test_controller_refuses_approval_without_reauth_even_when_middleware_is_absent(): void
    {
        $stale = now()->subMinutes(60)->toIso8601String();

        $this->withoutMiddleware(RequireFreshTwoFactor::class)
            ->actingAs($this->approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale])
            ->post($this->url('approve'))
            ->assertForbidden();

        $this->assertNull($this->draft->fresh()->approved_by);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.draft.reauth_missing')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'mail.draft.approved')->exists());
    }

    public function test_send_without_fresh_two_factor_session_is_refused(): void
    {
        $this->draft->forceFill(['approved_by' => $this->approver->getKey(), 'approved_at' => now(), 'approval_reauth_confirmed_at' => now()])->save();
        $stale = now()->subMinutes(60)->toIso8601String();

        $this->actingAs($this->author)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale])
            ->post($this->url('send'))
            ->assertRedirect(route('security.confirm.show'));
        $this->assertSame('pushed_to_gmail', $this->draft->fresh()->status);

        $this->withoutMiddleware(RequireFreshTwoFactor::class)
            ->actingAs($this->author)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $stale])
            ->post($this->url('send'))
            ->assertForbidden();
        $this->assertSame('pushed_to_gmail', $this->draft->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.draft.reauth_missing')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'mail.draft.send_requested')->exists());
    }

    public function test_approval_with_fresh_two_factor_session_records_reauth_timestamp(): void
    {
        $fresh = now()->subMinutes(3);

        $this->actingAs($this->approver)->withSession([LoginService::SESSION_TWO_FACTOR_VERIFIED => $fresh->toIso8601String()])
            ->post($this->url('approve'))
            ->assertRedirect(self::BASE.'/cases/'.$this->case->getKey())
            ->assertSessionHas('status');

        $draft = $this->draft->fresh();
        $this->assertSame($this->approver->getKey(), (int) $draft->approved_by);
        $this->assertNotNull($draft->approved_at);
        $this->assertSame($fresh->toIso8601String(), $draft->approval_reauth_confirmed_at?->toIso8601String());
        $this->assertTrue(AuditLog::query()->where('action', 'mail.draft.approved')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'mail.draft.reauth_missing')->exists());
    }

    private function url(string $action): string
    {
        return self::BASE.'/cases/'.$this->case->getKey().'/drafts/'.$this->draft->getKey().'/'.$action;
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
