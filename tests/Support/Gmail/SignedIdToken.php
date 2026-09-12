<?php

declare(strict_types=1);

namespace Tests\Support\Gmail;

/**
 * Testhilfe: erzeugt ein RSA-Schlüsselpaar, signiert OIDC-ID-Tokens (RS256) und liefert das passende JWKS-Dokument,
 * wie es Http::fake für den Zertifikatsendpunkt zurückgibt. Kein Google-Zertifikat, nur Simulation.
 */
final class SignedIdToken
{
    private \OpenSSLAsymmetricKey $key;

    public string $kid = 'test-kid-1';

    public function __construct()
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key === false) {
            throw new \RuntimeException('RSA-Schlüssel konnte nicht erzeugt werden.');
        }

        $this->key = $key;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function issue(array $overrides = [], ?string $kid = null): string
    {
        $claims = array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => 'https://mail.muellerhv.de/mail/gmail/push',
            'email' => 'pubsub-push@projekt.iam.gserviceaccount.com',
            'email_verified' => true,
            'sub' => '1234567890',
            'iat' => time() - 5,
            'exp' => time() + 300,
        ], $overrides);

        $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid ?? $this->kid], JSON_THROW_ON_ERROR));
        $payload = $this->b64(json_encode($claims, JSON_THROW_ON_ERROR));
        openssl_sign($header.'.'.$payload, $signature, $this->key, OPENSSL_ALGO_SHA256);

        return $header.'.'.$payload.'.'.$this->b64($signature);
    }

    /**
     * @return array{keys: array<int, array<string, string>>}
     */
    public function jwks(): array
    {
        $details = openssl_pkey_get_details($this->key);

        return ['keys' => [[
            'kty' => 'RSA',
            'alg' => 'RS256',
            'use' => 'sig',
            'kid' => $this->kid,
            'n' => $this->b64($details['rsa']['n']),
            'e' => $this->b64($details['rsa']['e']),
        ]]];
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
