<?php

declare(strict_types=1);

namespace App\Modules\Drive\Services;

use App\Modules\Drive\Models\DriveFolderMapping;
use App\Modules\Estate\Models\Property;
use Illuminate\Contracts\Config\Repository;

/**
 * Zuordnung Objekt zu Drive-Ordner aus Konfiguration (MAIL_DRIVE_FOLDER_MAP, Objektnummer:Ordner-ID) und Hub-Daten
 * (mail_drive_folder_mappings). Liefert nur bestehende Ordner-IDs, legt nie Strukturen an.
 */
final class FolderMappingResolver
{
    public function __construct(private readonly Repository $config) {}

    /**
     * @return array<int, string> Ordner-IDs, dedupliziert
     */
    public function foldersForProperty(Property $property): array
    {
        $folders = DriveFolderMapping::query()
            ->withoutGlobalScopes()
            ->where('property_id', $property->getKey())
            ->pluck('folder_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        $number = $property->getAttribute('immoware_object_number');

        if (is_string($number) && $number !== '') {
            $fromConfig = $this->configMap()[$number] ?? null;

            if ($fromConfig !== null) {
                $folders[] = $fromConfig;
            }
        }

        return array_values(array_unique(array_filter($folders, static fn (string $id): bool => $id !== '')));
    }

    /**
     * @return array<string, string> Objektnummer → Ordner-ID
     */
    public function configMap(): array
    {
        $raw = $this->config->get('hub.drive.folder_map_env');
        $map = [];

        if (is_string($raw) && trim($raw) !== '') {
            foreach (explode(',', $raw) as $pair) {
                $parts = explode(':', trim($pair), 2);

                if (count($parts) === 2 && trim($parts[0]) !== '' && trim($parts[1]) !== '') {
                    $map[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        $configured = $this->config->get('hub.drive.folder_map');

        if (is_array($configured)) {
            foreach ($configured as $number => $folder) {
                if (is_string($folder) && $folder !== '') {
                    $map[(string) $number] = $folder;
                }
            }
        }

        return $map;
    }
}
