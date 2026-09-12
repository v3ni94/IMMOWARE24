<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * OAuth2 Authorization Code Flow mit state und PKCE (S256) für Google Drive, analog zum Gmail-Modul, eigene
 * Implementierung über die Http-Facade (Endpunkte aus config hub.drive.oauth, aus Snippets, vor Produktivbetrieb am
 * Original prüfen). Eine Verbindung je Organisation; Scope ausschließlich drive.readonly; Refresh- und Access-Token
 * verschlüsselt in mail_drive_connections (Casts encrypted). Widerruf über revoke, danach Status revoked
 * ("Reauth nötig"); erneute Autorisierung über denselben Flow. Secrets erscheinen nie in Logs oder Exceptions.
 */
final class DriveOAuthService
{
    public const string STATUS_NOT_CONFIGURED = 'not_configured';

    public const string STATUS_CONNECTED = 'connected';

    public const string STATUS_REAUTH_REQUIRED = 'reauth_required';

    /** @var array<string, string> */
    public const array LABELS = [
        self::STATUS_NOT_CONFIGURED => 'Nicht eingerichtet',
        self::STATUS_CONNECTED => 'Verbunden',
        self::STATUS_REAUTH_REQUIRED => 'Reauth nötig',
    ];

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
        $uris = (array) $this->config->get('hub.drive.oauth.redirect_uris', []);
        $uri = $uris[$environment] ?? null;

        if (! is_string($uri) || trim($uri) === '') {
            $uri = $uris['production'] ?? '';
        }

