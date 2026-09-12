<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Modules\Sync\Models\WriteOperation;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Blob-Ablage des Quellinhalts eines Upload-Antrags (write_operations.source_storage_key), damit ein Antrag,
 * der pending bleibt (Flags gesperrt, Freigabe durch einen Menschen ausstehend), später ohne erneuten Upload
 * ausgeführt werden kann (05-write-capabilities.md 3.3: "Inhalt im Blob-Speicher", Änderungsvermerk 12.09.2026).
 * Der Inhalt liegt nie in der Datenbank.
 */
final class UploadContentStore
{
    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * Legt den Inhalt ab und liefert den Speicherschlüssel.
     */
    public function store(WriteOperation $operation, string $content): string
    {
        $key = $this->keyFor($operation);
        $this->disk()->put($key, $content);

        return $key;
    }

    public function retrieve(WriteOperation $operation): ?string
    {
        $key = $operation->getAttribute('source_storage_key');

        if (! is_string($key) || $key === '' || ! $this->disk()->exists($key)) {
            return null;
        }

        $content = $this->disk()->get($key);

        return is_string($content) ? $content : null;
    }

    public function forget(WriteOperation $operation): void
    {
        $key = $operation->getAttribute('source_storage_key');

        if (is_string($key) && $key !== '' && $this->disk()->exists($key)) {
            $this->disk()->delete($key);
        }
    }

    private function keyFor(WriteOperation $operation): string
    {
        $uuid = (string) ($operation->getAttribute('operation_uuid') ?? $operation->getKey());

        return trim((string) $this->config->get('hub.core.write.storage_prefix', 'write-operations'), '/').'/'.$uuid.'.bin';
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return $this->filesystems->disk((string) $this->config->get('hub.core.write.storage_disk', 'local'));
    }
}
