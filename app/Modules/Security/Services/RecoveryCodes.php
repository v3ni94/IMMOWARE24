<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use Illuminate\Contracts\Hashing\Hasher;

/**
 * Wiederherstellungscodes: Klartext nur bei Erzeugung, gespeichert werden Hashes, jeder Code einmal verwendbar.
 */
final class RecoveryCodes
{
    public function __construct(private readonly Hasher $hasher) {}

    /**
     * @return array<int, string> Klartextcodes im Format XXXX-XXXX-XX
     */
    public function generate(int $count = 10): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 2);
        }

        return $codes;
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, string>
     */
    public function hashAll(array $codes): array
    {
        return array_map(fn (string $code): string => $this->hasher->make(self::normalize($code)), $codes);
    }

    /**
     * Gibt den Index des passenden Hashes zurück oder null.
     *
     * @param  array<int, string>  $hashes
     */
    public function match(array $hashes, string $code): ?int
    {
        $code = self::normalize($code);

        foreach ($hashes as $index => $hash) {
            if ($this->hasher->check($code, $hash)) {
                return (int) $index;
            }
        }

        return null;
    }

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