        return is_string($uri) ? trim($uri) : '';
    }

    /**
     * @return array<int, string>
     */
    public function scopes(): array
    {
        return array_values(array_unique(array_map('strval', (array) $this->config->get('hub.drive.oauth.scopes', ['https://www.googleapis.com/auth/drive.readonly']))));
    }

    /**
     * Verbindung der Organisation (höchstens eine, nicht gelöscht) oder null.
     */
    public function connectionFor(int $organizationId): ?DriveConnection
    {
        $connection = DriveConnection::query()->allOrganizations()->where('organization_id', $organizationId)->orderBy('id')->first();

        return $connection instanceof DriveConnection ? $connection : null;
    }

    /**
     * Sichtbarer Status der Drive-Anbindung einer Organisation: Nicht eingerichtet, Verbunden, Reauth nötig.
     */
    public function status(int $organizationId): string
    {
        $connection = $this->connectionFor($organizationId);

        if (! $connection instanceof DriveConnection) {
            return self::STATUS_NOT_CONFIGURED;
        }

        $status = (string) $connection->getAttribute('status');
        $hasToken = is_string($connection->getAttribute('oauth_refresh_token')) && $connection->getAttribute('oauth_refresh_token') !== '';

        if (in_array($status, ['revoked', self::STATUS_REAUTH_REQUIRED], true)) {
            return self::STATUS_REAUTH_REQUIRED;
        }

        return $hasToken && in_array($status, ['configured', 'active', 'degraded'], true) ? self::STATUS_CONNECTED : self::STATUS_NOT_CONFIGURED;
    }

    public function statusLabel(int $organizationId): string
    {
        return self::LABELS[$this->status($organizationId)];
    }

    /**
     * Erzeugt die Autorisierungs-URL und legt state, PKCE-Verifier, Verbindung und Nutzer im Cache ab. Die Verbindung
     * der Organisation wird bei Bedarf mit Status not_configured angelegt (ohne Tokens).
     *
     * @return array{url: string, state: string}
     */
    public function beginAuthorization(User $user): array
    {
        if (! $this->isConfigured()) {
            throw MailIntegrationNotConfiguredException::for('drive');
        }

        $organizationId = (int) $user->getAttribute('organization_id');
        $connection = $this->connectionFor($organizationId) ?? DriveConnection::query()->create([
            'organization_id' => $organizationId,
            'label' => (string) $this->config->get('hub.drive.oauth.connection_label', 'Google Drive'),
            'status' => 'not_configured',
        ]);

        $state = bin2hex(random_bytes(32));
        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $scopes = $this->scopes();

        $this->cache->put($this->stateKey($state), [
            'connection_id' => (int) $connection->getKey(),
            'user_id' => (int) $user->getKey(),
            'verifier' => $verifier,
            'scopes' => $scopes,
        ], (int) $this->config->get('hub.drive.oauth.state_ttl_seconds', 600));

        $query = http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
        return ['url' => (string) $this->config->get('hub.drive.oauth.authorization_url').'?'.$query, 'state' => $state];
    }

    /**
     * Callback: prüft state und Sitzung, tauscht code mit PKCE-Verifier gegen Tokens, speichert verschlüsselt. Ohne
     * Refresh-Token bleibt die Verbindung nicht autorisiert (kein Erfolg ohne dauerhaften Zugang).
     */
    public function completeAuthorization(string $state, string $code, User $user): DriveConnection
    {
        $pending = $this->cache->pull($this->stateKey($state));

        if (! is_array($pending)) {
            throw new MailRemoteException('OAuth-Zustand ungültig oder abgelaufen (state).', 'drive');
        }

        if ((int) $pending['user_id'] !== (int) $user->getKey()) {
            throw new MailRemoteException('OAuth-Zustand gehört zu einer anderen Sitzung.', 'drive');
        }

        $connection = DriveConnection::query()->allOrganizations()->find((int) $pending['connection_id']);

        if (! $connection instanceof DriveConnection) {
            throw new MailRemoteException('Drive-Verbindung des OAuth-Zustands existiert nicht mehr.', 'drive');
        }

        if ((int) $connection->getAttribute('organization_id') !== (int) $user->getAttribute('organization_id')) {
            throw new MailRemoteException('OAuth-Zustand gehört zu einer anderen Organisation.', 'drive');
        }

        // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
        $response = $this->post((string) $this->config->get('hub.drive.oauth.token_url'), [
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
            throw new MailRemoteException('Token-Antwort ohne access_token.', 'drive');
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            $connection->forceFill([
                'status' => 'not_configured',
                'status_reason' => 'Google hat kein Refresh-Token geliefert; Autorisierung erneut mit Zustimmung durchführen.',
            ])->save();

            throw new MailRemoteException('Token-Antwort ohne refresh_token, dauerhafter Zugang nicht möglich.', 'drive');
        }

        $grantedScopes = isset($response['scope']) && is_string($response['scope'])
            ? array_values(array_filter(preg_split('/\s+/', trim($response['scope'])) ?: []))
            : (array) $pending['scopes'];

        // Nur lesender Zugriff: liefert Google einen Scope über drive.readonly hinaus, werden die Tokens verworfen.
        $unexpected = array_diff($grantedScopes, $this->scopes());

        if ($unexpected !== []) {
            $this->revokeQuietly($refreshToken);
            $connection->forceFill(['status' => 'not_configured', 'status_reason' => 'Google hat mehr Rechte erteilt als angefordert; Tokens verworfen.'])->save();

            throw new MailRemoteException('Erteilte Scopes gehen über drive.readonly hinaus; Tokens verworfen.', 'drive');
        }

        $connection->forceFill([
            'account_email' => $this->accountEmail($accessToken),
            'oauth_refresh_token' => $refreshToken,
            'oauth_access_token' => $accessToken,
            'oauth_token_expires_at' => CarbonImmutable::now()->addSeconds((int) ($response['expires_in'] ?? 3600)),
            'oauth_scopes_json' => $grantedScopes,
            'oauth_granted_by' => $user->getKey(),
            'oauth_granted_at' => CarbonImmutable::now(),
            'status' => 'active',
            'status_reason' => null,
            'last_error_at' => null,
            'last_error_class' => null,
        ])->save();

        $this->audit->record('mail.drive.oauth.granted', $connection, [], ['scopes' => $grantedScopes], AuditSource::Mail);

        return $connection;
    }

    /**
     * Widerruf bei Google und lokales Löschen der Tokens für die Verbindung der Organisation. Ein Fehler der
     * Gegenstelle verhindert das lokale Löschen nicht, wird aber als nicht bestätigt protokolliert.
     *
     * @return bool|null null, wenn keine Verbindung existiert
     */
    public function revoke(User $user): ?bool
    {
        $connection = $this->connectionFor((int) $user->getAttribute('organization_id'));

        if (! $connection instanceof DriveConnection) {
            return null;
        }

        $token = $connection->getAttribute('oauth_refresh_token') ?? $connection->getAttribute('oauth_access_token');
        $remoteConfirmed = false;

        if (is_string($token) && $token !== '') {
            $remoteConfirmed = $this->revokeQuietly($token);
        }

        $connection->forceFill([
            'oauth_refresh_token' => null,
            'oauth_access_token' => null,
            'oauth_token_expires_at' => null,
            'oauth_scopes_json' => null,
            'status' => 'revoked',
            'status_reason' => $remoteConfirmed ? 'Zugriff widerrufen.' : 'Lokal widerrufen, Bestätigung durch Google steht aus.',
        ])->save();

        $this->audit->record('mail.drive.oauth.revoked', $connection, [], ['remote_confirmed' => $remoteConfirmed], AuditSource::Mail);

        return $remoteConfirmed;
    }

    /**
     * Adresse des verbundenen Google-Kontos (Anzeige auf der Integrationsseite). Quelle: Drive v3 about.get mit
     * fields=user (aus Snippets, am Original prüfen). Ein Fehler verhindert die Verbindung nicht.
     */
    private function accountEmail(string $accessToken): ?string
    {
        try {
            $response = $this->http->withToken($accessToken)
                ->acceptJson()
                ->timeout((int) $this->config->get('hub.drive.timeout_seconds', 20))
                ->get(rtrim((string) $this->config->get('hub.drive.api_base_url'), '/').'/about', ['fields' => 'user']);

            if ($response->successful()) {
                $email = $response->json('user.emailAddress');

                return is_string($email) && $email !== '' ? mb_substr(strtolower(trim($email)), 0, 254) : null;
            }

            Log::warning('Drive: Kontoabfrage nach Autorisierung fehlgeschlagen.', ['status' => $response->status()]);
        } catch (ConnectionException $exception) {
            Log::warning('Drive: Kontoabfrage nach Autorisierung nicht erreichbar.', ['error' => $exception->getMessage()]);
        }

        return null;
    }

    private function revokeQuietly(string $token): bool
    {
        try {
            // gemäß Google OAuth2 Referenz, vor Produktivbetrieb am Original prüfen
            $response = $this->http->asForm()
                ->timeout((int) $this->config->get('hub.drive.timeout_seconds', 20))
                ->post((string) $this->config->get('hub.drive.oauth.revoke_url'), ['token' => $token]);

            return $response->successful();
        } catch (ConnectionException $exception) {
            Log::warning('Drive: Widerruf bei Google nicht erreichbar.', ['error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * @param  array<string, string>  $form
     * @return array<string, mixed>
     */
    private function post(string $url, array $form): array
    {
        try {
            $response = $this->http->asForm()
                ->timeout((int) $this->config->get('hub.drive.timeout_seconds', 20))
                ->post($url, $form);
        } catch (ConnectionException $exception) {
            throw new MailRemoteException('Google OAuth nicht erreichbar: '.$exception->getMessage(), 'drive');
        }

        if (! $response->successful()) {
            $error = $response->json('error');
            $excerpt = is_string($error) ? $error : 'unbekannt';

            throw new MailRemoteException('Google OAuth antwortet mit HTTP '.$response->status().' ('.$excerpt.').', 'drive', $response->status(), $excerpt);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function clientId(): string
    {
        return trim((string) $this->config->get('hub.drive.oauth.client_id', ''));
    }

    private function clientSecret(): string
    {
        return trim((string) $this->config->get('hub.drive.oauth.client_secret', ''));
    }

    private function stateKey(string $state): string
    {
        return 'mail:drive:oauth:state:'.hash('sha256', $state);
    }
}
