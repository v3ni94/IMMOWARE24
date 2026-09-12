<?php

declare(strict_types=1);

namespace App\Modules\Api\OpenApi;

use App\Modules\Api\Support\ResourceDefinition;
use App\Modules\Api\Support\ResourceRegistry;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Erzeugt eine OpenAPI-3.1-Spezifikation aus den registrierten Routen (api/v1, api/docs, health) und der
 * SchemaRegistry. Jede Operation trägt x-scope, x-effect, x-evidence-status und x-phase.
 */
final class OpenApiGenerator
{
    public function __construct(
        private readonly Router $router,
        private readonly ResourceRegistry $resources,
        private readonly SchemaRegistry $schemas,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function generate(): array
    {
        $paths = [];

        foreach ($this->router->getRoutes()->getRoutes() as $route) {
            if (! $route instanceof Route || ! $this->included($route)) {
                continue;
            }

            $path = '/'.ltrim($route->uri(), '/');

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][strtolower($method)] = $this->operation($route, $method, $path);
            }
        }

        ksort($paths);

        $baseUrl = rtrim((string) config('hub.api.base_url', 'https://immoware.muellerhv.de'), '/');

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => (string) config('hub.api.openapi.title', 'Immoware Hub API'),
                'version' => (string) config('hub.api.version', 'v1'),
                'summary' => 'REST-API des Immoware Hub für Spiegeldaten aus Immoware24, Hub-Vorgänge, Verzeichnis und Health.',
                'description' => $this->description(),
                'contact' => ['name' => 'Hausverwaltung Müller GmbH'],
            ],
            'servers' => [['url' => $baseUrl, 'description' => 'Produktion']],
            'tags' => $this->tags(),
            'paths' => $paths,
            'webhooks' => $this->webhooks(),
            'components' => [
                'securitySchemes' => [
                    'apiKey' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'hub_live_<prefix>_<secret>',
                        'description' => 'API-Key im Authorization-Header. Scopes je Key, siehe x-scope je Operation. Der Scope admin umfasst alle Scopes.',
                    ],
                ],
                'parameters' => $this->commonParameters(),
                'headers' => [
                    'RateLimit-Limit' => ['schema' => ['type' => 'integer'], 'description' => 'Erlaubte Requests pro Minute.'],
                    'RateLimit-Remaining' => ['schema' => ['type' => 'integer']],
                    'Retry-After' => ['schema' => ['type' => 'integer'], 'description' => 'Sekunden bis zum nächsten Versuch (bei 429).'],
                    'X-Correlation-Id' => ['schema' => ['type' => 'string'], 'description' => 'Wird gespiegelt oder erzeugt, erscheint als request_id im Fehlerobjekt.'],
                    'Idempotent-Replayed' => ['schema' => ['type' => 'boolean'], 'description' => 'true, wenn eine gespeicherte Antwort geliefert wurde.'],
                ],
                'responses' => $this->commonResponses(),
                'schemas' => $this->schemas->schemas(),
            ],
            'security' => [['apiKey' => []]],
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->generate(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function included(Route $route): bool
    {
        $uri = $route->uri();

        return $uri === 'api/v1' || str_starts_with($uri, 'api/v1/') || str_starts_with($uri, 'api/docs') || $uri === 'health' || str_starts_with($uri, 'health/');
    }

    /**
     * @return array<string, mixed>
     */
    private function operation(Route $route, string $method, string $path): array
    {
        $scopes = $this->scopes($route);
        $definition = $this->definitionFor($route);
        $isList = $definition !== null && ! str_contains($path, '{') && $method === 'GET';
        $isHealth = str_starts_with($path, '/health');
        $isDocs = str_starts_with($path, '/api/docs');
        $tag = $this->tag($path, $definition);

        $operation = [
            'operationId' => $this->operationId($route, $method, $path),
            'summary' => $this->summary($method, $path, $definition),
            'tags' => [$tag],
            'parameters' => [],
            'responses' => [],
            'x-scope' => $scopes,
            'x-effect' => $this->effect($method, $definition, $path),
            'x-evidence-status' => $definition !== null ? $definition->evidenceStatus : ($isHealth || $isDocs ? null : 'HUB'),
            'x-phase' => $definition !== null ? $definition->phase : (str_starts_with($path, '/api/v1/webhook-endpoints') ? 4 : 1),
        ];

        foreach ($route->parameterNames() as $name) {
            $operation['parameters'][] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
            ];
        }

        if ($isList) {
            foreach (['page', 'per_page', 'sort', 'fields', 'q', 'updated_since', 'external_id', 'status', 'include_deleted'] as $ref) {
                $operation['parameters'][] = ['$ref' => '#/components/parameters/'.$ref];
            }

            foreach (array_keys($definition->filters) as $filter) {
                $operation['parameters'][] = ['name' => $filter, 'in' => 'query', 'required' => false, 'schema' => ['type' => str_ends_with($filter, '_id') ? 'integer' : 'string']];
            }
        }

        if (str_starts_with($path, '/api/v1/directory')) {
            $operation['parameters'][] = ['name' => 'format', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['json', 'vcf', 'xml']], 'description' => 'json (Standard), vcf (vCard 3.0 Liste), xml (generische Telefonbuchstruktur).'];
            $operation['parameters'][] = ['$ref' => '#/components/parameters/page'];
            $operation['parameters'][] = ['$ref' => '#/components/parameters/per_page'];

            if (str_ends_with($path, '/search')) {
                $operation['parameters'][] = ['name' => 'q', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 2], 'description' => 'Suche über Name, Firma, Telefon, Mobil, Rolle, Objekt, Einheit.'];
            }
        }

        if (in_array($method, ['POST', 'PATCH'], true) && str_starts_with($path, '/api/v1/')) {
            $operation['parameters'][] = ['$ref' => '#/components/parameters/IdempotencyKey'];
            $operation['requestBody'] = $this->requestBody($method, $path);
        }

        $operation['responses'] = $this->responses($method, $path, $definition, $isList, $isHealth, $isDocs);

        if ($isHealth) {
            $operation['security'] = [[], ['apiKey' => []]];
        }

        return $operation;
    }

    /**
     * @return array<int, string>
     */
    private function scopes(Route $route): array
    {
        $scopes = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if (str_starts_with($middleware, 'api.scope:') || str_starts_with($middleware, 'scope:')) {
                $scopes = array_merge($scopes, explode(',', explode(':', $middleware, 2)[1]));
            }
        }

        return array_values(array_unique($scopes));
    }

    private function definitionFor(Route $route): ?ResourceDefinition
    {
        $defaults = $route->defaults;
        $name = $defaults['resource'] ?? null;

        if (is_string($name) && $this->resources->has($name)) {
            return $this->resources->get($name);
        }

        foreach ($this->resources->all() as $definition) {
            if (str_starts_with('/'.$route->uri(), $definition->href())) {
                return $definition;
            }
        }

        return null;
    }

    private function tag(string $path, ?ResourceDefinition $definition): string
    {
        if ($definition !== null) {
            return $definition->name;
        }

        return match (true) {
            str_starts_with($path, '/health') => 'health',
            str_starts_with($path, '/api/docs') => 'docs',
            str_starts_with($path, '/api/v1/directory') => 'directory',
            str_starts_with($path, '/api/v1/webhook') => 'webhooks',
            default => 'meta',
        };
    }

    private function effect(string $method, ?ResourceDefinition $definition, string $path): string
    {
        if ($method === 'GET') {
            return 'hub';
        }

        if ($definition !== null && $definition->name === 'documents' && $method === 'POST') {
            return 'immoware24';
        }

        return 'hub';
    }

    private function operationId(Route $route, string $method, string $path): string
    {
        $name = $route->getName();

        if (is_string($name) && $name !== '') {
            return str_replace(['.', '-'], '_', $name);
        }

        $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($path, '/'))) ?? '';

        return strtolower($method).'_'.trim($slug, '_');
    }

    private function summary(string $method, string $path, ?ResourceDefinition $definition): string
    {
        $hasId = str_contains($path, '{');

        if ($definition !== null) {
            return match ($method) {
                'GET' => $hasId ? sprintf('%s einzeln lesen', $definition->name) : sprintf('%s auflisten', $definition->name),
                'POST' => $definition->name === 'documents' ? 'Upload-Antrag für den Posteingang anlegen' : sprintf('%s anlegen', $definition->name),
                'PATCH' => $definition->name === 'contacts' ? 'Änderungsvorschlag für Kontakt anlegen (kein Writeback)' : sprintf('%s ändern', $definition->name),
                default => $method.' '.$path,
            };
        }

        return match (true) {
            $path === '/api/v1' => 'Ressourcenverzeichnis',
            $path === '/api/v1/me' => 'Aktueller Aufrufer',
            $path === '/api/v1/sync/status' => 'Synchronisationsstatus je Connection',
            $path === '/api/v1/capabilities' => 'Capability Registry',
            $path === '/api/v1/directory' => 'Telefonbuch',
            $path === '/api/v1/directory/search' => 'Telefonbuch durchsuchen',
            $path === '/health' => 'Aggregierter Health-Status',
            str_starts_with($path, '/health/') => 'Health-Check '.substr($path, 8),
            str_starts_with($path, '/api/docs') => 'API-Dokumentation',
            str_starts_with($path, '/api/v1/webhook-endpoints') => $method === 'GET' ? 'Webhook-Endpunkte auflisten' : ($method === 'POST' ? 'Webhook-Endpunkt anlegen' : 'Webhook-Endpunkt entfernen'),
            default => $method.' '.$path,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(string $method, string $path): array
    {
        $schema = match (true) {
            str_starts_with($path, '/api/v1/cases') => 'CaseWrite',
            str_starts_with($path, '/api/v1/contacts') => 'ContactProposal',
            str_starts_with($path, '/api/v1/documents') => 'DocumentUpload',
            str_starts_with($path, '/api/v1/webhook-endpoints') => 'WebhookEndpointWrite',
            default => null,
        };

        if ($schema === null) {
            return ['content' => ['application/json' => ['schema' => ['type' => 'object']]]];
        }

        $contentType = $schema === 'DocumentUpload' ? 'multipart/form-data' : 'application/json';

        return ['required' => true, 'content' => [$contentType => ['schema' => ['$ref' => '#/components/schemas/'.$schema]]]];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function responses(string $method, string $path, ?ResourceDefinition $definition, bool $isList, bool $isHealth, bool $isDocs): array
    {
        $problem = static fn (string $ref): array => ['$ref' => '#/components/responses/'.$ref];

        if ($isHealth) {
            return [
                '200' => ['description' => 'Dienst gesund oder degraded', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Health']]]],
                '503' => ['description' => 'Mindestens ein Check ist down (z. B. Spiegeldaten stale)', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Health']]]],
            ];
        }

        if ($isDocs) {
            return [
                '200' => ['description' => str_ends_with($path, '.json') ? 'OpenAPI-Spezifikation' : 'HTML-Dokumentation'],
                '401' => $problem('Unauthenticated'),
            ];
        }

        $responses = [
            '401' => $problem('Unauthenticated'),
            '403' => $problem('Forbidden'),
            '429' => $problem('RateLimited'),
        ];

        if ($definition !== null && $method === 'GET') {
            $schemaRef = ['$ref' => '#/components/schemas/'.$this->schemas->schemaName($definition)];
            $responses['200'] = $isList
                ? ['description' => 'Seite', 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'properties' => ['data' => ['type' => 'array', 'items' => $schemaRef], 'meta' => ['$ref' => '#/components/schemas/PageMeta'], 'links' => ['$ref' => '#/components/schemas/Links']],
                ]]]]
                : ['description' => 'Einzelressource', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => $schemaRef, 'meta' => ['type' => 'object']]]]]];

            if (! $isList) {
                $responses['404'] = $problem('NotFound');
            } else {
                $responses['400'] = $problem('BadRequest');
            }

            return $responses;
        }

        if ($definition !== null) {
            $responses['400'] = $problem('BadRequest');
            $responses['409'] = $problem('Conflict');
            $responses['422'] = $problem('ValidationFailed');

            if ($definition->name === 'documents') {
                $responses['202'] = ['description' => 'Upload-Antrag angenommen', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/DocumentUploadResult']]]]]];
                $responses['501'] = $problem('NotImplemented');
            } elseif ($definition->name === 'contacts') {
                $responses['202'] = ['description' => 'Änderungsvorschlag angelegt', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/ContactProposalResult']]]]]];
                $responses['404'] = $problem('NotFound');
            } else {
                $responses[$method === 'POST' ? '201' : '200'] = ['description' => 'Vorgang', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/Case']]]]]];
                $responses['404'] = $problem('NotFound');
            }

            return $responses;
        }

        if (str_starts_with($path, '/api/v1/directory')) {
            $responses['200'] = ['description' => 'Verzeichnis', 'content' => [
                'application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/DirectoryEntry']], 'meta' => ['$ref' => '#/components/schemas/PageMeta']]]],
                'text/vcard' => ['schema' => ['type' => 'string']],
                'application/xml' => ['schema' => ['type' => 'string']],
            ]];
            $responses['400'] = $problem('BadRequest');

            return $responses;
        }

        if (str_starts_with($path, '/api/v1/webhook-endpoints')) {
            $responses[$method === 'POST' ? '201' : ($method === 'DELETE' ? '204' : '200')] = ['description' => 'Webhook-Endpunkt', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['$ref' => '#/components/schemas/WebhookEndpoint']]]]]];
            $responses['422'] = $problem('ValidationFailed');
            $responses['404'] = $problem('NotFound');

            return $responses;
        }

        $responses['200'] = ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['data' => ['type' => 'object'], 'meta' => ['type' => 'object']]]]]];

        return $responses;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function commonParameters(): array
    {
        $max = (int) config('hub.api.pagination.max_per_page', 500);

        return [
            'page' => ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
            'per_page' => ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => $max, 'default' => (int) config('hub.api.pagination.default_per_page', 100)], 'description' => 'Maximal '.$max.'.'],
            'sort' => ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'default' => '-updated_at'], 'description' => 'Feld, optional mit führendem Minus für absteigend. Erlaubte Felder je Ressource.'],
            'fields' => ['name' => 'fields', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Sparse Fieldsets, kommagetrennt. id und provenance werden immer ausgegeben.'],
            'q' => ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Volltextsuche über die suchbaren Spalten der Ressource.'],
            'updated_since' => ['name' => 'updated_since', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date-time'], 'description' => 'Änderung im Hub seit diesem Zeitpunkt (Hub-updated_at, nicht Immoware24).'],
            'external_id' => ['name' => 'external_id', 'in' => 'query', 'schema' => ['type' => 'string'], 'description' => 'Exakter externer Schlüssel des Quellsystems.'],
            'status' => ['name' => 'status', 'in' => 'query', 'schema' => ['type' => 'string']],
            'include_deleted' => ['name' => 'include_deleted', 'in' => 'query', 'schema' => ['type' => 'boolean', 'default' => false], 'description' => 'Soft-gelöschte Spiegeldaten einbeziehen.'],
            'IdempotencyKey' => ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'maxLength' => 128], 'description' => 'Pflicht für POST und PATCH. Wiederholung liefert die gespeicherte Antwort (24 Stunden) mit Header Idempotent-Replayed: true; abweichender Body liefert 409 idempotency_mismatch.'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function commonResponses(): array
    {
        $problem = static fn (string $description, array $headers = []): array => array_filter([
            'description' => $description,
            'headers' => $headers,
            'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Problem']]],
        ]);

        return [
            'BadRequest' => $problem('400 bad_request, invalid_filter, idempotency_key_required'),
            'Unauthenticated' => $problem('401 unauthenticated, key_expired, key_revoked', ['WWW-Authenticate' => ['schema' => ['type' => 'string']]]),
            'Forbidden' => $problem('403 insufficient_scope, role_forbidden, ip_not_allowed, write_disabled, connection_degraded, capability_locked'),
            'NotFound' => $problem('404 not_found'),
            'Conflict' => $problem('409 conflict, write_target_exists, idempotency_mismatch'),
            'ValidationFailed' => $problem('422 validation_failed mit Array errors'),
            'RateLimited' => $problem('429 rate_limited', ['Retry-After' => ['$ref' => '#/components/headers/Retry-After'], 'RateLimit-Limit' => ['$ref' => '#/components/headers/RateLimit-Limit']]),
            'NotImplemented' => $problem('501 not_implemented, Hinweis WAITING_FOR_MODULE im Feld hint'),
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function tags(): array
    {
        $tags = [
            ['name' => 'meta', 'description' => 'Verzeichnis, Aufrufer, Sync-Status, Capabilities'],
            ['name' => 'directory', 'description' => 'Telefonbuch aus dem Kontaktspiegel'],
            ['name' => 'health', 'description' => 'Health-Checks ohne Pflicht-Auth, Details nur mit Scope admin'],
            ['name' => 'docs', 'description' => 'OpenAPI und HTML-Dokumentation'],
            ['name' => 'webhooks', 'description' => 'Verwaltung ausgehender Webhook-Endpunkte'],
        ];

        foreach ($this->resources->all() as $definition) {
            $tags[] = ['name' => $definition->name, 'description' => $definition->description];
        }

        return $tags;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function webhooks(): array
    {
        $result = [];
        $signatureHeader = (string) config('hub.webhooks.signature.header', 'X-Hub-Signature');

        foreach ((array) config('hub.webhooks.events', []) as $event => $description) {
            $result[(string) $event] = [
                'post' => [
                    'summary' => (string) $description,
                    'description' => sprintf(
                        'Signatur: Header %s mit t=<unix-sekunden>,v1=<hex HMAC-SHA256 über "<t>.<body>">. Header X-Hub-Event und X-Hub-Delivery. Zeitstempel älter als %d Sekunden verwerfen (Replay-Schutz). Antwort 2xx gilt als zugestellt, Timeout %d Sekunden, Wiederholung nach %s Sekunden, danach DLQ.',
                        $signatureHeader,
                        (int) config('hub.webhooks.replay_window_seconds', 300),
                        (int) config('hub.webhooks.timeout_seconds', 10),
                        implode(', ', (array) config('hub.webhooks.backoff_seconds', [])),
                    ),
                    'requestBody' => ['content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/WebhookPayload']]]],
                    'responses' => ['2XX' => ['description' => 'Zugestellt']],
                ],
            ];
        }

        return $result;
    }

    private function description(): string
    {
        $read = (int) config('hub.api.rate_limits.read_per_minute', 600);
        $write = (int) config('hub.api.rate_limits.write_per_minute', 60);

        return implode("\n\n", [
            'Alle Ressourcen liefern Spiegeldaten mit Herkunftsblock provenance (source_system, external_id, last_synced_at, connector, mapping_version, data_age_seconds, stale). Immoware24 ist Master; der Hub schreibt ausschließlich neue Dateien in den WebDAV-Posteingang (POST /api/v1/documents) und legt für alle anderen Änderungswünsche Änderungsvorschläge an.',
            '**Authentifizierung**: Authorization: Bearer hub_live_<prefix>_<secret>. Jede Operation nennt in x-scope die benötigten Scopes; admin umfasst alle Scopes.',
            '**Pagination**: page und per_page (maximal '.(int) config('hub.api.pagination.max_per_page', 500).'), Antwort {data, meta:{page, per_page, total}, links}. Sortierung über sort=-updated_at, Feldauswahl über fields=.',
            '**Fehler**: application/problem+json nach RFC 7807 mit type unter https://immoware.muellerhv.de/errors/<code>, request_id entspricht X-Correlation-Id.',
            sprintf('**Rate Limits**: %d Requests pro Minute lesend, %d pro Minute schreibend je Key. Header RateLimit-Limit, RateLimit-Remaining, bei 429 Retry-After.', $read, $write),
            '**Idempotenz**: Header Idempotency-Key ist für POST und PATCH Pflicht. Antworten werden 24 Stunden gespeichert und mit Idempotent-Replayed: true wiederholt.',
            '**Webhooks**: ausgehend, HMAC-SHA256-signiert, Ereigniskatalog im Abschnitt webhooks. Es gibt keine eingehenden Webhooks von Immoware24.',
        ]);
    }
}
