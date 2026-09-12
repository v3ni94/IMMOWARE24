<?php

declare(strict_types=1);

namespace App\Core\Boot;

use RuntimeException;

/**
 * Verhindert den Start der Anwendung, wenn ein hart gesperrtes Schreib-Flag auf true steht
 * (docs/immoware/05-write-capabilities.md Abschnitt 2.1).
 */
final class BootGuard
{
    /** @var array<string, string> Config-Key => ENV-Name */
    public const array HARD_LOCKED_FLAGS = [
        'write.webdav_overwrite_enabled' => 'IMMOWARE_WRITE_WEBDAV_OVERWRITE_ENABLED',
        'write.webdav_delete_enabled' => 'IMMOWARE_WRITE_WEBDAV_DELETE_ENABLED',
        'write.webdav_move_enabled' => 'IMMOWARE_WRITE_WEBDAV_MOVE_ENABLED',
        'write.carddav_enabled' => 'IMMOWARE_WRITE_CARDDAV_ENABLED',
        'write.caldav_enabled' => 'IMMOWARE_WRITE_CALDAV_ENABLED',
    ];

    /**
     * @param  array<string, mixed>  $coreConfig  Inhalt von config('hub.core')
     *
     * @throws RuntimeException
     */
    public function assertSafe(array $coreConfig): void
    {
        $violations = $this->violations($coreConfig);

        if ($violations === []) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Start verweigert: Die folgenden Schreib-Flags sind fest gesperrt und dürfen nie true sein: %s. '
            .'Immoware24 ist Master, der Hub schreibt ausschließlich create-only in den WebDAV-Posteingang. '
            .'Setzen Sie die Werte in der Umgebung auf false (docs/immoware/05-write-capabilities.md, Abschnitt 2.1).',
            implode(', ', $violations),
        ));
    }

    /**
     * @param  array<string, mixed>  $coreConfig
     * @return array<int, string> ENV-Namen der verletzten Flags
     */
    public function violations(array $coreConfig): array
    {
        $violations = [];

        foreach (self::HARD_LOCKED_FLAGS as $path => $envName) {
            if ($this->isTruthy(data_get($coreConfig, $path))) {
                $violations[] = $envName;
            }
        }

        return $violations;
    }

    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $value === 1;
    }
}
