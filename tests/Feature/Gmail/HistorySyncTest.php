<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\HistoryGapDetected;
use App\Modules\Gmail\Events\MessageCopyCandidateDetected;
use App\Modules\Gmail\Jobs\FullResyncJob;
use App\Modules\Gmail\Jobs\HistorySyncJob;
use App\Modules\Gmail\Jobs\InitialImportJob;
use App\Modules\Gmail\Jobs\ReconcileJob;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class HistorySyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailProvider $gmail;

    private Mailbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gmail = $this->app->make(GmailProviderInterface::class);
        $this->box = $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);
    }

    public function test_initial_import_remembers_history_id_before_import_and_hands_over_to_history_sync(): void
    {
        $this->gmail->seedMessage($this->id(), ['from' => 'Mieter <mieter@example.com>', 'subject' => 'Heizung', 'text' => 'Kalt.']);
        $this->gmail->seedMessage($this->id(), ['from' => 'eigentuemer@example.com', 'subject' => 'Abrechnung', 'text' => 'Frage.']);
        $expectedHistory = $this->gmail->currentHistoryId($this->id());

        InitialImportJob::dispatch($this->id());

        $this->assertSame(2, MailMessage::query()->count());
        $state = MailSyncState::query()->where('mailbox_id', $this->id())->firstOrFail();
        $this->assertSame($expectedHistory, $state->getAttribute('last_history_id'));
        $this->assertNotNull($state->getAttribute('full_sync_finished_at'));
        $this->assertNotEmpty($this->gmail->calls('getProfile'));
        $this->assertNotEmpty($this->gmail->calls('listHistory'), 'Nach dem Import läuft ein History-Abgleich.');

        $message = MailMessage::query()->where('subject', 'Heizung')->firstOrFail();
        $this->assertSame('mieter@example.com', $message->getAttribute('from_address'));
        $this->assertSame('Mieter', $message->getAttribute('from_name'));
        $this->assertSame('inbound', $message->getAttribute('direction'));
        $this->assertSame('Kalt.', trim((string) $message->getAttribute('body_text')));
        $this->assertFalse((bool) $message->getAttribute('is_read_in_gmail'));
        $this->assertSame('imported', $message->getAttribute('processing_status'), 'Gelesen ist nicht bearbeitet: nur technischer Status.');
    }

    public function test_history_sync_processes_added_deleted_and_label_changes_and_never_double_imports(): void
    {
        $first = $this->gmail->seedMessage($this->id(), ['subject' => 'Erste']);
        InitialImportJob::dispatch($this->id());

        $second = $this->gmail->seedMessage($this->id(), ['subject' => 'Zweite', 'label_ids' => ['INBOX', 'UNREAD']]);
        HistorySyncJob::dispatch($this->id());
        HistorySyncJob::dispatch($this->id());

        $this->assertSame(2, MailMessage::query()->count());
        $this->assertSame($this->gmail->currentHistoryId($this->id()), MailSyncState::query()->firstOrFail()->getAttribute('last_history_id'));

        $this->gmail->changeLabels($this->id(), $second, remove: ['UNREAD']);
        $this->gmail->deleteMessage($this->id(), $first);
        HistorySyncJob::dispatch($this->id());

        $this->assertTrue((bool) MailMessage::query()->where('gmail_message_id', $second)->firstOrFail()->getAttribute('is_read_in_gmail'));
        $this->assertSame(1, MailMessage::query()->count());
        $this->assertSame(2, MailMessage::query()->withTrashed()->count(), 'Nur weiche Löschung.');
    }

    public function test_invalid_history_id_triggers_full_resync_without_loss_or_deletion(): void
    {
        Event::fake([HistoryGapDetected::class]);
        $kept = $this->gmail->seedMessage($this->id(), ['subject' => 'Bleibt']);
        InitialImportJob::dispatch($this->id());

        $this->gmail->seedMessage($this->id(), ['subject' => 'Verpasst 1']);
        $this->gmail->seedMessage($this->id(), ['subject' => 'Verpasst 2', 'label_ids' => ['SENT']]);
        $this->gmail->expireHistoryBefore($this->id());

        HistorySyncJob::dispatch($this->id());

        $this->assertSame(3, MailMessage::query()->count());
        $this->assertSame(0, MailMessage::query()->onlyTrashed()->count(), 'Neuabgleich löscht nie.');
        $this->assertNotNull(MailMessage::query()->where('gmail_message_id', $kept)->first());
        $state = MailSyncState::query()->firstOrFail();
        $this->assertSame($this->gmail->currentHistoryId($this->id()), $state->getAttribute('last_history_id'));
        $this->assertSame(2, (int) $state->getAttribute('last_resync_gap_count'));
        $this->assertNotNull($state->getAttribute('last_full_resync_at'));
        Event::assertDispatched(HistoryGapDetected::class, static fn (HistoryGapDetected $e): bool => $e->gapCount === 2);

        // Danach läuft der inkrementelle Abgleich wieder ohne 404.
        $this->gmail->seedMessage($this->id(), ['subject' => 'Danach']);
        HistorySyncJob::dispatch($this->id());
        $this->assertSame(4, MailMessage::query()->count());
    }

    public function test_reconcile_imports_gaps_and_copy_in_other_mailbox_is_only_a_candidate(): void
    {
        Event::fake([MessageCopyCandidateDetected::class]);
        InitialImportJob::dispatch($this->id());
        $this->gmail->seedMessage($this->id(), ['subject' => 'Lücke', 'rfc_message_id' => '<gleich@example.com>']);

        ReconcileJob::dispatch($this->id());

        $this->assertSame(1, MailMessage::query()->count());
        $this->assertSame(1, (int) MailSyncState::query()->firstOrFail()->getAttribute('last_reconcile_gap_count'));

        $other = $this->createMailbox(team: $this->box->team, attributes: ['email_address' => 'buchhaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);
        $this->gmail->seedMessage((int) $other->getKey(), ['subject' => 'Lücke', 'rfc_message_id' => '<gleich@example.com>']);
        InitialImportJob::dispatch((int) $other->getKey());

        $this->assertSame(2, MailMessage::query()->count(), 'Kopie wird nicht zusammengeführt.');
        Event::assertDispatched(MessageCopyCandidateDetected::class);
    }

    public function test_full_resync_job_can_be_run_directly_and_skips_unauthorized_mailboxes(): void
    {
        $this->gmail->seedMessage($this->id(), ['subject' => 'A']);
        FullResyncJob::dispatch($this->id());
        $this->assertSame(1, MailMessage::query()->count());

        $this->box->forceFill(['status' => 'reauth_required'])->save();
        $this->gmail->seedMessage($this->id(), ['subject' => 'B']);
        HistorySyncJob::dispatch($this->id());
        $this->assertSame(1, MailMessage::query()->count(), 'Ohne Autorisierung kein Abgleich.');
    }

    public function test_paginated_initial_import_continues_after_lock_release_and_respects_limits(): void
    {
        config()->set('hub.gmail.sync.page_size', 1);
        config()->set('hub.gmail.sync.max_messages_per_run', 1);
        config()->set('hub.gmail.sync.initial_import_max_messages', 3);

        foreach (range(1, 5) as $n) {
            $this->gmail->seedMessage($this->id(), ['subject' => 'Seite '.$n]);
        }

        InitialImportJob::dispatch($this->id());

        $this->assertSame(3, MailMessage::query()->count(), 'Erstimport begrenzt auf initial_import_max_messages.');
        $this->assertCount(3, $this->gmail->calls('listMessages'));
        $this->assertCount(1, $this->gmail->calls('getProfile'), 'History-ID wird nur einmal vor dem Import gemerkt.');
        $this->assertNotNull(MailSyncState::query()->firstOrFail()->getAttribute('full_sync_finished_at'));
        $this->assertNull(MailSyncState::query()->firstOrFail()->getAttribute('full_sync_cursor'));
    }

    private function id(): int
    {
        return (int) $this->box->getKey();
    }
}
