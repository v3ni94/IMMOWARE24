<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Support;

use App\Modules\Mcp\Enums\ToolClass;

/**
 * Deklarative Beschreibung eines MCP-Tools: JSON-Schema der Argumente, Hub-API-Endpunkt, Scope, Klassifikation.
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema  JSON-Schema (Draft 2020-12, Teilmenge) der Argumente
     * @param  array<int, string>  $scopes  Fachliche Scopes der Hub-API (einer genügt), leer bei never_autonomous
     * @param  array<int, string>  $pathParameters  Argumente, die in den Pfad eingesetzt werden ({id})
     * @param  array<int, string>  $queryParameters  Argumente, die als Query-Parameter gesendet werden
     * @param  array<int, string>  $bodyParameters  Argumente, die als JSON-Body gesendet werden
     * @param  array<string, string>  $argumentMap  Umbenennung Argument => API-Parameter
     */
    public function __construct(
        public string $name,
        public string $description,
        public ToolClass $class,
        public array $inputSchema,
        public ?string $method = null,
        public ?string $path = null,
        public array $scopes = [],
        public array $pathParameters = [],
        public array $queryParameters = [],
        public array $bodyParameters = [],
        public array $argumentMap = [],
        public ?string $bodyWrapper = null,
        public ?string $reason = null,
    ) {}

    public function isWrite(): bool
    {
        return $this->class === ToolClass::Write;
    }

    public function isNeverAutonomous(): bool
    {
        return $this->class === ToolClass::NeverAutonomous;
    }

    /**
     * Katalogdarstellung (auch Grundlage für docs/mcp/tools.json).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'class' => $this->class->value,
            'inputSchema' => $this->inputSchema,
            'hub_api' => $this->path !== null ? ['method' => $this->method, 'path' => $this->path] : null,
            'required_scopes' => $this->scopes,
            'implemented' => ! $this->isNeverAutonomous(),
            'reason' => $this->reason,
        ];
    }
}
