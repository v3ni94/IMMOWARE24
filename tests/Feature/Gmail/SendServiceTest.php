<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\GmailReplyDetected;
use App\Modules\Gmail\Exceptions\DraftApprovalRefusedException;
use App\Modules\Gmail\Exceptions\SendRefusedException;
use App\Modules\Gmail\Models\MailDraft;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Models\SendReconciliation;
use App\Modules\Gmail\Services\DraftService;
use App\Modules\Gmail\Services\SendReconciliationService;
use App\Modules\Gmail\Services\SendService;
use App\Modules\Gmail\Services\Sync\MessageImporter;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SendServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailProvider $gmail;

    private User $user;

    private User $approver;

    private MailboxAlias $alias;

    private MailMessage $original;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.mail.flags.gmail_drafts', true);
        config()->set('hub.mail.flags.gmail_send', true);
        $this->gmail = $this->app->make(GmailProviderInterface::class);
        $this->user = $this->actingAsMailRole('lead');
        $this->approver = $this->actingAsMailRole('approver', $this->mailbox);
        $this->attachMailRole($this->approver, $this->mailbox, 'approver');
        $this->actingAs($this->user);
        $this->mailbox->forceFill(['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true])->save();
        $this->alias = MailboxAlias::query()->create(['mailbox_id' => $this->mailbox->getKey(), 'send_as_email' => 'verwaltung@muellerhv.de', 'legal_entity_code' => 'HVM', 'verification_status' => 'accepted']);
        $id = $this->gmail->seedMessage($this->boxId(), ['from' => 'mieter@example.com', 'subject' => 'Heizung', 'text' => 'Kalt.', 'thread_id' => 'thread-1']);
        $this->original = $this->app->make(MessageImporter::class)->import($this->mailbox, $id) ?? throw new \RuntimeException('Import fehlgeschlagen.');
    }

    public function test_send_is_refused_when_flag_is_off(): void
    {
        config()->set('hub.mail.flags.gmail_send', false);
        $draft = $this->draft();

        $this->expectException(SendRefusedException::class);
        $this->expectExceptionMessage('deaktiviert');
        $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());
    }

    public function test_send_is_refused_without_permission_or_unverified_alias(): void
    {
        $draft = $this->draft();

        $agentWithoutSend = $this->actingAsMailRole('auditor', $this->mailbox);
        $this->assertRefused($draft, $agentWithoutSend, 'permission_denied');

        $this->alias->forceFill(['verification_status' => 'pending'])->save();
        $this->assertRefused($draft, $this->user, 'alias_not_verified');

        $this->assertSame([], $this->gmail->calls('sendDraft'));
        $this->assertSame(DraftService::STATUS_PUSHED, $draft->refresh()->getAttribute('status'));
    }

    public function test_successful_send_is_verified_via_sent_label_and_never_marked_delivered(): void
    {
        $draft = $this->draft();

        $result = $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());

        $this->assertSame('sent_verified', $result->getAttribute('status'));
        $this->assertSame(SendReconciliationService::VERIFIED, $result->getAttribute('send_verification'));
        $this->assertSame('unknown', $result->getAttribute('delivery_status'), 'Gesendet ist nie zugestellt.');
        $this->assertNotNull($result->getAttribute('sent_message_id'));
        $this->assertSame((int) $this->user->getKey(), (int) $result->getAttribute('sent_requested_by'));
        $this->assertSame('verified', SendReconciliation::query()->firstOrFail()->getAttribute('result'));
        $this->assertSame('outbound', MailMessage::query()->findOrFail($result->getAttribute('sent_message_id'))->getAttribute('direction'));

        $this->assertRefused($result, $this->user, 'already_requested');
    }

    public function test_unclear_send_result_blocks_resend(): void
    {
        config()->set('hub.gmail.send.reconcile_attempts', 1);
        $draft = $this->draft();
        $this->gmail->failNext('sendDraft', new MailRemoteException('Timeout', 'gmail', 504, null));

        $result = $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());

        $this->assertSame(SendService::STATUS_SENT_REQUESTED, $result->getAttribute('status'), 'Nie sent nach Fehler der Gegenstelle.');
        $this->assertSame(SendReconciliationService::UNCLEAR, $result->getAttribute('send_verification'));
        $this->assertSame('not_found', SendReconciliation::query()->firstOrFail()->getAttribute('result'));

        $this->assertRefused($result->forceFill(['status' => DraftService::STATUS_PUSHED]), $this->user, 'previous_send_unclear');
        $this->assertCount(1, $this->gmail->calls('sendDraft'), 'Kein erneuter Versand bei unklarem Ergebnis.');
    }

    public function test_pending_reconciliation_is_retried_until_verified(): void
    {
        config()->set('hub.gmail.send.reconcile_attempts', 3);
        $draft = $this->draft();
        $this->gmail->failNext('listSent', new MailRemoteException('Vorübergehend', 'gmail', 503, null));

        $result = $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());
        $this->assertSame(SendReconciliationService::UNVERIFIED, $result->getAttribute('send_verification'));

        $reconciliation = SendReconciliation::query()->firstOrFail();
        $reconciliation->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->assertSame(1, $this->app->make(SendReconciliationService::class)->processDue());
        $this->assertSame('sent_verified', $draft->refresh()->getAttribute('status'));
    }

    public function test_only_one_open_reconciliation_exists_per_draft(): void
    {
        config()->set('hub.gmail.send.reconcile_attempts', 3);
        $draft = $this->draft();
        $this->gmail->failNext('listSent', new MailRemoteException('Vorübergehend', 'gmail', 503, null));
        $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());

        $service = $this->app->make(SendReconciliationService::class);
        $first = SendReconciliation::query()->firstOrFail();
        $this->assertSame((int) $draft->getKey(), $first->getAttribute('open_key'));

        // Ein Retry von drafts.send darf keinen zweiten offenen Abgleich anlegen; eine vorhandene Antwort-ID bleibt.
        $second = $service->start($draft->refresh(), 'antwort-42');
        $this->assertSame((int) $first->getKey(), (int) $second->getKey());
        $this->assertSame($first->getAttribute('gmail_response_message_id'), $second->getAttribute('gmail_response_message_id'));
        $first->forceFill(['gmail_response_message_id' => null])->save();
        $this->assertSame('antwort-42', $service->start($draft->refresh(), 'antwort-42')->getAttribute('gmail_response_message_id'), 'Fehlende Antwort-ID wird ergänzt.');
        $this->assertSame(1, SendReconciliation::query()->count());

        // Der Unique-Index greift auch bei einem direkten Insert unter Umgehung des Services.
        try {
            SendReconciliation::query()->create(['draft_id' => $draft->getKey(), 'open_key' => $draft->getKey(), 'requested_at' => now(), 'expected_rfc_message_id' => 'x', 'result' => 'pending']);
            $this->fail('Unique-Index auf open_key erwartet.');
        } catch (QueryException) {
            $this->assertSame(1, SendReconciliation::query()->count());
        }

        // Nach Abschluss ist open_key leer; ein neuer Abgleich desselben Entwurfs ist wieder möglich.
        $first->forceFill(['next_check_at' => now()->subMinute()])->save();
        $service->processDue();
        $this->assertSame('verified', $first->refresh()->getAttribute('result'));
        $this->assertNull($first->getAttribute('open_key'));
        $this->assertNotSame((int) $first->getKey(), (int) $service->start($draft->refresh(), null)->getKey());
    }

    public function test_new_inbound_thread_message_blocks_send_until_draft_is_reviewed(): void
    {
        $draft = $this->draft();
        $this->travel(1)->minutes();
        $newer = $this->gmail->seedMessage($this->boxId(), ['from' => 'mieter@example.com', 'subject' => 'Re: Heizung', 'text' => 'Jetzt auch Wasser.', 'thread_id' => 'thread-1']);
        $this->app->make(MessageImporter::class)->import($this->mailbox, $newer);

        $this->assertRefused($draft, $this->user, 'thread_changed');
    }

    public function test_direct_gmail_reply_without_hub_draft_is_detected_but_hub_sent_message_is_not(): void
    {
        Event::fake([GmailReplyDetected::class]);
        $sent = $this->gmail->seedMessage($this->boxId(), ['from' => 'verwaltung@muellerhv.de', 'to' => 'mieter@example.com', 'subject' => 'Re: Heizung', 'text' => 'Direkt in Gmail geantwortet.', 'thread_id' => 'thread-1', 'label_ids' => ['SENT']]);
        $this->app->make(MessageImporter::class)->import($this->mailbox, $sent);

        Event::assertDispatched(GmailReplyDetected::class, fn (GmailReplyDetected $e): bool => $e->gmailThreadId === 'thread-1' && $e->mailboxId === $this->boxId());

        $draft = $this->draft();
        $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());
        Event::assertDispatchedTimes(GmailReplyDetected::class, 1);
    }

    /**
     * docs/mail/08 Abschnitt 11.2 Punkt 8: Freigabe und Versand verweigern auf Service-Ebene ohne aktuellen
     * Reauth-Zeitstempel (null oder älter als hub.security.totp.fresh_minutes), unabhängig von der Middleware.
     */
    public function test_approve_and_send_refuse_without_fresh_reauthentication(): void
    {
        $service = $this->app->make(DraftService::class);
        $draft = $service->createReplyDraft($this->original, 'Wir kümmern uns.', null, $this->alias, [], $this->user);

        foreach ([null, CarbonImmutable::now()->subMinutes(60)] as $stale) {
            try {
                $service->approve($draft, $this->approver, $stale);
                $this->fail('Freigabe ohne frische Re-Authentifizierung muss abgelehnt werden.');
            } catch (DraftApprovalRefusedException $exception) {
                $this->assertSame('reauth_missing', $exception->reason);
            }
        }

        $this->assertNull($draft->refresh()->getAttribute('approved_by'));
        $this->assertSame(2, AuditLog::query()->where('action', 'mail.draft.approval_reauth_missing')->count());

        $reauth = CarbonImmutable::now()->subMinutes(5)->startOfSecond();
        $service->approve($draft, $this->approver, $reauth);
        $draft->refresh();
        $this->assertSame((int) $this->approver->getKey(), (int) $draft->getAttribute('approved_by'));
        $this->assertTrue($reauth->equalTo($draft->getAttribute('approval_reauth_confirmed_at')));

        // Versand: gleiche Regel für die sendende Person, kein Aufruf der Gegenstelle.
        $this->assertRefused($draft, $this->user, 'reauth_missing');
        $this->assertSame([], $this->gmail->calls('sendDraft'));
        $this->assertTrue(AuditLog::query()->where('action', 'mail.draft.send_refused')->exists());

        $this->assertSame('sent_verified', $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now())->getAttribute('status'));
    }

    public function test_send_requires_approval_by_a_second_person(): void
    {
        $service = $this->app->make(DraftService::class);
        $draft = $service->createReplyDraft($this->original, 'Wir kümmern uns.', null, $this->alias, [], $this->user);

        $this->assertRefused($draft, $this->user, 'approval_missing');

        try {
            $service->approve($draft, $this->user, CarbonImmutable::now());
            $this->fail('Der Autor darf nicht selbst freigeben.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Vier-Augen', $exception->getMessage());
        }

        $draft->forceFill(['approved_by' => $this->user->getKey(), 'approved_at' => now()])->save();
        $this->assertRefused($draft->refresh(), $this->user, 'approval_missing');

        $service->approve($draft, $this->approver, CarbonImmutable::now());
        $this->assertSame((int) $this->approver->getKey(), (int) $draft->refresh()->getAttribute('approved_by'));

        // Inhaltsänderung nach Freigabe: Freigabe gilt nicht mehr.
        $service->updateDraft($draft, ['body_text' => 'Neuer Text'], [], $this->user);
        $this->assertNull($draft->refresh()->getAttribute('approved_by'));
        $this->assertRefused($draft, $this->user, 'approval_missing');
        $this->assertSame([], $this->gmail->calls('sendDraft'));

        $service->approve($draft, $this->approver, CarbonImmutable::now());
        $this->assertSame('sent_verified', $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now())->getAttribute('status'));
    }

    public function test_concurrent_send_requests_claim_the_draft_only_once(): void
    {
        $draft = $this->draft();
        $stale = MailDraft::query()->findOrFail($draft->getKey());

        // Race-Fenster: die erste Anfrage hat alle Prüfungen bestanden und den Entwurf gerade übernommen (sent_requested),
        // drafts.send steht noch aus; der Gmail-Entwurf existiert also noch und refreshRemoteState liefert ok.
        MailDraft::query()->whereKey($draft->getKey())->update(['status' => SendService::STATUS_SENT_REQUESTED]);

        $this->assertSame(DraftService::STATUS_PUSHED, $stale->getAttribute('status'), 'Zweite Anfrage arbeitet mit veraltetem Stand.');
        $this->assertRefused($stale, $this->user, 'already_requested');
        $this->assertSame([], $this->gmail->calls('sendDraft'), 'Kein zweiter drafts.send.');
        $this->assertSame(0, SendReconciliation::query()->count(), 'Kein zweiter Versandabgleich.');
        $this->assertSame(SendService::STATUS_SENT_REQUESTED, $draft->refresh()->getAttribute('status'));
    }

    public function test_verified_send_stays_pending_when_sent_message_is_not_yet_importable(): void
    {
        config()->set('hub.gmail.send.reconcile_attempts', 3);
        $draft = $this->draft();
        // listSent findet die Nachricht, getMessage(metadata) bestätigt sie, der Import (getMessage raw) liefert 404.
        $this->gmail->failNext('getMessage', new MailRemoteException('Fake: noch nicht abrufbar', 'gmail', 404, null), skip: 1);

        $result = $this->app->make(SendService::class)->send($draft, $this->user, CarbonImmutable::now());

        $this->assertSame(SendReconciliationService::UNVERIFIED, $result->getAttribute('send_verification'), 'Ohne importierte Nachricht kein sent_verified.');
        $this->assertNull($result->getAttribute('sent_message_id'));
        $this->assertSame('pending', SendReconciliation::query()->firstOrFail()->getAttribute('result'));

        SendReconciliation::query()->update(['next_check_at' => now()->subMinute()]);
        $this->app->make(SendReconciliationService::class)->processDue();
        $this->assertSame('sent_verified', $draft->refresh()->getAttribute('status'));
        $this->assertNotNull($draft->getAttribute('sent_message_id'), 'Beim nächsten Lauf importiert und verknüpft.');
    }

    public function test_reimport_of_known_message_fires_no_second_reply_detection(): void
    {
        Event::fake([GmailReplyDetected::class]);
        $sent = $this->gmail->seedMessage($this->boxId(), ['from' => 'verwaltung@muellerhv.de', 'to' => 'mieter@example.com', 'subject' => 'Re: Heizung', 'text' => 'Direkt.', 'thread_id' => 'thread-1', 'label_ids' => ['SENT']]);
        $importer = $this->app->make(MessageImporter::class);

        $importer->import($this->mailbox, $sent);
        $importer->import($this->mailbox, $sent);
        $importer->import($this->mailbox, $sent);

        Event::assertDispatchedTimes(GmailReplyDetected::class, 1);
    }

    private function draft(): MailDraft
    {
        $service = $this->app->make(DraftService::class);
        $draft = $service->createReplyDraft($this->original, 'Wir kümmern uns.', null, $this->alias, [], $this->user);

        return $service->approve($draft, $this->approver, CarbonImmutable::now());
    }

    private function assertRefused(MailDraft $draft, User $user, string $reason): void
    {
        try {
            $this->app->make(SendService::class)->send($draft, $user, $reason === 'reauth_missing' ? null : CarbonImmutable::now());
            $this->fail('Ablehnung erwartet: '.$reason);
        } catch (SendRefusedException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function boxId(): int
    {
        return (int) $this->mailbox->getKey();
    }
}
