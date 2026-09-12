<?php

declare(strict_types=1);

namespace Tests\Feature\Drive;

use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Drive\Services\DriveOAuthService;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Security\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Drive-OAuth-Anmeldefluss analog Gmail (Authorization Code mit PKCE, nur drive.readonly). Nur Http::fake(),
 * kein Live-Test gegen Google möglich.
 */
final class DriveOAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hub.drive.oauth.client_id', 'drive-client.apps.googleusercontent.com');
        config()->set('hub.drive.oauth.client_secret', 'drive-secret');
    }

    public function test_authorization_url_carries_state_pkce_and_only_readonly_scope(): void
    {
        $user = $this->actingAsMailRole('admin');
        $service = $this->service();
        $this->assertSame(DriveOAuthService::STATUS_NOT_CONFIGURED, $service->status((int) $user->getAttribute('organization_id')));

        $result = $service->beginAuthorization($user);
        parse_str((string) parse_url($result['url'], PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $result['url']);
        $this->assertSame($result['state'], $query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertSame('http://mail.test/mail/integrations/drive/callback', $query['redirect_uri']);
        $this->assertSame('https://www.googleapis.com/auth/drive.readonly', $query['scope'], 'Ausschließlich lesender Scope.');
        $this->assertSame('offline', $query['access_type']);

        $connection = DriveConnection::query()->firstOrFail();
        $this->assertSame('not_configured', $connection->getAttribute('status'));
        $this->assertNull($connection->getAttribute('oauth_refresh_token'));
        $this->assertSame('Nicht eingerichtet', $service->statusLabel((int) $user->getAttribute('organization_id')));
    }

    public function test_callback_exchanges_code_with_pkce_verifier_and_stores_encrypted_tokens(): void
    {
        $user = $this->actingAsMailRole('admin');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-drive', 'refresh_token' => 'rt-drive', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/drive.readonly', 'token_type' => 'Bearer']),
            'https://www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'Ablage@muellerhv.de', 'displayName' => 'Ablage']]),
        ]);

        $begin = $this->service()->beginAuthorization($user);
        $connection = $this->service()->completeAuthorization($begin['state'], 'auth-code', $user);

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/token') && $request['grant_type'] === 'authorization_code' && $request['code'] === 'auth-code' && is_string($request['code_verifier']) && strlen($request['code_verifier']) > 40 && $request['client_secret'] === 'drive-secret');
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/drive/v3/about') && $request->hasHeader('Authorization', 'Bearer at-drive'));
        $this->assertSame('active', $connection->getAttribute('status'));
        $this->assertSame('rt-drive', $connection->getAttribute('oauth_refresh_token'));
        $this->assertSame('ablage@muellerhv.de', $connection->getAttribute('account_email'));
        $this->assertSame(['https://www.googleapis.com/auth/drive.readonly'], $connection->getAttribute('oauth_scopes_json'));
        $this->assertSame((int) $user->getKey(), (int) $connection->getAttribute('oauth_granted_by'));

        $stored = DB::table('mail_drive_connections')->where('id', $connection->getKey())->first();
        $this->assertStringNotContainsString('rt-drive', (string) $stored->oauth_refresh_token, 'Refresh-Token liegt verschlüsselt in der Datenbank.');
        $this->assertStringNotContainsString('at-drive', (string) $stored->oauth_access_token);
        $this->assertTrue(AuditLog::query()->where('action', 'mail.drive.oauth.granted')->exists());
        $this->assertSame('Verbunden', $this->service()->statusLabel((int) $user->getAttribute('organization_id')));

        $this->expectException(MailRemoteException::class);
        $this->service()->completeAuthorization($begin['state'], 'auth-code', $user);
    }

    public function test_callback_without_refresh_token_or_with_wider_scope_is_not_a_success(): void
    {
        $user = $this->actingAsMailRole('admin');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'at-1', 'expires_in' => 3599])
                ->push(['access_token' => 'at-2', 'refresh_token' => 'rt-2', 'expires_in' => 3599, 'scope' => 'https://www.googleapis.com/auth/drive.readonly https://www.googleapis.com/auth/drive']),
            'https://oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);

        $begin = $this->service()->beginAuthorization($user);

        try {
            $this->service()->completeAuthorization($begin['state'], 'code', $user);
            $this->fail('Ohne Refresh-Token kein Erfolg.');
        } catch (MailRemoteException $exception) {
            $this->assertStringContainsString('refresh_token', $exception->getMessage());
        }

        $begin = $this->service()->beginAuthorization($user);

        try {
            $this->service()->completeAuthorization($begin['state'], 'code', $user);
            $this->fail('Schreibender Scope darf nicht angenommen werden.');
        } catch (MailRemoteException $exception) {
            $this->assertStringContainsString('drive.readonly', $exception->getMessage());
        }

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/revoke') && $request['token'] === 'rt-2');
        $connection = DriveConnection::query()->firstOrFail();
        $this->assertSame('not_configured', $connection->getAttribute('status'));
        $this->assertNull($connection->getAttribute('oauth_refresh_token'));
        $this->assertSame(1, DriveConnection::query()->count(), 'Eine Verbindung je Organisation.');
    }

    public function test_revoke_clears_tokens_and_status_becomes_reauth_required(): void
    {
        $user = $this->actingAsMailRole('admin');
        $organizationId = (int) $user->getAttribute('organization_id');
        DriveConnection::query()->create(['organization_id' => $organizationId, 'label' => 'Ablage', 'oauth_refresh_token' => 'rt', 'oauth_access_token' => 'at', 'status' => 'active']);
        Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        $this->assertTrue($this->service()->revoke($user));

        $connection = DriveConnection::query()->firstOrFail();
        $this->assertSame('revoked', $connection->getAttribute('status'));
        $this->assertNull($connection->getAttribute('oauth_refresh_token'));
        $this->assertNull($connection->getAttribute('oauth_access_token'));
        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '/revoke') && $request['token'] === 'rt');
        $this->assertTrue(AuditLog::query()->where('action', 'mail.drive.oauth.revoked')->exists());
        $this->assertSame('Reauth nötig', $this->service()->statusLabel($organizationId));

        // Nach Widerruf ist eine erneute Autorisierung über denselben Flow möglich; der Zustand bleibt eine Verbindung.
        $this->service()->beginAuthorization($user);
        $this->assertSame(1, DriveConnection::query()->count());
    }

    public function test_not_configured_client_raises_not_configured(): void
    {
        config()->set('hub.drive.oauth.client_id', null);
        $user = $this->actingAsMailRole('admin');

        $this->assertFalse($this->service()->isConfigured());
        $this->expectException(MailIntegrationNotConfiguredException::class);
        $this->service()->beginAuthorization($user);
    }

    public function test_routes_require_integration_permission_and_redirect_to_google(): void
    {
        $this->actingAsMailRole('admin');

        $response = $this->post(self::BASE.'/mail/integrations/drive/connect');
        $response->assertRedirect();
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', (string) $response->headers->get('Location'));
        $this->assertStringContainsString('drive.readonly', urldecode((string) $response->headers->get('Location')));

        $this->get(self::BASE.'/mail/integrations/drive/callback?error=access_denied')->assertRedirect(self::BASE.'/integrations')->assertSessionHasErrors('drive');
        $this->post(self::BASE.'/mail/integrations/drive/revoke')->assertRedirect(self::BASE.'/integrations');

        $this->actingAsMailRole('agent');
        $this->post(self::BASE.'/mail/integrations/drive/connect')->assertForbidden();
        $this->post(self::BASE.'/mail/integrations/drive/revoke')->assertForbidden();
    }

    public function test_callback_route_completes_flow_and_integration_page_links_connect_and_revoke(): void
    {
        $user = $this->actingAsMailRole('admin');
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 3599]),
            'https://www.googleapis.com/drive/v3/about*' => Http::response(['user' => ['emailAddress' => 'ablage@muellerhv.de']]),
        ]);
        $begin = $this->service()->beginAuthorization($user);

        $this->get(self::BASE.'/mail/integrations/drive/callback?state='.$begin['state'].'&code=abc')
            ->assertRedirect(self::BASE.'/integrations')
            ->assertSessionHas('status');
        $this->assertSame('active', DriveConnection::query()->firstOrFail()->getAttribute('status'));

        $page = $this->get(self::BASE.'/integrations')->assertOk();
        $page->assertSee(self::BASE.'/mail/integrations/drive/connect', false);
        $page->assertSee(self::BASE.'/mail/integrations/drive/revoke', false);
        $page->assertSee('drive.readonly');
    }

    private function service(): DriveOAuthService
    {
        return $this->app->make(DriveOAuthService::class);
    }
}
