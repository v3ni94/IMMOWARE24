<?php

declare(strict_types=1);

namespace Tests\Feature\Gmail;

use App\Modules\Gmail\Events\MailboxReauthRequired;
use App\Modules\Gmail\Exceptions\GmailReauthRequiredException;
use App\Modules\Gmail\Services\OAuth\GoogleOAuthService;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Security\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.gmail.oauth.client_id', 'client-id.apps.googleusercontent.com');
        config()->set('hub.gmail.oauth.client_secret', 'client-secret');
    }

    public function test_authorization_url_carries_state_pkce_and_minimal_scopes(): void
    {
        $user = $this->actingAsMailRole('admin');
        $service = $this->service();

        $result = $service->beginAuthorization($this->mailbox, $user, ['import']);
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $result['url']);
        $this->assertSame($result['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('http://mail.test/mail/integrations/gmail/callback', $query['redirect_uri']);
        $this->assertSame('https://www.googleapis.com/auth/gmail.readonly', $query['scope'], 'Nur der Scope der Funktion Import.');
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame(['https://www.googleapis.com/auth/gmail.compose', 'https://www.googleapis.com/auth/gmail.send'], $service->scopesFor(['send']));
    }

    public function test_callback_exchanges_code_with_pkce_verifier_and_stores_encrypted_refresh_token(): void
    {
        $user = $this->actingAsMailRole('admin');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/gmail.readonly', 'token_type' => 'Bearer']),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => strtoupper((string) $this->mailbox->getAttribute('email_address')), 'historyId' => '1']),
        ]);

        $begin = $this->service()->beginAuthorization($this->mailbox, $user, ['import']);
        $mailbox = $this->service()->completeAuthorization($begin['state'], 'auth-code', $user);

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/token') && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code' && is_string($request['code_verifier']) && strlen($request['code_verifier']) > 40 && $request['client_secret'] === 'client-secret');
        $this->assertSame('active', $mailbox->getAttribute('status'));
        $this->assertSame('rt-1', $mailbox->getAttribute('oauth_refresh_token'));
        $this->assertSame(['https://www.googleapis.com/auth/gmail.readonly'], $mailbox->getAttribute('oauth_scopes_json'));
        $this->assertSame((int) $user->getKey(), (int) $mailbox->getAttribute('oauth_granted_by'));

        $stored = DB::table('mail_mailboxes')->where('id', $mailbox->getKey())->value('oauth_refresh_token');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('rt-1', $stored, 'Refresh-Token liegt verschlüsselt in der Datenbank.');
        $this->assertTrue(AuditLog::query()->where('action', 'mail.gmail.oauth.granted')->exists());
        Http::assertSent(static fn (Request $request): bool => str_ends_with($request->url(), '/users/me/profile') && $request->hasHeader('Authorization', 'Bearer at-1'));

        $this->expectException(MailRemoteException::class);
        $this->service()->completeAuthorization($begin['state'], 'auth-code', $user);
    }

    public function test_callback_without_refresh_token_is_not_a_success(): void
    {
        $user = $this->actingAsMailRole('admin');
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-1', 'expires_in' => 3599])]);
        $begin = $this->service()->beginAuthorization($this->mailbox, $user);

        try {
            $this->service()->completeAuthorization($begin['state'], 'code', $user);
            $this->fail('Ohne Refresh-Token kein Erfolg.');
        } catch (MailRemoteException) {
            $this->assertSame('configured', $this->mailbox->refresh()->getAttribute('status'));
            $this->assertNull($this->mailbox->getAttribute('oauth_refresh_token'));
        }
    }

    public function test_callback_with_foreign_google_account_discards_tokens(): void
    {
        $user = $this->actingAsMailRole('admin');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-fremd', 'refresh_token' => 'rt-fremd', 'expires_in' => 3599]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'privat@gmail.com', 'historyId' => '1']),
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);
        $begin = $this->service()->beginAuthorization($this->mailbox, $user, ['import']);

        try {
            $this->service()->completeAuthorization($begin['state'], 'code', $user);
            $this->fail('Fremdes Konto darf nicht autorisiert werden.');
        } catch (MailRemoteException $exception) {
            $this->assertStringContainsString('Postfachadresse', $exception->getMessage());
        }

        $this->mailbox->refresh();
        $this->assertSame('configured', $this->mailbox->getAttribute('status'));
        $this->assertNull($this->mailbox->getAttribute('oauth_refresh_token'));
        $this->assertNull($this->mailbox->getAttribute('oauth_access_token'));
        $this->assertStringContainsString('entspricht nicht', (string) $this->mailbox->getAttribute('status_reason'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/revoke') && $request['token'] === 'rt-fremd');
        $this->assertTrue(AuditLog::query()->where('action', 'mail.gmail.oauth.mismatch')->exists());
        $this->assertFalse(AuditLog::query()->where('action', 'mail.gmail.oauth.granted')->exists());
    }

    public function test_callback_without_determinable_account_discards_tokens_unless_id_token_matches(): void
    {
        $user = $this->actingAsMailRole('admin');
        $email = (string) $this->mailbox->getAttribute('email_address');
        $idToken = 'h.'.rtrim(strtr(base64_encode(json_encode(['email' => $email], JSON_THROW_ON_ERROR)), '+/', '-_'), '=').'.s';
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-2', 'refresh_token' => 'rt-2', 'expires_in' => 3599, 'id_token' => $idToken]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['error' => 'x'], 500),
        ]);
        $begin = $this->service()->beginAuthorization($this->mailbox, $user, ['import']);

        $mailbox = $this->service()->completeAuthorization($begin['state'], 'code', $user);

        $this->assertSame('active', $mailbox->getAttribute('status'), 'id_token-Claim ersetzt den Profilabruf.');
    }

    public function test_refresh_rejection_marks_reauth_required_and_revoke_clears_tokens(): void
    {
        Event::fake([MailboxReauthRequired::class]);
        $user = $this->actingAsMailRole('admin');
        $this->mailbox->forceFill(['oauth_refresh_token' => 'rt-alt', 'oauth_access_token' => 'at-alt', 'oauth_token_expires_at' => now()->subMinute(), 'status' => 'active'])->save();
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        try {
            $this->service()->accessToken($this->mailbox);
            $this->fail('reauth_required erwartet.');
        } catch (GmailReauthRequiredException) {
            $this->assertSame('reauth_required', $this->mailbox->refresh()->getAttribute('status'));
            Event::assertDispatched(MailboxReauthRequired::class);
        }

        $this->assertTrue($this->service()->revoke($this->mailbox, $user));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'oauth2.googleapis.com/revoke') && $request['token'] === 'rt-alt');
        $this->assertSame('revoked', $this->mailbox->refresh()->getAttribute('status'));
        $this->assertNull($this->mailbox->getAttribute('oauth_refresh_token'));
        $this->assertNull($this->mailbox->getAttribute('oauth_access_token'));
    }

    public function test_not_configured_client_throws_visibly_and_controller_requires_permission(): void
    {
        config()->set('hub.gmail.oauth.client_id', null);
        $user = $this->actingAsMailRole('admin');

        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->service()->beginAuthorization($this->mailbox, $user);
    }

    public function test_connect_route_redirects_to_google_for_admin_and_forbids_agent(): void
    {
        $this->actingAsMailRole('admin');
        $response = $this->post('https://mail.muellerhv.de/mail/integrations/gmail/'.$this->mailbox->getKey().'/connect', ['functions' => ['import', 'aliases']]);
        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('gmail.settings.basic', urldecode((string) $response->headers->get('Location')));

        $this->actingAsMailRole('agent', $this->mailbox);
        $this->post('https://mail.muellerhv.de/mail/integrations/gmail/'.$this->mailbox->getKey().'/connect')->assertForbidden();
    }

    private function service(): GoogleOAuthService
    {
        return $this->app->make(GoogleOAuthService::class);
    }
}
