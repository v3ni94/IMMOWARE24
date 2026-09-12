<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Lesender HTTP-Client für Google Drive v3 (files.list, files.get, files.export, permissions.list). Nur GET.
 * Parameter und Feldnamen aus Snippets, am Original zu prüfen. Bei 401 einmal Token erneuern, 429 und 5xx
 * begrenzt wiederholen. Secrets erscheinen nie in Exceptions.
 */
final class DriveApiClient
{
    public const string FILE_FIELDS = 'id,name,mimeType,size,modifiedTime,webViewLink,parents,md5Checksum,sha256Checksum,trashed';

    public function __construct(
        private readonly DriveTokenStore $tokens,
        private readonly Repository $config,
    ) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listFiles(DriveConnection $connection, array $query): array
    {
        $query += [
            'fields' => 'nextPageToken,files('.self::FILE_FIELDS.')',
            'pageSize' => (int) $this->config->get('hub.drive.page_size', 50),
            'supportsAllDrives' => 'true',
            'includeItemsFromAllDrives' => 'true',
            'corpora' => 'allDrives',
        ];

        return $this->json($this->get($connection, '/files', $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getFile(DriveConnection $connection, string $fileId): array
    {
        return $this->json($this->get($connection, '/files/'.rawurlencode($fileId), [
            'fields' => self::FILE_FIELDS,
            'supportsAllDrives' => 'true',
        ]));
    }

    /**
     * Inhalt einer Binär- oder Textdatei (alt=media), begrenzt auf max_download_bytes.
     */
    public function downloadContent(DriveConnection $connection, string $fileId): string
    {
        $response = $this->get($connection, '/files/'.rawurlencode($fileId), ['alt' => 'media', 'supportsAllDrives' => 'true']);

        return $this->limitBytes($response->body());
    }

    /**
     * Export eines Google-Dokuments (files.export) in ein Textformat.
     */
    public function exportContent(DriveConnection $connection, string $fileId, string $mimeType): string
    {
        $response = $this->get($connection, '/files/'.rawurlencode($fileId).'/export', ['mimeType' => $mimeType]);

        return $this->limitBytes($response->body());
    }

    /**
     * @return array<int, array{type: string, role: string, email: ?string, domain: ?string}>
     */
    public function listPermissions(DriveConnection $connection, string $fileId): array
    {
        $result = [];
        $pageToken = null;

        do {
            $query = ['fields' => 'nextPageToken,permissions(id,type,role,emailAddress,domain)', 'supportsAllDrives' => 'true'];

            if ($pageToken !== null) {
                $query['pageToken'] = $pageToken;
            }

            $json = $this->json($this->get($connection, '/files/'.rawurlencode($fileId).'/permissions', $query));

            foreach ((array) ($json['permissions'] ?? []) as $permission) {
                if (! is_array($permission)) {
                    continue;
                }

                $result[] = [
                    'type' => (string) ($permission['type'] ?? ''),
                    'role' => (string) ($permission['role'] ?? ''),
                    'email' => isset($permission['emailAddress']) ? strtolower((string) $permission['emailAddress']) : null,
                    'domain' => isset($permission['domain']) ? strtolower((string) $permission['domain']) : null,
                ];
            }

            $pageToken = is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : null;
        } while ($pageToken !== null && count($result) < 500);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function get(DriveConnection $connection, string $path, array $query): Response
    {
        $base = rtrim((string) $this->config->get('hub.drive.api_base_url', 'https://www.googleapis.com/drive/v3'), '/');
        $times = max(0, (int) $this->config->get('hub.drive.retry.times', 2));
        $sleeps = array_values(array_map('intval', (array) $this->config->get('hub.drive.retry.sleep_ms', [300, 1000])));
        $attempt = 0;
        $refreshed = false;
        $token = $this->tokens->accessToken($connection);

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($token)
                    ->connectTimeout((int) $this->config->get('hub.drive.connect_timeout_seconds', 5))
                    ->timeout((int) $this->config->get('hub.drive.timeout_seconds', 20))
                    ->get($base.$path, $query);
            } catch (ConnectionException) {
                if ($attempt > $times) {
                    $this->markError($connection, 'transport');

                    throw new MailRemoteException('Google Drive nicht erreichbar (Transport).', 'drive');
                }

                $this->pause($sleeps, $attempt);

                continue;
            }

            $status = $response->status();

            if ($status === 401 && ! $refreshed) {
                // Einmalige Token-Erneuerung zählt nicht als Wiederholungsversuch.
                $refreshed = true;
                $attempt--;
                $token = $this->tokens->accessToken($connection, true);

                continue;
            }

            if ($status === 429 || $status >= 500) {
                if ($attempt > $times) {
                    $this->markError($connection, 'http_'.$status);

                    throw new MailRemoteException(sprintf('Google Drive antwortet mit HTTP %d.', $status), 'drive', $status, mb_substr($response->body(), 0, 300));
                }

                $this->pause($sleeps, $attempt);

                continue;
            }

            if ($status >= 400) {
                $this->markError($connection, 'http_'.$status);

                throw new MailRemoteException(sprintf('Google Drive lehnt Anfrage ab (HTTP %d).', $status), 'drive', $status, mb_substr($response->body(), 0, 300));
            }

            $connection->forceFill(['last_success_at' => now()->toImmutable()])->saveQuietly();

            return $response;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new MailRemoteException('Google Drive antwortet ohne JSON.', 'drive', $response->status());
        }

        return $json;
    }

    private function limitBytes(string $body): string
    {
        $max = (int) $this->config->get('hub.drive.excerpt.max_download_bytes', 2 * 1024 * 1024);

        return strlen($body) > $max ? substr($body, 0, $max) : $body;
    }

    /**
     * @param  array<int, int>  $sleeps
     */
    private function pause(array $sleeps, int $attempt): void
    {
        $ms = $sleeps[$attempt - 1] ?? ($sleeps === [] ? 300 : end($sleeps));

        if ($ms > 0 && ! app()->runningUnitTests()) {
            usleep($ms * 1000);
        }
    }

    private function markError(DriveConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'last_error_at' => now()->toImmutable(),
            'last_error_class' => mb_substr($reason, 0, 200),
            'status' => (string) $connection->getAttribute('status') === 'revoked' ? 'revoked' : 'degraded',
        ])->saveQuietly();
    }
}
