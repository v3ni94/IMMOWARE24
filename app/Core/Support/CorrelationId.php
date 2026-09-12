<?php

declare(strict_types=1);

namespace App\Core\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Hält die Correlation-ID des aktuellen Requests bzw. Jobs und spiegelt sie in den Log-Kontext.
 */
final class CorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    private ?string $current = null;

    public function current(): string
    {
        return $this->current ??= $this->set(self::generate());
    }

    public function set(string $id): string
    {
        $this->current = self::sanitize($id);
        Log::withContext(['correlation_id' => $this->current]);

        return $this->current;
    }

    public function has(): bool
    {
        return $this->current !== null;
    }

    public function clear(): void
    {
        $this->current = null;
        Log::withoutContext();
    }

    public static function generate(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Nur druckbare, kurze Werte akzeptieren, sonst neue ID erzeugen.
     */
    public static function sanitize(string $id): string
    {
        $id = trim($id);

        if ($id === '' || strlen($id) > 128 || preg_match('/^[A-Za-z0-9._:-]+$/', $id) !== 1) {
            return self::generate();
        }

        return $id;
    }
}
