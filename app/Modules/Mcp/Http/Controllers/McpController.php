<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Controllers;

use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Mcp\Services\ToolExecutor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/mcp/tools liefert den Tool-Katalog, POST /api/v1/mcp/call führt ein Tool aus ({tool, arguments}).
 * Beide Endpunkte sind API-Key-geschützt. Die Ausführung läuft intern über die Hub-API v1, nie an Immoware24.
 */
final class McpController
{
    public function __construct(
        private readonly ToolExecutor $executor,
        private readonly ApiResponse $response,
    ) {}

    public function tools(Request $request): JsonResponse
    {
        $tools = $this->executor->catalogFor($request);

        return $this->response->raw([
            'tools' => $tools,
            'call_endpoint' => ['method' => 'POST', 'path' => '/api/v1/mcp/call', 'body' => ['tool' => 'string', 'arguments' => 'object', 'idempotency_key' => 'string, optional, nur Write-Tools']],
            'permission_layer' => 'docs/immoware/08-security.md Abschnitt 8, docs/mcp/README.md',
        ], ['total' => count($tools)]);
    }

    public function call(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $tool = $payload['tool'] ?? null;
        $arguments = $payload['arguments'] ?? [];
        $idempotencyKey = $payload['idempotency_key'] ?? null;

        if (! is_string($tool) || trim($tool) === '') {
            throw ApiProblemException::badRequest('bad_request', 'Das Feld "tool" (String) ist erforderlich.');
        }

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            throw ApiProblemException::badRequest('bad_request', 'Das Feld "arguments" muss ein Objekt sein.');
        }

        if ($idempotencyKey !== null && (! is_string($idempotencyKey) || preg_match('/^[A-Za-z0-9._:-]{1,128}$/', $idempotencyKey) !== 1)) {
            throw ApiProblemException::badRequest('bad_request', 'Das Feld "idempotency_key" darf höchstens 128 Zeichen aus A-Z, a-z, 0-9, Punkt, Doppelpunkt, Unterstrich und Bindestrich enthalten.');
        }

        /** @var array<string, mixed> $arguments */
        $data = $this->executor->execute(trim($tool), $arguments, $request, $idempotencyKey);

        return $this->response->raw($data);
    }
}
