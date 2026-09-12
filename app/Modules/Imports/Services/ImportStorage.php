<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;

/**
 * Liefert die Storage-Disk des Drop-Ordners aus HUB_IMPORT_DROP_PATH sowie absolute Pfade darin.
 */
final class ImportStorage
{
    private ?Filesystem $disk = null;

    public function __construct(private readonly FilesystemManager $filesystems) {}

    public function dropDisk(): Filesystem
    {
        if ($this->disk === null) {
            $root = $this->dropPath();

            if (! is_dir($root)) {
                mkdir($root, 0770, true);
            }

            $this->disk = $this->filesystems->build([
                'driver' => 'local',
                'root' => $root,
                'throw' => false,
            ]);
        }

        return $this->disk;
    }

    public function dropPath(): string
    {
        $path = (string) config('hub.imports.drop_path');

        return rtrim($path, '/');
    }

    public function absolutePath(string $relative): string
    {
        return $this->dropPath().'/'.ltrim($relative, '/');
    }

    public function exportDisk(): Filesystem
    {
        return $this->filesystems->disk((string) config('hub.imports.exports.disk', 'local'));
    }

    /**
     * Setzt die gecachte Disk zurück (nach Konfigurationswechsel in Tests).
     */
    public function reset(): void
    {
        $this->disk = null;
    }
}
