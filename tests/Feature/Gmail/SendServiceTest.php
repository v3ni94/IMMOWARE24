<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\GmailReplyDetected;
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
use App\Modules\Security\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class SendServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailProvider $gmail;

    private User $user;

    private MailboxAlias $alias;

    private MailMessage $original;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.mail.flags.gmail_drafts', true);
        config()->set('hub.mail.flags.gmail_send', true);
        $this->gmail = $this->app->make(GmailProviderInterface::class);
        $this->user = $this->actingAsMailRole('lead');
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
        $this->app->make(SendService::class)->send($draft, $this->user);
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

        $result = $this->app->make(SendService::class)->send($draft, $this->user);

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

        $result = $this->app->make(SendService::class)->send($draft, $this->user);

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

        $result = $this->app->make(SendService::class)->send($draft, $this->user);
        $this->assertSame(SendReconciliationService::UNVERIFIED, $result->getAttribute('send_verification'));

        $reconciliation = SendReconciliation::query()->firstOrFail();
        $reconciliation->forceFill(['next_check_at' => now()->subMinute()])->save();
        $this->assertSame(1, $this->app->make(SendReconciliationService::class)->processDue());
        $this->assertSame('sent_verified', $draft->refresh()->getAttribute('status'));
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
        $this->app->make(SendService::class)->send($draft, $this->user);
        Event::assertDispatchedTimes(GmailReplyDetected::class, 1);
    }

    private function draft(): MailDraft
    {
        return $this->app->make(DraftService::class)->createReplyDraft($this->original, 'Wir kümmern uns.', null, $this->alias, [], $this->user);
    }

    private function assertRefused(MailDraft $draft, User $user, string $reason): void
    {
        try {
            $this->app->make(SendService::class)->send($draft, $user);
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
