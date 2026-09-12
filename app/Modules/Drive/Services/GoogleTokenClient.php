<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Kleiner, wiederverwendbarer Helfer für den Google-OAuth-Token-Endpunkt (refresh_token → access_token). Bewusst ohne
 * Bindung an Postfach oder Drive-Verbindung, damit das Gmail-Modul dieselbe Logik nutzen könnte (kein Duplikat der
 * dortigen Mailbox-Logik). Formfelder laut Snippets: client_id, client_secret, refresh_token, grant_type=refresh_token;
 * Antwort access_token, expires_in. Am Original zu prüfen. Secrets erscheinen nie in Exceptions oder Logs.
 */
final class GoogleTokenClient
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array{access_token: string, expires_in: int, scope: ?string}
     */
    public function refresh(string $clientId, string $clientSecret, string $refreshToken, string $integration = 'drive'): array
    {
        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw MailIntegrationNotConfiguredException::for($integration);
        }

        $url = (string) $this->config->get('hub.drive.oauth.token_url', 'https://oauth2.googleapis.com/token');

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout((int) $this->config->get('hub.drive.timeout_seconds', 20))
                ->post($url, [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (ConnectionException) {
            throw new MailRemoteException('Google-Token-Endpunkt nicht erreichbar.', $integration);
        }

        if ($response->failed()) {
            $error = is_string($response->json('error')) ? (string) $response->json('error') : 'unbekannt';

            throw new MailRemoteException(sprintf('Token-Aktualisierung fehlgeschlagen (HTTP %d, %s).', $response->status(), $error), $integration, $response->status(), $error);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new MailRemoteException('Token-Antwort ohne access_token.', $integration, $response->status());
        }

        return [
            'access_token' => $token,
            'expires_in' => (int) ($response->json('expires_in') ?? 3600),
            'scope' => is_string($response->json('scope')) ? (string) $response->json('scope') : null,
        ];
    }
}
