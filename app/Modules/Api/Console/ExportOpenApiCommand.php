<?php

declare(strict_types=1);

namespace App\Modules\Api\Console;

use App\Modules\Api\OpenApi\OpenApiGenerator;
use Illuminate\Console\Command;

/**
 * hub:openapi:export schreibt die generierte Spezifikation nach docs/api/openapi.json.
 */
final class ExportOpenApiCommand extends Command
{
    protected $signature = 'hub:openapi:export {--path= : Zielpfad relativ zum Projekt oder absolut}';

    protected $description = 'Exportiert die OpenAPI-3.1-Spezifikation der Hub-API als JSON-Datei.';

    public function handle(OpenApiGenerator $generator): int
    {
        $option = $this->option('path');
        $target = is_string($option) && $option !== '' ? $option : (string) config('hub.api.openapi.export_path', 'docs/api/openapi.json');

        if (! str_starts_with($target, DIRECTORY_SEPARATOR)) {
            $target = base_path($target);
        }

        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            $this->error(sprintf('Verzeichnis %s konnte nicht angelegt werden.', $directory));

            return self::FAILURE;
        }

        $json = $generator->toJson()."\n";

        if (file_put_contents($target, $json) === false) {
            $this->error(sprintf('Datei %s konnte nicht geschrieben werden.', $target));

            return self::FAILURE;
        }

        $this->info(sprintf('OpenAPI-Spezifikation geschrieben: %s (%d Bytes)', $target, strlen($json)));

        return self::SUCCESS;
    }
}
