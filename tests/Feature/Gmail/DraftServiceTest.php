<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Exceptions\DraftConflictException;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Services\DraftService;
use App\Modules\Gmail\Services\Sync\MessageImporter;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DraftServiceTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailProvider $gmail;

    private Mailbox $box;

    private MailboxAlias $alias;

    private MailMessage $original;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.mail.flags.gmail_drafts', true);
        $this->gmail = $this->app->make(GmailProviderInterface::class);
        $this->box = $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);
        $this->alias = MailboxAlias::query()->create([
            'mailbox_id' => $this->box->getKey(), 'send_as_email' => 'verwaltung@muellerhv.de', 'display_name' => 'Hausverwaltung Müller GmbH',
            'legal_entity_code' => 'HVM', 'verification_status' => 'accepted', 'is_primary' => true, 'is_default' => true,
        ]);
        $id = $this->gmail->seedMessage((int) $this->box->getKey(), ['from' => 'Mieter <mieter@example.com>', 'subject' => 'Wasserschaden Küche', 'text' => 'Es tropft.', 'rfc_message_id' => '<orig@example.com>', 'headers' => ['References' => '<root@example.com>']]);
        $this->original = $this->app->make(MessageImporter::class)->import($this->box, $id) ?? throw new \RuntimeException('Import fehlgeschlagen.');
    }

    public function test_create_reply_draft_builds_threaded_mime_and_pushes_to_gmail(): void
    {
        $draft = $this->service()->createReplyDraft($this->original, "Guten Tag,\nwir kümmern uns.", '<p>Guten Tag,<br>wir kümmern uns.</p>', $this->alias, [
            ['filename' => 'Bestätigung.pdf', 'mime_type' => 'application/pdf', 'content' => '%PDF'],
        ]);

        $this->assertSame(DraftService::STATUS_PUSHED, $draft->getAttribute('status'));
        $this->assertSame(1, (int) $draft->getAttribute('revision'));
        $this->assertNotNull($draft->getAttribute('gmail_draft_id'));
        $this->assertNotNull($draft->getAttribute('content_hash'));
        $this->assertSame('unknown', $draft->getAttribute('delivery_status'));
        $this->assertSame(['mieter@example.com'], $draft->getAttribute('to_json'));
        $this->assertSame('Re: Wasserschaden Küche', $draft->getAttribute('subject'));
        $this->assertSame('fake-thread-'.$this->original->getAttribute('gmail_message_id'), $draft->getAttribute('gmail_thread_id'));

        $remote = $this->gmail->drafts((int) $this->box->getKey())[$draft->getAttribute('gmail_draft_id')];
        $raw = (string) $remote['mime']['raw'];
        $this->assertStringContainsString('In-Reply-To: <orig@example.com>', $raw);
        $this->assertStringContainsString('References: <root@example.com>', $raw);
        $this->assertStringContainsString('<orig@example.com>', $raw);
        $this->assertStringContainsString('Message-ID: '.$draft->getAttribute('rfc_message_id'), $raw);
        $this->assertStringContainsString('Content-Type: multipart/mixed', $raw);
        $this->assertSame($draft->getAttribute('gmail_thread_id'), $remote['mime']['thread_id']);
    }

    public function test_deleted_gmail_draft_is_missing_and_never_sent(): void
    {
        $draft = $this->service()->createReplyDraft($this->original, 'Text', null, $this->alias);
        $this->gmail->removeDraft((int) $this->box->getKey(), (string) $draft->getAttribute('gmail_draft_id'));

        try {
            $this->service()->updateDraft($draft, ['body_text' => 'Neuer Text']);
            $this->fail('Konflikt erwartet.');
        } catch (DraftConflictException $exception) {
            $this->assertSame(DraftService::STATUS_MISSING, $exception->state);
        }

        $draft->refresh();
        $this->assertSame(DraftService::STATUS_MISSING, $draft->getAttribute('status'));
        $this->assertSame('Text', $draft->getAttribute('body_text'), 'Kein Überschreiben.');
        $this->assertNull($draft->getAttribute('send_verification'));
        $this->assertNull($draft->getAttribute('sent_message_id'));
        $this->assertNotSame('sent_verified', $draft->getAttribute('status'));
    }

    public function test_externally_changed_gmail_draft_is_a_conflict_without_overwrite(): void
    {
        $draft = $this->service()->createReplyDraft($this->original, 'Ursprung', null, $this->alias);
        $this->gmail->touchDraft((int) $this->box->getKey(), (string) $draft->getAttribute('gmail_draft_id'), "From: verwaltung@muellerhv.de\r\nTo: mieter@example.com\r\nSubject: Re: Wasserschaden Küche\r\n\r\nIn Gmail geändert.");

        $this->expectException(DraftConflictException::class);

        try {
            $this->service()->updateDraft($draft, ['body_text' => 'Hub-Änderung']);
        } finally {
            $draft->refresh();
            $this->assertSame(DraftService::STATUS_CONFLICT, $draft->getAttribute('status'));
            $this->assertSame('Ursprung', $draft->getAttribute('body_text'));
            $this->assertSame(1, $this->gmail->drafts((int) $this->box->getKey())[$draft->getAttribute('gmail_draft_id')]['updated'] - 1, 'Keine updateDraft-Übertragung.');
        }
    }

    public function test_update_increments_revision_and_updates_gmail_draft(): void
    {
        $draft = $this->service()->createReplyDraft($this->original, 'Ursprung', null, $this->alias);

        $updated = $this->service()->updateDraft($draft, ['body_text' => 'Zweite Fassung', 'cc' => ['eigentuemer@example.com']]);

        $this->assertSame(2, (int) $updated->getAttribute('revision'));
        $this->assertSame(DraftService::STATUS_PUSHED, $updated->getAttribute('status'));
        $this->assertCount(1, $this->gmail->calls('updateDraft'));
        $this->assertStringContainsString('Zweite Fassung', (string) $this->gmail->drafts((int) $this->box->getKey())[$draft->getAttribute('gmail_draft_id')]['mime']['raw']);
    }

    public function test_stale_hub_revision_is_rejected_instead_of_overwriting(): void
    {
        $draft = $this->service()->createReplyDraft($this->original, 'Fassung A', null, $this->alias);
        $this->assertSame(1, (int) $draft->getAttribute('revision'));

        // Bearbeiter 1 speichert auf Basis von Revision 1.
        $this->service()->updateDraft($draft, ['body_text' => 'Fassung B'], [], null, expectedRevision: 1);
        $this->assertSame(2, (int) $draft->refresh()->getAttribute('revision'));

        // Bearbeiter 2 hatte ebenfalls Revision 1 offen: kein last write wins.
        try {
            $this->service()->updateDraft(MailMessage::query()->exists() ? $draft : $draft, ['body_text' => 'Fassung C'], [], null, expectedRevision: 1);
            $this->fail('Veraltete Revision muss abgelehnt werden.');
        } catch (DraftConflictException $exception) {
            $this->assertSame(DraftService::STATUS_STALE_REVISION, $exception->state);
        }

        $draft->refresh();
        $this->assertSame('Fassung B', $draft->getAttribute('body_text'));
        $this->assertSame(2, (int) $draft->getAttribute('revision'));
        $this->assertCount(1, $this->gmail->calls('updateDraft'), 'Keine zweite Übertragung nach Gmail.');
    }

    public function test_alias_of_other_entity_is_rejected_and_flag_off_keeps_draft_local(): void
    {
        $foreign = MailboxAlias::query()->create(['mailbox_id' => $this->box->getKey(), 'send_as_email' => 'holding@mueller-holding.ag', 'legal_entity_code' => 'MHAG', 'verification_status' => 'accepted']);

        try {
            $this->service()->createReplyDraft($this->original, 'x', null, $foreign);
            $this->fail('Gesellschaften dürfen nicht vermischt werden.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Gesellschaften', $exception->getMessage());
        }

        config()->set('hub.mail.flags.gmail_drafts', false);
        $draft = $this->service()->createReplyDraft($this->original, 'lokal', null, $this->alias);

        $this->assertSame(DraftService::STATUS_LOCAL, $draft->getAttribute('status'));
        $this->assertNull($draft->getAttribute('gmail_draft_id'));
        $this->assertSame([], $this->gmail->calls('createDraft'));
    }

    private function service(): DraftService
    {
        return $this->app->make(DraftService::class);
    }
}
