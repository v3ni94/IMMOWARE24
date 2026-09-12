<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\Organization;
use App\Modules\Imports\DTO\ImportMetadata;
use App\Modules\Imports\DTO\ScanResult;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Models\ImportFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drop-Ordner-Watcher: erkennt neue Dateien, berechnet SHA-256, legt import_files an (Status received)
 * und verlangt einen Metadaten-Sidecar <datei>.json. Ohne gültigen Sidecar landet die Datei in Quarantäne.
 * Erfasste Dateien werden in die Unterordner received bzw. quarantine verschoben.
 */
final class DropFolderScanner
{
    public const string SIDECAR_SUFFIX = '.json';

    public function __construct(private readonly ImportStorage $storage) {}

    public function scan(): ScanResult
    {
        $result = new ScanResult;
        $disk = $this->storage->dropDisk();
        $receivedFolder = (string) config('hub.imports.folders.received', 'received');
        $quarantineFolder = (string) config('hub.imports.folders.quarantine', 'quarantine');

        foreach ($disk->files('') as $relative) {
            $basename = basename($relative);

            if (str_starts_with($basename, '.') || str_ends_with(strtolower($basename), self::SIDECAR_SUFFIX)) {
                continue;
            }

            try {
                $this->register($relative, $result, $receivedFolder, $quarantineFolder);
            } catch (Throwable $e) {
                Log::warning('Import-Datei konnte nicht erfasst werden.', ['file' => $basename, 'error' => $e->getMessage()]);
                $result->errors[] = sprintf('%s: %s', $basename, $e->getMessage());
            }
        }

        return $result;
    }

    private function register(string $relative, ScanResult $result, string $receivedFolder, string $quarantineFolder): void
    {
        $basename = basename($relative);
        $absolute = $this->storage->absolutePath($relative);
        $hash = (string) hash_file('sha256', $absolute);
        $size = (int) filesize($absolute);

        [$metadata, $metadataErrors] = $this->readSidecar($relative);
        $requireMetadata = (bool) config('hub.imports.require_metadata', true);

        $organization = $this->resolveOrganization($metadata);

        if ($organization === null) {
            $result->errors[] = sprintf('%s: Mandant nicht ermittelbar, Datei bleibt im Drop-Ordner.', $basename);

            return;
        }

        $duplicate = ImportFile::query()
            ->withoutGlobalScope('organization')
            ->where('organization_id', $organization->getKey())
            ->where('content_hash', $hash)
            ->exists();

        if ($duplicate) {
            $result->duplicates[] = $basename;
            $this->moveWithSidecar($relative, $receivedFolder.'/duplicate_'.$hash.'_'.$basename);

            return;
        }

        $exportType = $metadata?->exportType !== null ? ExportType::tryFrom($metadata->exportType) : null;
        $quarantineReason = null;

        if ($metadata === null && $requireMetadata) {
            $quarantineReason = 'metadata_missing';
        } elseif ($metadataErrors !== []) {
            $quarantineReason = 'metadata_invalid';
        } elseif ($metadata !== null && $metadata->exportType !== null && $exportType === null) {
            $quarantineReason = 'export_type_unknown';
            $metadataErrors[] = sprintf('Unbekannter export_type "%s".', $metadata->exportType);
        }

        $status = $quarantineReason === null ? ImportFileStatus::Received : ImportFileStatus::Quarantined;
        $targetFolder = $status === ImportFileStatus::Received ? $receivedFolder : $quarantineFolder;
        $target = $targetFolder.'/'.substr($hash, 0, 12).'_'.$basename;

        $this->moveWithSidecar($relative, $target);

        $connection = $this->resolveConnection($organization, $metadata);

        $file = ImportFile::query()->create([
            'organization_id' => $organization->getKey(),
            'connection_id' => $connection?->getKey(),
            'original_filename' => $basename,
            'file_type' => $this->fileType($basename, $exportType),
            'export_type' => $exportType?->value,
            'content_hash' => $hash,
            'size_bytes' => $size,
            'storage_key' => $target,
            'metadata' => $metadata?->raw,
            'is_full_export' => $metadata !== null && $metadata->isFullExport,
            'as_of_date' => $metadata !== null ? ($metadata->asOfDate ?? $metadata->exportedAt?->startOfDay()) : null,
            'exported_at' => $metadata?->exportedAt,
            'exported_by' => $metadata?->exportedBy,
            'status' => $status->value,
            'quarantine_reason' => $quarantineReason,
            'errors' => $metadataErrors === [] ? null : $metadataErrors,
            'received_at' => CarbonImmutable::now(),
        ]);

        if ($status === ImportFileStatus::Received) {
            $result->received[] = (int) $file->getKey();
        } else {
            $result->quarantined[] = (int) $file->getKey();
        }

        Log::info('Import-Datei erfasst.', ['import_file_id' => $file->getKey(), 'status' => $status->value, 'export_type' => $exportType?->value]);
    }

    /**
     * @return array{0: ImportMetadata|null, 1: array<int, string>}
     */
    private function readSidecar(string $relative): array
    {
        $disk = $this->storage->dropDisk();
        $sidecar = $relative.self::SIDECAR_SUFFIX;

        if (! $disk->exists($sidecar)) {
            return [null, []];
        }

        $content = (string) $disk->get($sidecar);
        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [null, ['Sidecar ist kein gültiges JSON-Objekt.']];
        }

        $metadata = ImportMetadata::fromArray($decoded);
        /** @var array<int, string> $required */
        $required = (array) config('hub.imports.metadata_required_fields', []);
        $missing = $metadata->missingFields($required);
        $errors = $missing === [] ? [] : ['Sidecar unvollständig, fehlende Felder: '.implode(', ', $missing).'.'];

        return [$metadata, $errors];
    }

    private function resolveOrganization(?ImportMetadata $metadata): ?Organization
    {
        if ($metadata?->organization !== null) {
            $org = Organization::query()->where('legal_entity_code', $metadata->organization)->first();

            if ($org === null && ctype_digit($metadata->organization)) {
                $org = Organization::query()->find((int) $metadata->organization);
            }

            if ($org !== null) {
                return $org;
            }
        }

        $default = config('hub.imports.default_organization_id');

        return $default !== null ? Organization::query()->find((int) $default) : null;
    }

    private function resolveConnection(Organization $organization, ?ImportMetadata $metadata): ?ImmowareConnection
    {
        $query = ImmowareConnection::query()->where('organization_id', $organization->getKey());

        if ($metadata?->connection !== null && ctype_digit($metadata->connection)) {
            return $query->whereKey((int) $metadata->connection)->first();
        }

        return null;
    }

    private function fileType(string $basename, ?ExportType $exportType): string
    {
        return match (true) {
            $exportType === ExportType::DatevBuchungsstapel => 'datev_csv',
            $exportType === ExportType::Camt053 => 'camt053_xml',
            str_ends_with(strtolower($basename), '.xml') => 'xml',
            default => 'csv',
        };
    }

    private function moveWithSidecar(string $relative, string $target): void
    {
        $disk = $this->storage->dropDisk();
        $disk->move($relative, $target);

        if ($disk->exists($relative.self::SIDECAR_SUFFIX)) {
            $disk->move($relative.self::SIDECAR_SUFFIX, $target.self::SIDECAR_SUFFIX);
        }
    }
}
