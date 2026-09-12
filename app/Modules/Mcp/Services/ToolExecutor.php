<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Mcp\Support\ToolCatalog;
use App\Modules\Mcp\Support\ToolDefinition;
use Illuminate\Http\Request;

/**
 * Permission Layer des KI/MCP-Zugangs (08-security.md Abschnitt 8):
 * 1. Tool muss im Katalog stehen, never_autonomous-Tools sind nie ausführbar.
 * 2. Der API-Key braucht den fachlichen Scope des Endpunkts (oder admin).
 * 3. Write-Tools brauchen zusätzlich den Scope mcp:write, ausdrücklich, auch bei admin.
 * 4. Argumente werden gegen das JSON-Schema geprüft.
 * 5. Ausführung ausschließlich über die Hub-API (HubApiGateway).
 * 6. Write-Tools werden mit Quelle mcp auditiert, Read-Tools optional.
 */
final class ToolExecutor
{
    public function __construct(
        private readonly ToolCatalog $catalog,
        private readonly ArgumentValidator $validator,
        private readonly HubApiGateway $gateway,
        private readonly ApiCaller $caller,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
    ) {}

    /**
     * Katalog aus Sicht des Aufrufers: jedes Tool mit Kennzeichen, ob der Key es ausführen dürfte.
     *
     * @return array<int, array<string, mixed>>
     */
    public function catalogFor(Request $request): array
    {
        $result = [];

        foreach ($this->catalog->all() as $tool) {
            $entry = $tool->toArray();
            $entry['callable_with_current_key'] = ! $tool->isNeverAutonomous() && $this->missingScopes($tool, $request) === [];
            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $arguments, Request $request, ?string $idempotencyKey = null): array
    {
        if (! $this->catalog->has($toolName)) {
            throw new ApiProblemException(404, 'unknown_tool', sprintf('Das Tool "%s" ist im Katalog nicht vorhanden. Katalog: POST /api/v1/mcp/tools.', $toolName));
        }

        $tool = $this->catalog->get($toolName);

        if ($tool->isNeverAutonomous()) {
            throw new ApiProblemException(403, 'never_autonomous', sprintf(
                'Das Tool "%s" darf nie autonom ausgeführt werden und ist nicht implementiert. Diese Handlung nimmt ausschließlich ein Mensch in Immoware24 vor. %s',
                $tool->name,
                (string) $tool->reason,
            ), ['tool' => $tool->name, 'class' => $tool->class->value]);
        }

        $missing = $this->missingScopes($tool, $request);

        if ($missing !== []) {
            $code = in_array($this->writeScope(), $missing, true) && count($missing) === 1 ? 'mcp_write_scope_required' : 'insufficient_scope';

            throw new ApiProblemException(403, $code, sprintf('Für das Tool "%s" fehlt dem API-Key der Scope: %s.', $tool->name, implode(', ', $missing)), [
                'tool' => $tool->name,
                'missing_scopes' => $missing,
            ]);
        }

        $errors = $this->validator->validate($tool->inputSchema, $arguments);

        if ($errors !== []) {
            throw new ApiProblemException(422, 'validation_failed', 'Die Tool-Argumente entsprechen nicht dem Schema.', ['tool' => $tool->name, 'errors' => $errors]);
        }

        $result = $this->gateway->call($tool, $arguments, $request, $idempotencyKey);

        if ($tool->isWrite() || (bool) config('hub.mcp.audit_reads', false)) {
            $this->audit->log(
                'mcp.tool.called',
                null,
                [],
                [
                    'tool' => $tool->name,
                    'class' => $tool->class->value,
                    'hub_api' => $tool->method.' '.$tool->path,
                    'arguments' => $arguments,
                    'response_status' => $result['status'],
                    'api_key_id' => $this->caller->apiKeyId($request),
                ],
                AuditSource::Mcp->value,
                $this->correlationId->current(),
            );
        }

        return [
            'tool' => $tool->name,
            'class' => $tool->class->value,
            'effect' => $tool->isWrite() ? 'hub' : 'none',
            'hub_api' => ['method' => $tool->method, 'path' => $tool->path, 'status' => $result['status']],
            'result' => $result['body'],
            'note' => $tool->isWrite()
                ? 'Änderung nur im Hub. Es wurde nichts nach Immoware24 geschrieben; die Umsetzung erfolgt durch einen Menschen.'
                : 'Spiegeldaten des Hubs. Datenalter und Herkunft stehen in meta.source und provenance.',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function missingScopes(ToolDefinition $tool, Request $request): array
    {
        $missing = [];
        $hasBusinessScope = $tool->scopes === [];

        foreach ($tool->scopes as $scope) {
            if ($this->caller->hasScope($request, $scope)) {
                $hasBusinessScope = true;
                break;
            }
        }

        if (! $hasBusinessScope) {
            $missing[] = implode(' oder ', $tool->scopes);
        }

        if ($tool->isWrite() && ! in_array($this->writeScope(), $this->caller->scopes($request), true)) {
            $missing[] = $this->writeScope();
        }

        return $missing;
    }

    private function writeScope(): string
    {
        return (string) config('hub.mcp.write_scope', 'mcp:write');
    }
}
