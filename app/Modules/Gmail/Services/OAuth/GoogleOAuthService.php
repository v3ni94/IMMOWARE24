<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\OAuth;

use App\Core\Enums\AuditSource;
use App\Modules\Gmail\Events\MailboxReauthRequired;
use App\Modules\Gmail\Exceptions\GmailReauthRequiredException;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * OAuth2 Authorization Code Flow mit state und PKCE (S256), eigene Implementierung über die Http-Facade
 * (Google-Endpunkte aus config hub.gmail.oauth, aus Snippets, vor Produktivbetrieb am Original zu prüfen).
 * Refresh-Token verschlüsselt in mail_mailboxes (Cast encrypted). Scopes minimal je Funktion (config functions).
 * Widerruf über revoke; danach Status revoked, erneute Autorisierung über denselben Flow.
 */
final class GoogleOAuthService
{
    public const string STATUS_REAUTH_REQUIRED = 'reauth_required';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly Repository $config,
        private readonly AuditLogger $audit,
    ) {}

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '' && $this->redirectUri() !== '';
    }

    /**
     * Aktive Redirect-URI der laufenden Umgebung (getrennt je Umgebung).
     */
    public function redirectUri(): string
    {
        $environment = strtolower((string) $this->config->get('app.env', 'production'));
        $uris = (array) $this->config->get('hub.gmail.oauth.redirect_uris', []);
        $uri = $uris[$environment] ?? null;

        if (! is_string($uri) || trim($uri) === '') {
            $uri = $uris['production'] ?? '';
        }

        return is_string($uri) ? trim($uri) : '';
    }

    /**
     * Minimale Scopes für die gewünschten Funktionen (import, drafts, send, aliases).
     *
     * @param  array<int, string>  $functions
     * @return array<int, string>
     */
    public function scopesFor(array $functions): array
    {
        $matrix = (array) $this->config->get('hub.gmail.oauth.functions', []);
        $scopes = (array) $this->config->get('hub.gmail.oauth.scopes', []);
        $result = [];

        foreach ($functions as $function) {
            foreach ((array) ($matrix[$function] ?? []) as $key) {
                if (isset($scopes[$key]) && is_string($scopes[$key])) {
                    $result[] = $scopes[$key];
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Erzeugt die Autorisierungs-URL und legt state, PKCE-Verifier, Postfach und Nutzer im Cache ab.
     *
     * @param  array<int, string>  $functions
     * @return array{url: string, state: string}
     */
    public function beginAuthorization(Mailbox $mailbox, User $user, array $functions = ['import']): array
    {
        if (! $this->isConfigured()) {
            throw MailIntegrationNotConfiguredException::for('gmail');
        }

        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $scopes = $this->scopesFor($functions);

        $this->cache->put($this->stateKey($state), [
            'mailbox_id' => (int) $mailbox->getKey(),
            'user_id' => (int) $user->getKey(),
            'verifier' => $verifier,
            'scopes' => $scopes,
            'functions' => array_values($functions),
        ], (int) $this->config->get('hub.gmail.oauth.state_ttl_seconds', 600));

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'login_hint' => (string) $mailbox->getAttribute('email_address'),
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
        return ['url' => (string) $this->config->get('hub.gmail.oauth.authorization_url').'?'.$query, 'state' => $state];
    }

    /**
     * Callback: prüft state, tauscht code gegen Tokens, speichert Refresh-Token verschlüsselt. Ohne Refresh-Token
     * bleibt das Postfach nicht autorisiert (kein Erfolg ohne dauerhaften Zugang).
     */
    public function completeAuthorization(string $state, string $code, User $user): Mailbox
    {
        $pending = $this->cache->pull($this->stateKey($state));

        if (! is_array($pending)) {
            throw new MailRemoteException('OAuth-Zustand ungültig oder abgelaufen (state).', 'gmail', null, null);
        }

        if ((int) $pending['user_id'] !== (int) $user->getKey()) {
            throw new MailRemoteException('OAuth-Zustand gehört zu einer anderen Sitzung.', 'gmail', null, null);
        }

        /** @var Mailbox $mailbox */
        $mailbox = Mailbox::query()->withoutGlobalScopes()->findOrFail((int) $pending['mailbox_id']);

        // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
        $response = $this->post((string) $this->config->get('hub.gmail.oauth.token_url'), [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $this->redirectUri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => (string) $pending['verifier'],
        ]);

        $refreshToken = $response['refresh_token'] ?? null;
        $accessToken = $response['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new MailRemoteException('Token-Antwort ohne access_token.', 'gmail', null, null);
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            $mailbox->forceFill([
                'status' => 'configured',
                'status_reason' => 'Google hat kein Refresh-Token geliefert; Autorisierung erneut mit Zustimmung durchführen.',
            ])->save();

            throw new MailRemoteException('Token-Antwort ohne refresh_token, dauerhafter Zugang nicht möglich.', 'gmail', null, null);
        }

        $grantedScopes = isset($response['scope']) && is_string($response['scope'])
            ? preg_split('/\s+/', trim($response['scope'])) ?: []
            : (array) $pending['scopes'];

        $mailbox->forceFill([
            'oauth_client_id' => $this->clientId(),
            'oauth_refresh_token' => $refreshToken,
            'oauth_access_token' => $accessToken,
            'oauth_token_expires_at' => CarbonImmutable::now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
            'oauth_scopes_json' => array_values($grantedScopes),
            'oauth_granted_by' => $user->getKey(),
            'oauth_granted_at' => CarbonImmutable::now(),
            'status' => 'active',
            'status_reason' => null,
            'last_error_at' => null,
            'last_error_class' => null,
        ])->save();

        $this->audit->record('mail.gmail.oauth.granted', $mailbox, [], ['scopes' => array_values($grantedScopes), 'functions' => $pending['functions']], AuditSource::Mail);

        return $mailbox;
    }

    /**
     * Gültiges Access-Token liefern, bei Ablauf erneuern. Schlägt der Refresh fehl (invalid_grant, 400/401), steht
     * das Postfach auf reauth_required.
     *
     * @throws GmailReauthRequiredException
     */
    public function accessToken(Mailbox $mailbox, bool $forceRefresh = false): string
    {
        $expiresAt = $mailbox->getAttribute('oauth_token_expires_at');
        $leeway = (int) $this->config->get('hub.gmail.oauth.refresh_leeway_seconds', 60);
        $current = $mailbox->getAttribute('oauth_access_token');

        if (! $forceRefresh && is_string($current) && $current !== '' && $expiresAt instanceof \DateTimeInterface && $expiresAt->getTimestamp() - $leeway > time()) {
            return $current;
        }

        return $this->refresh($mailbox);
    }

    /**
     * @throws GmailReauthRequiredException
     */
    public function refresh(Mailbox $mailbox): string
    {
        $refreshToken = $mailbox->getAttribute('oauth_refresh_token');

        if (! is_string($refreshToken) || $refreshToken === '') {
            $this->markReauthRequired($mailbox, 'Kein Refresh-Token hinterlegt.');

            throw new GmailReauthRequiredException((int) $mailbox->getKey());
        }

        try {
            // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
            $response = $this->post((string) $this->config->get('hub.gmail.oauth.token_url'), [
                'client_id' => $this->clientId(),
                'client_secret' => $this->clientSecret(),
                'refresh_token' => $refreshToken,
                'grant_type' => 'refresh_token',
            ]);
        } catch (MailRemoteException $exception) {
            if (in_array($exception->httpStatus, [400, 401, 403], true)) {
                $this->markReauthRequired($mailbox, 'Token-Refresh abgelehnt (HTTP '.$exception->httpStatus.').');

                throw new GmailReauthRequiredException((int) $mailbox->getKey(), $exception->getMessage());
            }

            throw $exception;
        }

        $accessToken = $response['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            $this->markReauthRequired($mailbox, 'Token-Refresh ohne access_token.');

            throw new GmailReauthRequiredException((int) $mailbox->getKey());
        }

        $mailbox->forceFill([
            'oauth_access_token' => $accessToken,
            'oauth_token_expires_at' => CarbonImmutable::now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
        ])->save();

        return $accessToken;
    }

    /**
     * Widerruf bei Google und lokales Löschen der Tokens. Ein Fehler der Gegenstelle verhindert das lokale Löschen
     * nicht, wird aber als nicht bestätigt protokolliert.
     */
    public function revoke(Mailbox $mailbox, ?User $user = null): bool
    {
        $token = $mailbox->getAttribute('oauth_refresh_token') ?? $mailbox->getAttribute('oauth_access_token');
        $remoteConfirmed = false;

        if (is_string($token) && $token !== '') {
            try {
                // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
                $response = $this->http->asForm()
                    ->timeout((int) $this->config->get('hub.gmail.api.timeout_seconds', 30))
                    ->post((string) $this->config->get('hub.gmail.oauth.revoke_url'), ['token' => $token]);
                $remoteConfirmed = $response->successful();
            } catch (ConnectionException $exception) {
                Log::warning('Gmail: Widerruf bei Google nicht erreichbar.', ['mailbox_id' => $mailbox->getKey(), 'error' => $exception->getMessage()]);
            }
        }

        $mailbox->forceFill([
            'oauth_refresh_token' => null,
            'oauth_access_token' => null,
            'oauth_token_expires_at' => null,
            'oauth_scopes_json' => null,
            'status' => 'revoked',
            'status_reason' => $remoteConfirmed ? 'Zugriff widerrufen.' : 'Lokal widerrufen, Bestätigung durch Google steht aus.',
        ])->save();

        $this->audit->record('mail.gmail.oauth.revoked', $mailbox, [], ['remote_confirmed' => $remoteConfirmed], AuditSource::Mail);

        return $remoteConfirmed;
    }

    public function markReauthRequired(Mailbox $mailbox, string $reason): void
    {
        $mailbox->forceFill([
            'status' => self::STATUS_REAUTH_REQUIRED,
            'status_reason' => mb_substr($reason, 0, 200),
            'last_error_at' => CarbonImmutable::now(),
            'last_error_class' => GmailReauthRequiredException::class,
        ])->save();

        event(new MailboxReauthRequired((int) $mailbox->getKey(), $reason));
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function post(string $url, array $form): array
    {
        try {
            $response = $this->http->asForm()
                ->timeout((int) $this->config->get('hub.gmail.api.timeout_seconds', 30))
                ->post($url, $form);
        } catch (ConnectionException $exception) {
            throw new MailRemoteException('Google OAuth nicht erreichbar: '.$exception->getMessage(), 'gmail', null, null);
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            $excerpt = is_string($error) ? $error : 'unbekannt';

            throw new MailRemoteException('Google OAuth antwortet mit HTTP '.$response->status().' ('.$excerpt.').', 'gmail', $response->status(), $excerpt);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function clientId(): string
    {
        return trim((string) $this->config->get('hub.gmail.oauth.client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) $this->config->get('hub.gmail.oauth.client_secret', ''));
    }

    private function stateKey(string $state): string
    {
        return 'mail:gmail:oauth:state:'.hash('sha256', $state);
    }
}
