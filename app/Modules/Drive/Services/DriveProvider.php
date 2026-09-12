<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Core\Contracts\Mail\DocumentSourceInterface;
use App\Modules\Drive\Models\DriveConnection;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use Illuminate\Contracts\Config\Repository;

/**
 * DocumentSourceInterface für Google Drive (lesend). Nutzt die erste aktive Drive-Verbindung der Organisation
 * (oder eine explizit gesetzte). Ohne Verbindung: MailIntegrationNotConfiguredException ("Nicht eingerichtet").
 * Kein Schreiben, kein Anlegen von Strukturen.
 */
final class DriveProvider implements DocumentSourceInterface
{
    private ?DriveConnection $connection = null;

    public function __construct(
        private readonly DriveApiClient $client,
        private readonly Repository $config,
    ) {}

    public function using(DriveConnection $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    public function connection(): DriveConnection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $connection = DriveConnection::query()
            ->whereIn('status', ['configured', 'active', 'degraded'])
            ->whereNotNull('oauth_refresh_token')
            ->orderBy('id')
            ->first();

        if (! $connection instanceof DriveConnection) {
            throw MailIntegrationNotConfiguredException::for('drive');
        }

        $this->connection = $connection;

        return $connection;
    }

    public function isConfigured(): bool
    {
        try {
            $this->connection();

            return true;
        } catch (MailIntegrationNotConfiguredException) {
            return false;
        }
    }

    public function search(string $query, array $options = []): array
    {
        $terms = [];
        $escaped = $this->escape(trim($query));

        if ($escaped !== '') {
            $terms[] = sprintf("fullText contains '%s'", $escaped);
        }

        $folder = (string) ($options['folder_id'] ?? $this->config->get('hub.drive.root_folder_id') ?? '');

        if ($folder !== '') {
            $terms[] = sprintf("'%s' in parents", $this->escape($folder));
        }

        if (isset($options['mime_type']) && is_string($options['mime_type'])) {
            $terms[] = sprintf("mimeType = '%s'", $this->escape($options['mime_type']));
        }

        $terms[] = 'trashed = false';

        $params = ['q' => implode(' and ', $terms)];

        if (isset($options['page_token']) && is_string($options['page_token'])) {
            $params['pageToken'] = $options['page_token'];
        }

        if (isset($options['page_size'])) {
            $params['pageSize'] = max(1, min(100, (int) $options['page_size']));
        }

        return $this->mapList($this->client->listFiles($this->connection(), $params));
    }

    public function get(string $fileId): array
    {
        $file = $this->client->getFile($this->connection(), $fileId);
        $mapped = $this->mapFile($file);
        $mapped['size'] = isset($file['size']) ? (int) $file['size'] : null;
        $mapped['permissions_summary'] = array_map(
            static fn (array $p): array => ['type' => $p['type'], 'role' => $p['role'], 'email' => $p['email']],
            $this->permissions($fileId),
        );

        return $mapped;
    }

    public function listFolder(string $folderId, ?string $pageToken = null): array
    {
        $params = ['q' => sprintf("'%s' in parents and trashed = false", $this->escape($folderId)), 'orderBy' => 'folder,name'];

        if ($pageToken !== null) {
            $params['pageToken'] = $pageToken;
        }

        return $this->mapList($this->client->listFiles($this->connection(), $params));
    }

    /**
     * @return array<int, array{type: string, role: string, email: ?string, domain: ?string}>
     */
    public function permissions(string $fileId): array
    {
        return $this->client->listPermissions($this->connection(), $fileId);
    }

    /**
     * Textinhalt als Auszug: Google-Dokumente per files.export, Textformate per alt=media, alles andere null
     * (kein OCR, "nicht eingerichtet").
     */
    public function textContent(string $fileId, string $mimeType): ?string
    {
        $exports = (array) $this->config->get('hub.drive.excerpt.export_mime_types', []);
        $textTypes = array_map('strval', (array) $this->config->get('hub.drive.excerpt.text_mime_types', []));

        if (isset($exports[$mimeType])) {
            $content = $this->client->exportContent($this->connection(), $fileId, (string) $exports[$mimeType]);
        } elseif (in_array($mimeType, $textTypes, true)) {
            $content = $this->client->downloadContent($this->connection(), $fileId);
        } else {
            return null;
        }

        $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8');

        return mb_substr(trim($content), 0, (int) $this->config->get('hub.drive.excerpt.max_chars', 4000));
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{files: array<int, array{id: string, name: string, mime_type: string, modified_at: ?string, web_view_link: ?string, parents: array<int, string>}>, next_page_token: ?string}
     */
    private function mapList(array $json): array
    {
        $files = [];

        foreach ((array) ($json['files'] ?? []) as $file) {
            if (is_array($file) && ! (bool) ($file['trashed'] ?? false)) {
                $files[] = $this->mapFile($file);
            }
        }

        return ['files' => $files, 'next_page_token' => is_string($json['nextPageToken'] ?? null) ? $json['nextPageToken'] : null];
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array{id: string, name: string, mime_type: string, modified_at: ?string, web_view_link: ?string, parents: array<int, string>}
     */
    private function mapFile(array $file): array
    {
        return [
            'id' => (string) ($file['id'] ?? ''),
            'name' => (string) ($file['name'] ?? ''),
            'mime_type' => (string) ($file['mimeType'] ?? 'application/octet-stream'),
            'modified_at' => isset($file['modifiedTime']) ? (string) $file['modifiedTime'] : null,
            'web_view_link' => isset($file['webViewLink']) ? (string) $file['webViewLink'] : null,
            'parents' => array_values(array_map('strval', (array) ($file['parents'] ?? []))),
        ];
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
