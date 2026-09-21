<?php

declare(strict_types=1);

namespace App\Modules\Paperless\Services;

use App\Core\Contracts\Mail\PaperlessSourceInterface;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Services\MailFeatureFlags;
use Illuminate\Contracts\Config\Repository;

/**
 * PaperlessSourceInterface gegen eine feste, konfigurierte Paperless-ngx-Instanz (ein Token für den ganzen Hub,
 * keine Verbindung je Organisation wie bei Drive). Objektzuordnung über ein Paperless-Zusatzfeld
 * (hub.paperless.object_number_field_id), in Paperless bereits gepflegt, der Hub legt es nicht an.
 */
final class PaperlessProvider implements PaperlessSourceInterface
{
    public function __construct(
        private readonly PaperlessApiClient $client,
        private readonly Repository $config,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function isConfigured(): bool
    {
        $base = (string) $this->config->get('hub.paperless.base_url', '');
        $token = (string) $this->config->get('hub.paperless.api_token', '');

        return trim($base) !== '' && trim($token) !== '';
    }

    private function guard(): void
    {
        if (! $this->isConfigured()) {
            throw MailIntegrationNotConfiguredException::for('paperless');
        }
    }

    public function search(string $query, array $options = []): array
    {
        $this->guard();

        $params = ['query' => $query];
        $params += $this->pagination($options);

        return $this->mapList($this->client->listDocuments($params));
    }

    public function get(int $documentId): array
    {
        $this->guard();

        $document = $this->client->getDocument($documentId);
        $mapped = $this->mapDocument($document);
        $mapped['content_excerpt'] = $this->excerpt($document);

        return $mapped;
    }

    public function forProperty(string $objectNumber, array $options = []): array
    {
        $this->guard();

        $fieldId = $this->config->get('hub.paperless.object_number_field_id');

        if (! is_numeric($fieldId)) {
            // Ohne gepflegte Feld-ID keine Annahme über den Filteraufbau, lieber leer als falsch zugeordnet.
            return ['documents' => [], 'count' => 0, 'next' => false];
        }

        // Filterparameter für Zusatzfelder sind je Paperless-Version unterschiedlich benannt (custom_field_query
        // oder custom_fields__icontains), am eigenen Server zu prüfen. Hier der dokumentierte Query-Ausdruck.
        $params = ['custom_field_query' => sprintf('["%s", "exact", "%s"]', (int) $fieldId, $objectNumber)];
        $params += $this->pagination($options);

        return $this->mapList($this->client->listDocuments($params));
    }

    public function upload(string $filename, string $content, string $mimeType, string $title, ?string $objectNumber = null): string
    {
        $this->guard();

        if (! $this->flags->paperlessWriteEnabled()) {
            throw MailIntegrationNotConfiguredException::for('paperless_write');
        }

        $customFields = [];
        $fieldId = $this->config->get('hub.paperless.object_number_field_id');

        if (is_numeric($fieldId) && $objectNumber !== null && trim($objectNumber) !== '') {
            $customFields[] = ['field' => (int) $fieldId, 'value' => $objectNumber];
        }

        return $this->client->uploadDocument($filename, $content, $mimeType, $title, $customFields);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function pagination(array $options): array
    {
        $params = [];

        if (isset($options['page'])) {
            $params['page'] = max(1, (int) $options['page']);
        }

        $params['page_size'] = isset($options['page_size']) ? max(1, min(100, (int) $options['page_size'])) : (int) $this->config->get('hub.paperless.page_size', 25);

        return $params;
    }

    /**
     * @param  array<string, mixed>  $json
     * @return array{documents: array<int, array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string}>, count: int, next: bool}
     */
    private function mapList(array $json): array
    {
        $documents = [];

        foreach ((array) ($json['results'] ?? []) as $document) {
            if (is_array($document)) {
                $documents[] = $this->mapDocument($document);
            }
        }

        return [
            'documents' => $documents,
            'count' => (int) ($json['count'] ?? count($documents)),
            'next' => ($json['next'] ?? null) !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string}
     */
    private function mapDocument(array $document): array
    {
        return [
            'id' => (int) ($document['id'] ?? 0),
            'title' => (string) ($document['title'] ?? ''),
            'correspondent' => isset($document['correspondent']) ? (string) $document['correspondent'] : null,
            'document_type' => isset($document['document_type']) ? (string) $document['document_type'] : null,
            'created' => isset($document['created']) ? (string) $document['created'] : null,
            'tags' => array_map('strval', (array) ($document['tags'] ?? [])),
            'object_number' => $this->objectNumberFromCustomFields($document),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function objectNumberFromCustomFields(array $document): ?string
    {
        $fieldId = $this->config->get('hub.paperless.object_number_field_id');

        if (! is_numeric($fieldId)) {
            return null;
        }

        foreach ((array) ($document['custom_fields'] ?? []) as $entry) {
            if (is_array($entry) && (int) ($entry['field'] ?? -1) === (int) $fieldId && isset($entry['value'])) {
                return (string) $entry['value'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function excerpt(array $document): ?string
    {
        $content = $document['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        return mb_substr(trim($content), 0, (int) $this->config->get('hub.paperless.excerpt.max_chars', 4000));
    }
}
