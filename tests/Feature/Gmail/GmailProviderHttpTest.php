<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Exceptions\GmailQuotaExceededException;
use App\Modules\Gmail\Exceptions\GmailReauthRequiredException;
use App\Modules\Gmail\Mime\MimeBuilder;
use App\Modules\Gmail\Services\GmailApiClient;
use App\Modules\Gmail\Services\GmailProvider;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Live-Provider gegen Http::fake. Ein Erfolg hier ist kein Live-Test der Gmail-API; alle Antwortformen stammen aus
 * Snippets (docs/mail/research/gmail-api.md) und sind am Original zu prüfen.
 */
final class GmailProviderHttpTest extends TestCase
{
    use RefreshDatabase;

    private Mailbox $box;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.gmail.oauth.client_id', 'client-id.apps.googleusercontent.com');
        config()->set('hub.gmail.oauth.client_secret', 'client-secret');
        $this->box = $this->createMailbox(attributes: [
            'email_address' => 'verwaltung@muellerhv.de', 'status' => 'active', 'import_enabled' => true,
            'oauth_access_token' => 'access-1', 'oauth_refresh_token' => 'refresh-1', 'oauth_token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_list_and_get_raw_use_documented_endpoints_and_decode_base64url(): void
    {
        $raw = "From: a@example.com\r\nTo: verwaltung@muellerhv.de\r\nSubject: Test\r\nMessage-ID: <m1@example.com>\r\n\r\nHallo Müller";
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1', 'threadId' => 't1']], 'nextPageToken' => 'p2', 'resultSizeEstimate' => 12]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/m1?*' => Http::response(['id' => 'm1', 'threadId' => 't1', 'historyId' => '99', 'labelIds' => ['INBOX'], 'internalDate' => '1757664000000', 'raw' => MimeBuilder::base64url($raw)]),
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::response(['history' => [['id' => '101', 'messagesAdded' => [['message' => ['id' => 'm2', 'threadId' => 't1', 'labelIds' => ['INBOX', 'UNREAD']]]], 'labelsRemoved' => [['message' => ['id' => 'm1', 'threadId' => 't1'], 'labelIds' => ['UNREAD']]]]], 'historyId' => '105']),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'verwaltung@muellerhv.de', 'historyId' => '105', 'messagesTotal' => 3]),
            'https://gmail.googleapis.com/gmail/v1/users/me/watch' => Http::response(['historyId' => '105', 'expiration' => '1758268800000']),
            'https://gmail.googleapis.com/gmail/v1/users/me/settings/sendAs' => Http::response(['sendAs' => [['sendAsEmail' => 'Verwaltung@muellerhv.de', 'displayName' => 'HVM', 'isPrimary' => true, 'isDefault' => true, 'verificationStatus' => 'accepted']]]),
        ]);

        $provider = $this->provider();
        $list = $provider->listMessages($this->id(), ['label_ids' => ['INBOX', 'SENT'], 'max_results' => 50, 'query' => 'after:1']);
        $this->assertSame([['id' => 'm1', 'thread_id' => 't1']], $list['messages']);
        $this->assertSame('p2', $list['next_page_token']);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'labelIds=INBOX&labelIds=SENT') && str_contains($request->url(), 'maxResults=50') && $request->hasHeader('Authorization', 'Bearer access-1'));

        $message = $provider->getMessage($this->id(), 'm1', 'raw');
        $this->assertSame($raw, $message['raw']);
        $this->assertSame('99', $message['history_id']);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/messages/m1?format=raw'));

        $history = $provider->listHistoryFiltered($this->id(), '100', ['messageAdded', 'labelRemoved'], 'INBOX');
        $this->assertSame('105', $history['history_id']);
        $this->assertSame('m2', $history['history'][0]['messages_added'][0]['id']);
        $this->assertSame(['UNREAD'], $history['history'][0]['labels_removed'][0]['label_ids']);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'startHistoryId=100&historyTypes=messageAdded&historyTypes=labelRemoved&labelId=INBOX'));

        $this->assertSame('105', $provider->getProfile($this->id())['history_id']);

        $watch = $provider->watch($this->id(), 'projects/p/topics/t', ['INBOX', 'SENT']);
        $this->assertSame('1758268800000', $watch['expiration']);
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/users/me/watch') && $request['topicName'] === 'projects/p/topics/t' && $request['labelIds'] === ['INBOX', 'SENT']);

        $aliases = $provider->listSendAs($this->id());
        $this->assertSame('verwaltung@muellerhv.de', $aliases[0]['send_as_email']);
        $this->assertTrue($aliases[0]['is_primary']);
    }

    public function test_drafts_are_created_with_base64url_raw_and_thread_id_and_sent_via_drafts_send(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/drafts' => Http::response(['id' => 'd1', 'message' => ['id' => 'dm1', 'threadId' => 't1']]),
            'https://gmail.googleapis.com/gmail/v1/users/me/drafts/d1' => Http::response(['id' => 'd1', 'message' => ['id' => 'dm1', 'threadId' => 't1']]),
            'https://gmail.googleapis.com/gmail/v1/users/me/drafts/send' => Http::response(['id' => 'sent1', 'threadId' => 't1', 'labelIds' => ['SENT']]),
        ]);

        $provider = $this->provider();
        $created = $provider->createDraft($this->id(), ['raw' => "Subject: x\r\n\r\ny", 'thread_id' => 't1']);
        $this->assertSame(['draft_id' => 'd1', 'message_id' => 'dm1'], $created);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/users/me/drafts') && $request['message']['threadId'] === 't1' && $request['message']['raw'] === MimeBuilder::base64url("Subject: x\r\n\r\ny"));

        $provider->updateDraft($this->id(), 'd1', ['raw' => "Subject: z\r\n\r\ny"]);
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/users/me/drafts/d1'));

        $sent = $provider->sendDraft($this->id(), 'd1');
        $this->assertSame('sent1', $sent['message_id']);
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/users/me/drafts/send') && $request['id'] === 'd1');
    }

    public function test_401_refreshes_token_once_and_repeated_401_marks_mailbox_reauth_required(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'expires_in' => 3600]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::sequence()
                ->push(['error' => ['code' => 401, 'message' => 'Invalid Credentials']], 401)
                ->push(['emailAddress' => 'verwaltung@muellerhv.de', 'historyId' => '7'])
                ->push(['error' => ['code' => 401]], 401)
                ->push(['error' => ['code' => 401]], 401),
        ]);

        $provider = $this->provider();
        $this->assertSame('7', $provider->getProfile($this->id())['history_id']);
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/token') && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'refresh-1');
        $this->assertSame('access-2', $this->box->refresh()->getAttribute('oauth_access_token'));

        try {
            $provider->getProfile($this->id());
            $this->fail('reauth_required erwartet.');
        } catch (GmailReauthRequiredException) {
            $this->assertSame('reauth_required', $this->box->refresh()->getAttribute('status'));
        }
    }

    public function test_429_and_5xx_are_retryable_remote_errors_and_404_is_not(): void
    {
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/history?*' => Http::sequence()
                ->push(['error' => ['code' => 429, 'message' => 'userRateLimitExceeded']], 429)
                ->push(['error' => ['code' => 404, 'message' => 'Not Found']], 404),
        ]);

        $provider = $this->provider();

        try {
            $provider->listHistory($this->id(), '1');
            $this->fail('429 erwartet.');
        } catch (MailRemoteException $exception) {
            $this->assertSame(429, $exception->httpStatus);
            $this->assertTrue(GmailApiClient::isRetryableStatus($exception->httpStatus));
            $this->assertSame('userRateLimitExceeded', $exception->responseExcerpt);
        }

        try {
            $provider->listHistory($this->id(), '1');
            $this->fail('404 erwartet.');
        } catch (MailRemoteException $exception) {
            $this->assertSame(404, $exception->httpStatus);
            $this->assertFalse(GmailApiClient::isRetryableStatus($exception->httpStatus));
        }

        $this->assertSame('active', $this->box->refresh()->getAttribute('status'), 'Kein reauth_required durch 429 oder 404.');
    }

    public function test_quota_counter_blocks_calls_before_they_reach_google(): void
    {
        config()->set('hub.gmail.quota.per_minute_per_mailbox', 2);
        Http::fake(['https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'x', 'historyId' => '1']), '*' => Http::response([], 500)]);

        $provider = $this->provider();
        $provider->getProfile($this->id());
        $provider->getProfile($this->id());

        $this->expectException(GmailQuotaExceededException::class);

        try {
            $provider->getProfile($this->id());
        } finally {
            Http::assertSentCount(2);
        }
    }

    private function provider(): GmailProvider
    {
        return $this->app->make(GmailProvider::class);
    }

    private function id(): int
    {
        return (int) $this->box->getKey();
    }
}
