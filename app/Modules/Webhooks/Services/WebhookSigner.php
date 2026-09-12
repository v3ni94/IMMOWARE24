<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

/**
 * HMAC-SHA256-Signatur ausgehender Webhooks: X-Hub-Signature: t=<ts>,v1=<hex(HMAC(secret, "<ts>.<body>"))>.
 * Bei Secret-Rotation werden zwei v1-Werte (neu und alt) kommagetrennt gesendet.
 */
final class WebhookSigner
{
    public function signature(string $secret, int $timestamp, string $body, ?string $previousSecret = null): string
    {
        $parts = ['t='.$timestamp, 'v1='.$this->digest($secret, $timestamp, $body)];

        if ($previousSecret !== null && $previousSecret !== '' && $previousSecret !== $secret) {
            $parts[] = 'v1='.$this->digest($previousSecret, $timestamp, $body);
        }

        return implode(',', $parts);
    }

    public function digest(string $secret, int $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /**
     * Prüft eine Signatur zeitkonstant und innerhalb des Replay-Fensters (Konsumentenseite und Tests).
     */
    public function verify(string $header, string $body, string $secret, ?int $now = null, ?int $toleranceSeconds = null): bool
    {
        $now ??= time();
        $toleranceSeconds ??= (int) config('hub.webhooks.replay_window_seconds', 300);
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, null);

            if ($key === 't' && $value !== null && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== null) {
                $signatures[] = strtolower($value);
            }
        }

        if ($timestamp === null || $signatures === [] || abs($now - $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = $this->digest($secret, $timestamp, $body);

        foreach ($signatures as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
