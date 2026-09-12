<?php

declare(strict_types=1);

namespace App\Core\Contracts\Mail;

/**
 * Interner Adaptervertrag für eine lesende Dokumentenquelle (Google Drive). Kein Herstellerendpunkt, kein Schreibzugriff.
 */
interface DocumentSourceInterface
{
    /**
     * @param  array<string, mixed>  $options  z. B. ['folder_id' => ..., 'mime_type' => ..., 'page_token' => ..., 'page_size' => 50]
     * @return array{files: array<int, array{id: string, name: string, mime_type: string, modified_at: ?string, web_view_link: ?string, parents: array<int, string>}>, next_page_token: ?string}
     */
    public function search(string $query, array $options = []): array;

    /**
     * @return array{id: string, name: string, mime_type: string, size: ?int, modified_at: ?string, web_view_link: ?string, parents: array<int, string>, permissions_summary: array<int, array{type: string, role: string, email: ?string}>}
     */
    public function get(string $fileId): array;

    /**
     * @return array{files: array<int, array{id: string, name: string, mime_type: string, modified_at: ?string, web_view_link: ?string, parents: array<int, string>}>, next_page_token: ?string}
     */
    public function listFolder(string $folderId, ?string $pageToken = null): array;
}
