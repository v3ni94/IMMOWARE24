<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Maskiert Geheimnisse in Arrays und Strings, bevor sie in Logs, Audit oder Exceptions gelangen.
 */
final class SecretMasker
{
    public const string MASK = '***';

    /** @var array<int, string> */
    private const array SENSITIVE_KEY_PARTS = [
        'password', 'passwd', 'pwd', 'secret', 'token', 'authorization', 'auth', 'api_key', 'apikey', 'api-key',
        'private_key', 'client_secret', 'credential', 'iban', 'totp', 'recovery_code', 'cookie', 'set-cookie',
        'x-hub-signature', 'signature', 'key_hash', 'remember_token',
    ];

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    public function maskArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $result[$key] = $value === null || $value === '' || $value === [] ? $value : self::MASK;

                continue;
            }

            if (is_array($value)) {
                $result[$key] = $this->maskArray($value);
            } elseif (is_string($value)) {
                $result[$key] = $this->maskString($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function maskString(string $text): string
    {
        $patterns = [
            // Authorization: Basic xxx / Bearer xxx / Digest xxx
            '/(authorization\s*[:=]\s*)(basic|bearer|digest)\s+[^\s,;"\']+/i' => '$1$2 '.self::MASK,
            '/\b(basic|bearer)\s+[A-Za-z0-9+\/=_\-.]{8,}/i' => '$1 '.self::MASK,
            // key=value in Query-Strings, JSON oder Log-Zeilen
            '/((?:password|passwd|pwd|secret|token|api[_-]?key|client_secret|totp_secret)\s*["\']?\s*[:=]\s*["\']?)([^\s&,;"\'}]+)/i' => '$1'.self::MASK,
            // Userinfo in URLs: https://user:pass@host
            '#(\w+://)([^/:@\s]+):([^@/\s]+)@#' => '$1$2:'.self::MASK.'@',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, array<int, string>|string>
     */
    public function maskHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[$name] = $this->isSensitiveKey($name) ? self::MASK : $value;
        }

        return $result;
    }

    public function isSensitiveKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if (str_contains($needle, $part)) {
                return true;
            }
        }

        return false;
    }
}
