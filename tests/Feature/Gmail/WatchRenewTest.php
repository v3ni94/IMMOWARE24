<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Contracts\GmailProviderInterface;
use App\Modules\Gmail\Events\WatchExpiringSoon;
use App\Modules\Gmail\Jobs\WatchRenewJob;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Services\Sync\WatchService;
use App\Modules\Gmail\Testing\FakeGmailProvider;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class WatchRenewTest extends TestCase
{
    use RefreshDatabase;

    private FakeGmailProvider $gmail;

    private Mailbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.gmail.push.topic', 'projects/hvm/topics/gmail');
        $this->gmail = $this->app->make(GmailProviderInterface::class);
        $this->box = $this->createMailbox(attributes: ['status' => 'active', 'import_enabled' => true]);
    }

    public function test_renew_stores_expiration_as_requested_not_active(): void
    {
        Event::fake([WatchExpiringSoon::class]);

        WatchRenewJob::dispatch();

        $state = MailSyncState::query()->where('mailbox_id', $this->box->getKey())->firstOrFail();
        $this->assertSame('requested', $state->getAttribute('watch_status'), 'HTTP 200 auf watch ist kein aktiver Watch.');
        $this->assertTrue($state->getAttribute('watch_expiration')->greaterThan(now()->addDays(6)));
        $this->assertNotNull($state->getAttribute('last_history_id'));
        $this->assertSame(['INBOX', 'SENT', 'DRAFT'], $this->gmail->watchState((int) $this->box->getKey())['label_ids']);
        Event::assertNotDispatched(WatchExpiringSoon::class);
    }

    public function test_alert_when_expiration_is_below_24_hours_and_only_once(): void
    {
        Event::fake([WatchExpiringSoon::class]);
        $service = $this->app->make(WatchService::class);
        $state = $service->renew($this->box);
        $state->forceFill(['watch_expiration' => now()->addHours(5)])->save();

        $this->assertTrue($service->alertIfExpiring($this->box));
        $this->assertFalse($service->alertIfExpiring($this->box), 'Nur ein Alarm je Ablauf.');
        Event::assertDispatchedTimes(WatchExpiringSoon::class, 1);
        $this->assertNotNull($state->refresh()->getAttribute('watch_alerted_at'));

        $state->forceFill(['watch_expiration' => now()->subHour(), 'watch_alerted_at' => null])->save();
        $this->assertTrue($service->alertIfExpiring($this->box));
        $this->assertSame('expired', $state->refresh()->getAttribute('watch_status'));
    }

    public function test_failed_renewal_keeps_old_expiration_and_alerts(): void
    {
        Event::fake([WatchExpiringSoon::class]);
        $service = $this->app->make(WatchService::class);
        $service->renew($this->box);
        $before = MailSyncState::query()->firstOrFail()->getAttribute('watch_expiration');

        $this->gmail->failNext('watch', new MailRemoteException('Fake: 503', 'gmail', 503, null));

        try {
            $service->renew($this->box);
            $this->fail('Fehler erwartet.');
        } catch (MailRemoteException) {
            $state = MailSyncState::query()->firstOrFail();
            $this->assertSame('failed', $state->getAttribute('watch_status'));
            $this->assertEquals($before, $state->getAttribute('watch_expiration'));
            Event::assertDispatched(WatchExpiringSoon::class, static fn (WatchExpiringSoon $e): bool => str_starts_with($e->reason, 'renew_failed'));
        }
    }

    public function test_without_topic_no_watch_is_requested_and_schedule_is_registered(): void
    {
        config()->set('hub.gmail.push.topic', null);
        WatchRenewJob::dispatch((int) $this->box->getKey());
        $this->assertSame([], $this->gmail->calls('watch'));

        $names = array_map(static fn ($event): string => (string) $event->description, $this->app->make(Schedule::class)->events());
        $this->assertContains('mail-gmail-watch-renew', $names);
        $this->assertContains('mail-gmail-reconcile', $names);
        $this->assertContains('mail-gmail-send-reconcile', $names);
    }
}
