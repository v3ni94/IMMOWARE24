<?php

declare(strict_types=1);

namespace App\Modules\Learning\Services;

use App\Modules\Imports\Models\ImportFormat;

/**
 * Lernphase Immoware24, Art imports: wertet die bereits im Hub erfassten Dateiformate aus (import_formats,
 * gepflegt von ImportFormatService bei jedem neuen CSV-, DATEV- oder CAMT-Export). Kein Dateizugriff durch
 * diesen Dienst selbst: die Formate liegen bereits vor, sobald ein Export einmal eingespielt wurde.
 * import_formats ist nicht mandantengebunden (eine Spaltenstruktur je Exportwerkzeug, nicht je Organisation).
 */
final class ImportsStructureScanner
{
    /**
     * @return array<string, mixed>
     */
    public function scan(): array
    {
        $query = ImportFormat::query();
        $query->orderBy('format_key')->orderByDesc('version');
        $formats = $query->get();

        $byKey = [];

        foreach ($formats as $format) {
            $key = (string) $format->getAttribute('format_key');
            $byKey[$key] ??= [];
            $byKey[$key][] = [
                'version' => (int) $format->getAttribute('version'),
                'status' => (string) $format->getAttribute('status'),
                'header_columns' => (array) $format->getAttribute('header_columns'),
                'mapped_fields' => array_keys((array) $format->getAttribute('column_mapping')),
                'confirmed' => $format->getAttribute('confirmed_at') !== null,
            ];
        }

        return [
            'kind' => 'imports',
            'formats' => $byKey,
            'format_count' => $formats->count(),
            'draft_count' => $formats->where('status', 'draft')->count(),
        ];
    }
}
