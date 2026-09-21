<?php

declare(strict_types=1);

namespace App\Modules\Paperless\Services;

use App\Modules\Mail\Exceptions\MailRemoteException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * HTTP-Client für Paperless-ngx (Token-Authentifizierung, ein Token für den ganzen Hub). Endpunkte und Feldnamen aus
 * allgemeinem Wissen zur öffentlichen REST-API, am eigenen Server zu prüfen. Sekundäre Wiederholung bei 429 und 5xx,
 * kein Retry bei 401 (fester Token, kein Refresh-Fluss). Secrets erscheinen nie in Exceptions.
 */
final class PaperlessApiClient
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listDocuments(array $query): array
    {
        return $this->json($this->request('get', '/api/documents/', $query));
    }

    /**
     * @return array<string, mixed>
     */
    public function getDocument(int $documentId): array
    {
        return $this->json($this->request('get', '/api/documents/'.$documentId.'/', []));
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    public function listCustomFields(): array
    {
        $json = $this->json($this->request('get', '/api/custom_fields/', ['page_size' => 100]));
        $fields = [];

        foreach ((array) ($json['results'] ?? []) as $field) {
            if (is_array($field) && isset($field['id'], $field['name'])) {
                $fields[] = ['id' => (int) $field['id'], 'name' => (string) $field['name']];
            }
        }

        return $fields;
    }

    /**
     * Legt ein neues Dokument an (multipart, post_document). Paperless verarbeitet asynchron und liefert eine
     * Task-ID (UUID als Text), kein fertiges Dokument. Zusatzfelder werden als JSON-Feld "custom_fields" mitgesendet,
     * sofern eine Feld-ID konfiguriert und eine Objektnummer übergeben ist.
     *
     * @param  array<int, array{field: int, value: string}>  $customFields
     */
    public function uploadDocument(string $filename, string $content, string $mimeType, string $title, array $customFields): string
    {
        $base = $this->baseUrl();
        $multipart = [
            ['name' => 'document', 'contents' => $content, 'filename' => $filename],
            ['name' => 'title', 'contents' => $title],
        ];

        if ($customFields !== []) {
            $multipart[] = ['name' => 'custom_fields', 'contents' => json_encode($customFields, JSON_THROW_ON_ERROR)];
        }

        $response = Http::withToken((string) $this->config->get('hub.paperless.api_token'), 'Token')
            ->connectTimeout((int) $this->config->get('hub.paperless.connect_timeout_seconds', 5))
            ->timeout((int) $this->config->get('hub.paperless.timeout_seconds', 20))
            ->asMultipart()
            ->post($base.'/api/documents/post_document/', $multipart);

        if ($response->status() >= 400) {
            throw new MailRemoteException(sprintf('Paperless lehnt Upload ab (HTTP %d).', $response->status()), 'paperless', $response->status(), mb_substr($response->body(), 0, 300));
        }

        return trim($response->body(), "\" \t\n\r\0\x0B");
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function request(string $method, string $path, array $query): Response
    {
        $base = $this->baseUrl();
        $token = (string) $this->config->get('hub.paperless.api_token');
        $times = max(0, (int) $this->config->get('hub.paperless.retry.times', 2));
        $sleeps = array_values(array_map('intval', (array) $this->config->get('hub.paperless.retry.sleep_ms', [300, 1000])));
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = Http::withToken($token, 'Token')
                    ->connectTimeout((int) $this->config->get('hub.paperless.connect_timeout_seconds', 5))
                    ->timeout((int) $this->config->get('hub.paperless.timeout_seconds', 20))
                    ->{$method}($base.$path, $query);
            } catch (ConnectionException) {
                if ($attempt > $times) {
                    throw new MailRemoteException('Paperless nicht erreichbar (Transport).', 'paperless');
                }

                $this->pause($sleeps, $attempt);

                continue;
            }

            $status = $response->status();

            if ($status === 429 || $status >= 500) {
                if ($attempt > $times) {
                    throw new MailRemoteException(sprintf('Paperless antwortet mit HTTP %d.', $status), 'paperless', $status, mb_substr($response->body(), 0, 300));
                }

                $this->pause($sleeps, $attempt);

                continue;
            }

            if ($status >= 400) {
                throw new MailRemoteException(sprintf('Paperless lehnt Anfrage ab (HTTP %d).', $status), 'paperless', $status, mb_substr($response->body(), 0, 300));
            }

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
            throw new MailRemoteException('Paperless antwortet ohne JSON.', 'paperless', $response->status());
        }

        return $json;
    }

    private function baseUrl(): string
    {
        return rtrim((string) $this->config->get('hub.paperless.base_url'), '/');
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
}
