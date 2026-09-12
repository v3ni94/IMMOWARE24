<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Http\Controllers\PushController;
use App\Modules\Gmail\Jobs\HistorySyncJob;
use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\Gmail\Models\PushEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Gmail\SignedIdToken;
use Tests\TestCase;

final class PushEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const string URL = 'https://mail.muellerhv.de/mail/gmail/push';

    private SignedIdToken $tokens;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokens = new SignedIdToken;
        config()->set('hub.gmail.push.topic', 'projects/hvm/topics/gmail');
        config()->set('hub.gmail.push.audience', self::URL);
        config()->set('hub.gmail.push.service_account_email', 'pubsub-push@projekt.iam.gserviceaccount.com');
        Http::fake(['https://www.googleapis.com/oauth2/v3/certs' => Http::response($this->tokens->jwks())]);
    }

    public function test_valid_push_is_stored_and_dispatches_history_sync_before_answering_204(): void
    {
        Bus::fake([HistorySyncJob::class]);
        $mailbox = $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);
        MailSyncState::query()->create(['mailbox_id' => $mailbox->getKey(), 'watch_status' => 'requested', 'last_history_id' => '100']);

        $this->push('verwaltung@muellerhv.de', '4711', 'msg-1')->assertNoContent();

        Bus::assertDispatched(HistorySyncJob::class, static fn (HistorySyncJob $job): bool => $job->mailboxId === (int) $mailbox->getKey());
        $event = PushEvent::query()->firstOrFail();
        $this->assertSame('queued', $event->getAttribute('outcome'));
        $this->assertSame('ok', $event->getAttribute('auth_result'));
        $this->assertSame('4711', $event->getAttribute('history_id'));
        $this->assertSame('active', MailSyncState::query()->firstOrFail()->getAttribute('watch_status'), 'Erster Push bestätigt den Watch.');
    }

    public function test_duplicate_push_has_no_effect(): void
    {
        Bus::fake([HistorySyncJob::class]);
        $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);

        $this->push('verwaltung@muellerhv.de', '4711', 'dup-1')->assertNoContent();
        $this->push('verwaltung@muellerhv.de', '4712', 'dup-1')->assertNoContent();

        Bus::assertDispatchedTimes(HistorySyncJob::class, 1);
        $this->assertSame(1, PushEvent::query()->count());
    }

    public function test_redelivery_after_failed_dispatch_queues_history_sync_instead_of_being_a_duplicate(): void
    {
        Bus::fake([HistorySyncJob::class]);
        $mailbox = $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);
        // Erste Zustellung ist beim Einplanen gescheitert (Redis nicht erreichbar), Pub/Sub stellt erneut zu.
        PushEvent::query()->create(['mailbox_id' => $mailbox->getKey(), 'pubsub_message_id' => 'retry-1', 'email_address' => 'verwaltung@muellerhv.de', 'history_id' => '4711', 'received_at' => now(), 'auth_result' => 'ok', 'outcome' => 'failed']);

        $this->push('verwaltung@muellerhv.de', '4711', 'retry-1')->assertNoContent();

        Bus::assertDispatchedTimes(HistorySyncJob::class, 1);
        $this->assertSame(1, PushEvent::query()->count());
        $this->assertSame('queued', PushEvent::query()->firstOrFail()->getAttribute('outcome'));
    }

    public function test_controller_refuses_payload_without_middleware_result(): void
    {
        Bus::fake([HistorySyncJob::class]);
        $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true]);
        $request = Request::create(self::URL, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($this->payload('verwaltung@muellerhv.de', '1', 'no-mw-1'), JSON_THROW_ON_ERROR));

        try {
            $this->app->make(PushController::class)($request);
            $this->fail('Ohne Prüfergebnis der Middleware darf nichts verarbeitet werden.');
        } catch (HttpException $exception) {
            $this->assertSame(401, $exception->getStatusCode());
        }

        Bus::assertNothingDispatched();
        $this->assertSame(0, PushEvent::query()->count());
    }

    public function test_unknown_mailbox_answers_204_and_is_logged_as_ignored(): void
    {
        Bus::fake([HistorySyncJob::class]);

        $this->push('fremd@example.com', '1', 'unk-1')->assertNoContent();

        Bus::assertNothingDispatched();
        $this->assertSame('ignored', PushEvent::query()->firstOrFail()->getAttribute('outcome'));
        $this->assertNull(PushEvent::query()->firstOrFail()->getAttribute('mailbox_id'));
    }

    public function test_rejects_missing_token_wrong_audience_and_wrong_host(): void
    {
        Bus::fake([HistorySyncJob::class]);
        $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de']);
        $payload = $this->payload('verwaltung@muellerhv.de', '1', 'rej-1');

        $this->postJson(self::URL, $payload)->assertStatus(401);
        $this->postJson(self::URL, $payload, ['Authorization' => 'Bearer '.$this->tokens->issue(['aud' => 'https://anderer.example/push'])])->assertStatus(403);
        $this->postJson('https://immoware.muellerhv.de/mail/gmail/push', $payload, ['Authorization' => 'Bearer '.$this->tokens->issue()])->assertNotFound();
        $this->postJson(self::URL, ['message' => ['messageId' => 'x']], ['Authorization' => 'Bearer '.$this->tokens->issue()])->assertStatus(400);

        Bus::assertNothingDispatched();
        $this->assertSame(0, PushEvent::query()->count());
    }

    public function test_endpoint_is_not_configured_without_topic(): void
    {
        config()->set('hub.gmail.push.topic', null);

        $this->postJson(self::URL, $this->payload('a@b.de', '1', 'nc-1'), ['Authorization' => 'Bearer '.$this->tokens->issue()])->assertNotFound();
    }

    public function test_path_token_is_enforced_when_configured(): void
    {
        config()->set('hub.gmail.push.path_token', 'geheim');
        $this->createMailbox(attributes: ['email_address' => 'verwaltung@muellerhv.de']);

        $this->postJson(self::URL, $this->payload('verwaltung@muellerhv.de', '1', 'pt-1'), ['Authorization' => 'Bearer '.$this->tokens->issue()])->assertStatus(403);
        $this->postJson(self::URL.'/geheim', $this->payload('verwaltung@muellerhv.de', '1', 'pt-2'), ['Authorization' => 'Bearer '.$this->tokens->issue()])->assertNoContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $email, string $historyId, string $messageId): array
    {
        return [
            'message' => [
                'data' => base64_encode(json_encode(['emailAddress' => $email, 'historyId' => $historyId], JSON_THROW_ON_ERROR)),
                'messageId' => $messageId,
                'publishTime' => '2026-09-12T10:00:00.000Z',
            ],
            'subscription' => 'projects/hvm/subscriptions/gmail-push',
        ];
    }

    private function push(string $email, string $historyId, string $messageId): TestResponse
    {
        return $this->postJson(self::URL, $this->payload($email, $historyId, $messageId), ['Authorization' => 'Bearer '.$this->tokens->issue()]);
    }
}
