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
        $companyQuery = $this->companyCondition($options);

        if ($companyQuery !== null) {
            $params['custom_field_query'] = $companyQuery;
        }

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

        // Feldwerte in Paperless: "523" oder "602, Bedburg, Am Fließ 6" (Objektnummer, Ort, Straße). Deshalb exakt ODER
        // mit "<Nummer>, " beginnend; ein reines istartswith auf die Nummer träfe auch 5230.
        $objectCondition = ['OR', [[(int) $fieldId, 'exact', $objectNumber], [(int) $fieldId, 'istartswith', $objectNumber.', ']]];
        $conditions = [$objectCondition];
        $companyId = $this->companyOptionId($options['company'] ?? null);

        if ($companyId !== null) {
            $conditions[] = [(int) $this->config->get('hub.paperless.company_field_id'), 'exact', $companyId];
        }

        $params = ['custom_field_query' => $this->encodeCustomFieldQuery($conditions)];
        $params += $this->pagination($options);

        return $this->mapList($this->client->listDocuments($params));
    }

    public function upload(string $filename, string $content, string $mimeType, string $title, ?string $objectNumber = null, ?string $company = null): string
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

        $companyId = $this->companyOptionId($company);
        $companyFieldId = $this->config->get('hub.paperless.company_field_id');

        if ($companyId !== null && is_numeric($companyFieldId)) {
            $customFields[] = ['field' => (int) $companyFieldId, 'value' => $companyId];
        }

        return $this->client->uploadDocument($filename, $content, $mimeType, $title, $customFields);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function companyCondition(array $options): ?string
    {
        $companyId = $this->companyOptionId($options['company'] ?? null);
        $fieldId = $this->config->get('hub.paperless.company_field_id');

        if ($companyId === null || ! is_numeric($fieldId)) {
            return null;
        }

        return $this->encodeCustomFieldQuery([[(int) $fieldId, 'exact', $companyId]]);
    }

    /**
     * Bildet ein Gesellschafts-Label (z. B. "HVM") auf die in Paperless hinterlegte Options-ID ab.
     */
    private function companyOptionId(mixed $companyLabel): ?string
    {
        if (! is_string($companyLabel) || trim($companyLabel) === '') {
            return null;
        }

        foreach ((array) $this->config->get('hub.paperless.company_options', []) as $optionId => $label) {
            if (strcasecmp((string) $label, $companyLabel) === 0) {
                return (string) $optionId;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, mixed>>  $conditions
     */
    private function encodeCustomFieldQuery(array $conditions): string
    {
        if (count($conditions) === 1) {
            return json_encode($conditions[0], JSON_THROW_ON_ERROR);
        }

        return json_encode(['AND', $conditions], JSON_THROW_ON_ERROR);
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
     * @return array{documents: array<int, array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string, company: ?string}>, count: int, next: bool}
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
     * @return array{id: int, title: string, correspondent: ?string, document_type: ?string, created: ?string, tags: array<int, string>, object_number: ?string, company: ?string}
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
            'object_number' => $this->customFieldValue($document, $this->config->get('hub.paperless.object_number_field_id')),
            'company' => $this->companyLabel($document),
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private function customFieldValue(array $document, mixed $fieldId): ?string
    {
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
    private function companyLabel(array $document): ?string
    {
        $optionId = $this->customFieldValue($document, $this->config->get('hub.paperless.company_field_id'));

        if ($optionId === null) {
            return null;
        }

        $options = (array) $this->config->get('hub.paperless.company_options', []);

        return isset($options[$optionId]) ? (string) $options[$optionId] : null;
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
