<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Mcp. Zugriff über config('hub.mcp.*').
 * Grundlage: docs/immoware/08-security.md Abschnitt 8 (KI/MCP-Permission-Layer) und docs/mcp/README.md.
 *
 * Der Hub stellt keinen MCP-Server im Sinne des Protokolls bereit, sondern einen Tool-Katalog und einen
 * Aufruf-Endpunkt über die Hub-API. Ein späterer MCP-Server (stdio) ist ein dünner Client dieser Endpunkte.
 */
return [

    // Schaltet die Endpunkte /api/v1/mcp/tools und /api/v1/mcp/call frei. Standard: aktiv, da nur Hub-API gespiegelt wird.
    'enabled' => (bool) env('HUB_MCP_ENABLED', true),

    // Zusätzlicher Scope, den jeder Write-Tool-Aufruf neben dem fachlichen Scope benötigt.
    'write_scope' => 'mcp:write',

    // Scopes, die das Modul dem Katalog bekannter API-Key-Scopes hinzufügt (hub.security.api_keys.scopes).
    'additional_scopes' => ['mcp:write'],

    // Audit: Write-Tools werden immer protokolliert (Quelle mcp). Read-Tools nur, wenn hier aktiviert.
    'audit_reads' => (bool) env('HUB_MCP_AUDIT_READS', false),

    // Obergrenze für per_page bei Such-Tools, unabhängig vom API-Maximum (Kontextfenster eines Assistenten).
    'max_per_page' => 50,

    'export_path' => 'docs/mcp/tools.json',

    // Präfix aller Tool-Namen.
    'tool_prefix' => 'immoware_',
];
