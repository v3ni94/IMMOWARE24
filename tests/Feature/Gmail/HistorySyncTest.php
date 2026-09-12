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
use App\Modules\Gmail\Services\Sync\MessageImporter;
use App\Modules\Gmail\Services\Sync\SyncStateService;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Sync\Models\DlqItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

    public function test_history_page_with_more_added_messages_than_limit_imports_all_before_cursor_advances(): void
    {
        InitialImportJob::dispatch($this->id());
        config()->set('hub.gmail.sync.max_messages_per_run', 2);

        foreach (range(1, 5) as $n) {
            $this->gmail->seedMessage($this->id(), ['subject' => 'Massenlauf '.$n]);
        }

        HistorySyncJob::dispatch($this->id());

        $this->assertSame(5, MailMessage::query()->count(), 'Kein Eintrag der Seite wird übersprungen.');
        $this->assertSame($this->gmail->currentHistoryId($this->id()), MailSyncState::query()->firstOrFail()->getAttribute('last_history_id'));
        $this->assertGreaterThanOrEqual(3, count($this->gmail->calls('listHistory')), 'Fortsetzung ab dem letzten vollständigen Eintrag.');
    }

    public function test_message_not_yet_retrievable_keeps_cursor_and_is_imported_on_retry(): void
    {
        InitialImportJob::dispatch($this->id());
        $before = MailSyncState::query()->firstOrFail()->getAttribute('last_history_id');
        $this->gmail->seedMessage($this->id(), ['subject' => 'Frisch']);
        $this->gmail->failNext('getMessage', new MailRemoteException('Fake: noch nicht da', 'gmail', 404, null));

        HistorySyncJob::dispatch($this->id());

        $this->assertSame(1, MailMessage::query()->count(), 'Wiederholung importiert die Nachricht.');
        $this->assertSame($this->gmail->currentHistoryId($this->id()), MailSyncState::query()->firstOrFail()->getAttribute('last_history_id'));
        $this->assertNotSame($before, MailSyncState::query()->firstOrFail()->getAttribute('last_history_id'));
        $this->assertCount(3, $this->gmail->calls('listHistory'), 'Übergabe nach Erstimport, erster Lauf, Wiederholung.');
    }

    public function test_message_missing_after_all_retries_is_given_up_without_blocking_the_cursor(): void
    {
        config()->set('hub.gmail.sync.history_missing_retries', 1);
        InitialImportJob::dispatch($this->id());
        $ghost = $this->gmail->seedMessage($this->id(), ['subject' => 'Weg']);
        $this->gmail->deleteMessage($this->id(), $ghost);

        HistorySyncJob::dispatch($this->id());

        $this->assertSame(0, MailMessage::query()->count());
        $this->assertSame($this->gmail->currentHistoryId($this->id()), MailSyncState::query()->firstOrFail()->getAttribute('last_history_id'), 'Cursor bleibt nicht dauerhaft stehen.');
    }

    public function test_busy_lock_does_not_release_loop_but_marks_pending_follow_up(): void
    {
        InitialImportJob::dispatchSync($this->id());
        Bus::fake([HistorySyncJob::class]);
        $lock = Cache::lock(HistorySyncJob::lockKey($this->id()), 60);
        $this->assertTrue($lock->get());

        $job = new HistorySyncJob($this->id());
        $job->handle($this->gmail, $this->app->make(MessageImporter::class), $this->app->make(SyncStateService::class));

        Bus::assertNotDispatched(HistorySyncJob::class);
        $this->assertNotNull(Cache::get(HistorySyncJob::pendingKey($this->id())), 'Push wird als ausstehend vermerkt.');
        $lock->release();

        // Der nächste Lauf holt den vermerkten Push als Folgelauf nach.
        (new HistorySyncJob($this->id()))->handle($this->gmail, $this->app->make(MessageImporter::class), $this->app->make(SyncStateService::class));
        Bus::assertDispatchedTimes(HistorySyncJob::class, 1);
        $this->assertNull(Cache::get(HistorySyncJob::pendingKey($this->id())));
    }

    public function test_second_404_during_running_resync_does_not_start_another_resync(): void
    {
        Bus::fake([FullResyncJob::class]);
        InitialImportJob::dispatchSync($this->id());
        MailSyncState::query()->update(['full_sync_started_at' => now(), 'full_sync_finished_at' => null]);
        $this->gmail->expireHistoryBefore($this->id(), (string) ((int) $this->gmail->currentHistoryId($this->id()) + 1));

        HistorySyncJob::dispatchSync($this->id());

        Bus::assertNotDispatched(FullResyncJob::class);
    }

    public function test_failed_initial_import_resets_state_so_it_can_restart_and_lands_in_dlq(): void
    {
        MailSyncState::query()->create(['mailbox_id' => $this->id(), 'watch_status' => 'none', 'full_sync_started_at' => now(), 'full_sync_cursor' => '3']);

        (new InitialImportJob($this->id()))->failed(new MailRemoteException('Fake: endgültig', 'gmail', 403, null));

        $state = MailSyncState::query()->firstOrFail();
        $this->assertNull($state->getAttribute('full_sync_started_at'));
        $this->assertNull($state->getAttribute('full_sync_cursor'));
        $this->assertSame('degraded', $this->box->refresh()->getAttribute('status'));
        $this->assertSame(1, DlqItem::query()->where('job_class', InitialImportJob::class)->where('entity_type', 'mail_gmail')->count());

        // Der nächste History-Abgleich startet den Erstimport erneut.
        $this->gmail->seedMessage($this->id(), ['subject' => 'Nach Re-Autorisierung']);
        HistorySyncJob::dispatch($this->id());
        $this->assertSame(1, MailMessage::query()->count());
        $this->assertNotNull(MailSyncState::query()->firstOrFail()->getAttribute('full_sync_finished_at'));
    }

    private function id(): int
    {
        return (int) $this->box->getKey();
    }
}
