<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;

/**
 * Token-Verwaltung je Drive-Verbindung: liefert ein gültiges Access-Token, erneuert bei Ablauf über GoogleTokenClient
 * und speichert verschlüsselt (Casts encrypted). Bei invalid_grant wird die Verbindung als revoked markiert.
 */
final class DriveTokenStore
{
    public function __construct(
        private readonly GoogleTokenClient $client,
        private readonly Repository $config,
    ) {}

    public function accessToken(DriveConnection $connection, bool $forceRefresh = false): string
    {
        $token = $connection->getAttribute('oauth_access_token');
        $expires = $connection->getAttribute('oauth_token_expires_at');
        $leeway = (int) $this->config->get('hub.drive.oauth.refresh_leeway_seconds', 120);

        if (! $forceRefresh && is_string($token) && $token !== '' && $expires instanceof CarbonImmutable && $expires->isAfter(CarbonImmutable::now()->addSeconds($leeway))) {
            return $token;
        }

        return $this->refresh($connection);
    }

    public function refresh(DriveConnection $connection): string
    {
        $refreshToken = $connection->getAttribute('oauth_refresh_token');
        $clientId = $this->config->get('hub.drive.oauth.client_id');
        $clientSecret = $this->config->get('hub.drive.oauth.client_secret');

        if (! is_string($refreshToken) || $refreshToken === '' || ! is_string($clientId) || ! is_string($clientSecret)) {
            throw MailIntegrationNotConfiguredException::for('drive');
        }

        try {
            $result = $this->client->refresh($clientId, $clientSecret, $refreshToken);
        } catch (MailRemoteException $e) {
            if ($e->responseExcerpt === 'invalid_grant') {
                $connection->forceFill([
                    'status' => 'revoked',
                    'status_reason' => 'Google meldet invalid_grant, erneute Anmeldung erforderlich.',
                    'last_error_at' => now()->toImmutable(),
                    'last_error_class' => $e::class,
                ])->save();
            }

            throw $e;
        }

        $connection->forceFill([
            'oauth_access_token' => $result['access_token'],
            'oauth_token_expires_at' => CarbonImmutable::now()->addSeconds(max(60, $result['expires_in'])),
            'status' => 'active',
            'status_reason' => null,
        ])->save();

        return $result['access_token'];
    }
}
