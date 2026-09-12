<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Services;

use App\Core\Support\CorrelationId;
use App\Modules\Mcp\Support\ToolDefinition;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einziger Ausführungsweg eines Tools: ein interner Sub-Request an die Hub-API v1, der durch den Router und
 * damit durch dieselben Middlewares (API-Key-Auth, Scope, Rate Limit, Idempotenz) läuft wie ein externer Aufruf.
 * Der Bearer-Token des MCP-Aufrufers wird unverändert weitergereicht, so dass ein Tool nie mehr kann als der Key.
 * Es gibt keinen direkten Zugriff auf Modelle, DAV-Adapter oder Immoware24.
 */
final class HubApiGateway
{
    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    public function call(ToolDefinition $tool, array $arguments, Request $outer, ?string $idempotencyKey = null): array
    {
        if ($tool->method === null || $tool->path === null) {
            throw new \LogicException(sprintf('Tool %s hat keinen Hub-API-Endpunkt.', $tool->name));
        }

        $path = $tool->path;

        foreach ($tool->pathParameters as $parameter) {
            $path = str_replace('{'.$parameter.'}', rawurlencode((string) ($arguments[$parameter] ?? '')), $path);
        }

        $query = [];

        foreach ($tool->queryParameters as $parameter) {
            $source = $tool->argumentMap[$parameter] ?? $parameter;

            if (array_key_exists($source, $arguments) && $arguments[$source] !== null && $arguments[$source] !== '') {
                $query[$parameter] = is_bool($arguments[$source]) ? ($arguments[$source] ? 'true' : 'false') : (string) $arguments[$source];
            }
        }

        $body = [];

        foreach ($tool->bodyParameters as $parameter) {
            $source = $tool->argumentMap[$parameter] ?? $parameter;

            if (array_key_exists($source, $arguments) && $arguments[$source] !== null) {
                $body[$parameter] = $arguments[$source];
            }
        }

        if ($tool->bodyWrapper !== null) {
            $body = [$tool->bodyWrapper => $body];
        }

        $content = $body === [] ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $server = [
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => (string) $outer->headers->get('Authorization', ''),
            'HTTP_USER_AGENT' => 'ImmowareHub-Mcp/1.0',
            'HTTP_X_CORRELATION_ID' => $this->correlationId->current(),
            'HTTP_X_HUB_TOOL' => $tool->name,
            'REMOTE_ADDR' => (string) ($outer->server->get('REMOTE_ADDR') ?? '127.0.0.1'),
        ];

        if ($tool->isWrite()) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey ?? 'mcp-'.(string) Str::uuid();
        }

        $inner = Request::create($outer->getSchemeAndHttpHost().$path, $tool->method, $query, [], [], $server, $content);
        $inner->headers->set('Accept', 'application/json');

        // FormRequests lösen den Request aus dem Container auf, deshalb wird er für die Dauer des Sub-Requests ausgetauscht.
        $previous = $this->container->bound('request') ? $this->container->make('request') : null;
        $this->container->instance('request', $inner);

        try {
            $response = $this->router->dispatch($inner);
        } finally {
            if ($previous !== null) {
                $this->container->instance('request', $previous);
            }
        }

        return $this->normalize($response);
    }

    /**
     * @return array{status: int, body: array<string, mixed>, headers: array<string, string>}
     */
    private function normalize(Response $response): array
    {
        $raw = (string) $response->getContent();
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        $headers = [];

        foreach (['Location', 'Content-Type', 'Idempotent-Replayed', 'Retry-After'] as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => is_array($decoded) ? $decoded : ['raw' => $raw],
            'headers' => $headers,
        ];
    }
}
