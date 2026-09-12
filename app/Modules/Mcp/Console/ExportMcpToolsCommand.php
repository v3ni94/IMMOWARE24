<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Console;

use App\Modules\Mcp\Support\ToolCatalog;
use Illuminate\Console\Command;

/**
 * hub:mcp:export schreibt den Tool-Katalog nach docs/mcp/tools.json.
 */
final class ExportMcpToolsCommand extends Command
{
    protected $signature = 'hub:mcp:export {--path= : Zielpfad relativ zum Projekt oder absolut}';

    protected $description = 'Exportiert den MCP-Tool-Katalog (Schemas, Endpunkte, Scopes, Klassifikation) als JSON-Datei.';

    public function handle(ToolCatalog $catalog): int
    {
        $option = $this->option('path');
        $target = is_string($option) && $option !== '' ? $option : (string) config('hub.mcp.export_path', 'docs/mcp/tools.json');

        if (! str_starts_with($target, DIRECTORY_SEPARATOR)) {
            $target = base_path($target);
        }

        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error(sprintf('Verzeichnis %s konnte nicht angelegt werden.', $directory));

            return self::FAILURE;
        }

        $document = [
            'title' => 'Immoware Hub MCP-Tool-Katalog',
            'version' => (string) config('hub.api.version', 'v1'),
            'generated_by' => 'php artisan hub:mcp:export',
            'hub_base_url' => (string) config('hub.api.base_url', 'https://immoware.muellerhv.de'),
            'endpoints' => [
                'catalog' => ['method' => 'POST', 'path' => '/api/v1/mcp/tools'],
                'call' => ['method' => 'POST', 'path' => '/api/v1/mcp/call', 'body' => ['tool' => 'string', 'arguments' => 'object', 'idempotency_key' => 'string (optional)']],
            ],
            'write_scope' => (string) config('hub.mcp.write_scope', 'mcp:write'),
            'tools' => $catalog->toArray(),
        ];

        $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        if (file_put_contents($target, $json) === false) {
            $this->error(sprintf('Datei %s konnte nicht geschrieben werden.', $target));

            return self::FAILURE;
        }

        $this->info(sprintf('MCP-Tool-Katalog geschrieben: %s (%d Tools, %d Bytes)', $target, count($catalog->all()), strlen($json)));

        return self::SUCCESS;
    }
}
