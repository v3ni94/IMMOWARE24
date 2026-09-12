<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Jobs\AliasSyncJob;
use App\Modules\Gmail\Models\PushEvent;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Models\MailboxAlias;
use App\Modules\Sync\Models\DlqItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Alias-Abgleich über sendAs.list (FakeGmailProvider) und Aufbewahrung der Push-Ereignisse (mail:push:prune).
 */
final class AliasSyncTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailProvider $gmail;

    private Mailbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gmail = $this->app->make(GmailProviderInterface::class);
        $this->box = $this->createMailbox(attributes: ['status' => 'active', 'legal_entity_code' => 'MHAG', 'oauth_refresh_token' => 'rt', 'email_address' => 'vorstand@mueller-holding.ag']);
    }

    public function test_only_verified_aliases_and_primary_address_are_created_with_mailbox_entity(): void
    {
        $this->gmail->seedSendAs((int) $this->box->getKey(), [
            ['send_as_email' => 'vorstand@mueller-holding.ag', 'display_name' => 'Müller Holding AG', 'reply_to' => null, 'is_default' => true, 'is_primary' => true, 'verification_status' => 'unknown'],
            ['send_as_email' => 'invest@mueller-holding.ag', 'display_name' => 'Investoren', 'reply_to' => 'vorstand@mueller-holding.ag', 'is_default' => false, 'is_primary' => false, 'verification_status' => 'accepted'],
            ['send_as_email' => 'neu@mueller-holding.ag', 'display_name' => null, 'reply_to' => null, 'is_default' => false, 'is_primary' => false, 'verification_status' => 'pending'],
        ]);

        AliasSyncJob::dispatch((int) $this->box->getKey());

        $aliases = MailboxAlias::query()->where('mailbox_id', $this->box->getKey())->orderBy('send_as_email')->get();
        $this->assertSame(['invest@mueller-holding.ag', 'vorstand@mueller-holding.ag'], $aliases->pluck('send_as_email')->all(), 'Nicht verifizierter Alias wird nicht angelegt.');
        $this->assertSame(['MHAG', 'MHAG'], $aliases->pluck('legal_entity_code')->all(), 'Gesellschaft aus dem Postfach.');
        $this->assertSame(['accepted', 'accepted'], $aliases->pluck('verification_status')->all());
        $primary = $aliases->firstWhere('send_as_email', 'vorstand@mueller-holding.ag');
        $this->assertTrue($primary->getAttribute('is_primary'));
        $this->assertTrue($primary->getAttribute('is_default'));
        $this->assertNotNull($primary->getAttribute('synced_at'));
        $this->assertSame('vorstand@mueller-holding.ag', $aliases->firstWhere('send_as_email', 'invest@mueller-holding.ag')->getAttribute('reply_to'));
        $this->assertNull($primary->getAttribute('signature_key'), 'Signatur kommt aus dem CI-Skill, nicht aus Gmail.');
    }

    public function test_existing_aliases_keep_entity_lose_verification_and_missing_ones_are_marked_not_deleted(): void
    {
        MailboxAlias::query()->create(['mailbox_id' => $this->box->getKey(), 'send_as_email' => 'invest@mueller-holding.ag', 'legal_entity_code' => 'HVM', 'verification_status' => 'accepted', 'is_default' => true, 'signature_key' => 'mhag-standard']);
        MailboxAlias::query()->create(['mailbox_id' => $this->box->getKey(), 'send_as_email' => 'alt@mueller-holding.ag', 'legal_entity_code' => 'MHAG', 'verification_status' => 'accepted']);

        $this->gmail->seedSendAs((int) $this->box->getKey(), [
            ['send_as_email' => 'INVEST@mueller-holding.ag', 'display_name' => 'Investoren', 'reply_to' => null, 'is_default' => false, 'is_primary' => false, 'verification_status' => 'pending'],
        ]);

        $stats = (new AliasSyncJob((int) $this->box->getKey()))->apply($this->box, $this->gmail->listSendAs((int) $this->box->getKey()));

        $this->assertSame(['created' => 0, 'updated' => 1, 'missing' => 1, 'skipped' => 0], $stats);
        $invest = MailboxAlias::query()->where('send_as_email', 'invest@mueller-holding.ag')->firstOrFail();
        $this->assertSame('pending', $invest->getAttribute('verification_status'), 'Status aus Gmail übernommen, SendService lehnt ab.');
        $this->assertSame('HVM', $invest->getAttribute('legal_entity_code'), 'Gesellschaft wird nie überschrieben.');
        $this->assertSame('mhag-standard', $invest->getAttribute('signature_key'));
        $this->assertFalse($invest->getAttribute('is_default'));

        $alt = MailboxAlias::query()->where('send_as_email', 'alt@mueller-holding.ag')->firstOrFail();
        $this->assertSame('missing', $alt->getAttribute('verification_status'), 'Kein Hard Delete, nur Kennzeichnung.');
        $this->assertSame(2, MailboxAlias::query()->count());
    }

    public function test_job_without_mailbox_fans_out_only_to_connected_mailboxes_and_is_scheduled_daily(): void
    {
        Bus::fake([AliasSyncJob::class]);
        $this->createMailbox(attributes: ['status' => 'configured', 'oauth_refresh_token' => null]);
        $this->createMailbox(attributes: ['status' => 'revoked', 'oauth_refresh_token' => 'rt']);

        (new AliasSyncJob)->handle($this->gmail);

        Bus::assertDispatchedTimes(AliasSyncJob::class, 1);
        Bus::assertDispatched(AliasSyncJob::class, fn (AliasSyncJob $job): bool => $job->mailboxId === (int) $this->box->getKey());

        $names = array_map(static fn ($event): string => (string) $event->description, $this->app->make(Schedule::class)->events());
        $this->assertContains('mail-gmail-alias-sync', $names);
        $this->assertContains('mail-gmail-push-prune', $names);
    }

    public function test_remote_error_is_not_a_success_and_final_failure_lands_in_dlq(): void
    {
        $this->gmail->failNext('listSendAs', new MailRemoteException('Scope fehlt', 'gmail', 403, 'insufficientPermissions'));

        $job = new AliasSyncJob((int) $this->box->getKey());
        $job->handle($this->gmail);
        $this->assertSame(0, MailboxAlias::query()->count());

        $job->failed(new MailRemoteException('Scope fehlt', 'gmail', 403, 'insufficientPermissions'));
        $this->assertSame(1, DlqItem::query()->count());
    }

    public function test_push_prune_command_removes_only_events_older_than_retention(): void
    {
        config()->set('hub.gmail.push.dedup_retention_days', 30);
        PushEvent::query()->create(['pubsub_message_id' => 'alt', 'email_address' => 'a@muellerhv.de', 'history_id' => '1', 'received_at' => now()->subDays(31), 'auth_result' => 'ok']);
        PushEvent::query()->create(['pubsub_message_id' => 'neu', 'email_address' => 'a@muellerhv.de', 'history_id' => '2', 'received_at' => now()->subDays(29), 'auth_result' => 'ok']);

        $this->artisan('mail:push:prune', ['--chunk' => 1])
            ->expectsOutputToContain('1 Push-Ereignisse älter als 30 Tage entfernt.')
            ->assertSuccessful();

        $this->assertSame(['neu'], PushEvent::query()->pluck('pubsub_message_id')->all());
    }
}
