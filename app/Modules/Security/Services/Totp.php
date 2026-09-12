<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Modules\Security\Support\Base32;

/**
 * TOTP nach RFC 6238 auf Basis von HOTP (RFC 4226). Eigene Implementierung ohne Fremdpaket.
 */
final class Totp
{
    public function __construct(
        private readonly int $period = 30,
        private readonly int $digits = 6,
        private readonly string $algorithm = 'sha1',
        private readonly int $window = 1,
    ) {}

    public static function fromConfig(): self
    {
        $config = (array) config('hub.security.totp', []);

        return new self(
            period: (int) ($config['period'] ?? 30),
            digits: (int) ($config['digits'] ?? 6),
            algorithm: (string) ($config['algorithm'] ?? 'sha1'),
            window: (int) ($config['window'] ?? 1),
        );
    }

    /**
     * Neues Secret als Base32 (Standard 20 Byte Zufall, 160 Bit gemäß RFC 4226 Empfehlung).
     */
    public function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes($bytes));
    }

    /**
     * Berechnet den Code für einen Zeitpunkt (Unix-Sekunden). Secret in Base32.
     */
    public function code(string $secretBase32, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? now()->getTimestamp(), $this->period);

        return $this->hotp(Base32::decode($secretBase32), $counter);
    }

    /**
     * Prüft einen Code mit Toleranz von ±window Perioden. Konstantzeitvergleich.
     */
    public function verify(string $secretBase32, string $code, ?int $timestamp = null): bool
    {
        return $this->matchCounter($secretBase32, $code, $timestamp) !== null;
    }

    /**
     * Liefert den Zeitfenster-Zähler, für den der Code gültig ist (±window Perioden), sonst null.
     * Alle Fenster werden immer vollständig geprüft (Konstantzeit), der Zähler dient der Replay-Sperre.
     */
    public function matchCounter(string $secretBase32, string $code, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (strlen($code) !== $this->digits || ! ctype_digit($code)) {
            return null;
        }

        $secret = Base32::decode($secretBase32);
        $counter = intdiv($timestamp ?? now()->getTimestamp(), $this->period);
        $matched = null;

        for ($offset = -$this->window; $offset <= $this->window; $offset++) {
            if (hash_equals($this->hotp($secret, $counter + $offset), $code)) {
                $matched = $counter + $offset;
            }
        }

        return $matched;
    }

    /**
     * otpauth://-URI für Authenticator-Apps.
     */
    public function otpauthUri(string $secretBase32, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($account);

        $query = http_build_query([
            'secret' => $secretBase32,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }

    public function period(): int
    {
        return $this->period;
    }

    private function hotp(string $secret, int $counter): string
    {
        $message = pack('J', $counter);
        $hmac = hash_hmac($this->algorithm, $message, $secret, true);
        $offset = ord($hmac[strlen($hmac) - 1]) & 0x0F;

        $binary = ((ord($hmac[$offset]) & 0x7F) << 24)
            | ((ord($hmac[$offset + 1]) & 0xFF) << 16)
            | ((ord($hmac[$offset + 2]) & 0xFF) << 8)
            | (ord($hmac[$offset + 3]) & 0xFF);

        $otp = $binary % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }
}
