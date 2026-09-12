<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Services\Push;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;

/**
 * Prüft OIDC-ID-Tokens der Pub/Sub-Push-Zustellung ohne Paket: JWT (RS256) gegen die JWKS-Schlüssel von Google
 * (config hub.gmail.push.certs_url, mit Cache und einmaligem Neuladen bei unbekannter kid), aud gegen die
 * konfigurierte Endpunkt-URL, iss gegen accounts.google.com, email gegen das konfigurierte Dienstkonto,
 * email_verified, exp und iat mit Toleranz. Quelle der Endpunkte: Snippets, vor Produktivbetrieb am Original prüfen.
 */
final class GoogleIdTokenVerifier
{
    public const string RESULT_OK = 'ok';

    public const string RESULT_MISSING = 'missing';

    public const string RESULT_INVALID = 'invalid_jwt';

    public const string RESULT_BAD_AUDIENCE = 'bad_audience';

    public const string RESULT_BAD_EMAIL = 'bad_email';

    private const string CACHE_KEY = 'mail:gmail:push:certs';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CacheRepository $cache,
        private readonly Repository $config,
    ) {}

    /**
     * @return array{result: string, claims: array<string, mixed>}
     */
    public function verify(?string $bearer): array
    {
        if ($bearer === null || trim($bearer) === '') {
            return ['result' => self::RESULT_MISSING, 'claims' => []];
        }

        $segments = explode('.', trim($bearer));

        if (count($segments) !== 3) {
            return ['result' => self::RESULT_INVALID, 'claims' => []];
        }

        [$headerSegment, $payloadSegment, $signatureSegment] = $segments;
        $header = $this->decodeJson($headerSegment);
        $claims = $this->decodeJson($payloadSegment);
        $signature = $this->base64urlDecode($signatureSegment);

        if ($header === null || $claims === null || $signature === '') {
            return ['result' => self::RESULT_INVALID, 'claims' => []];
        }

        if (strtoupper((string) ($header['alg'] ?? '')) !== 'RS256' || ! isset($header['kid'])) {
            return ['result' => self::RESULT_INVALID, 'claims' => []];
        }

        $publicKey = $this->publicKeyFor((string) $header['kid']);

        if ($publicKey === null) {
            return ['result' => self::RESULT_INVALID, 'claims' => []];
        }

        $verified = openssl_verify($headerSegment.'.'.$payloadSegment, $signature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            return ['result' => self::RESULT_INVALID, 'claims' => []];
        }

        $skew = (int) $this->config->get('hub.gmail.push.clock_skew_seconds', 60);
        $now = time();

        if (! isset($claims['exp']) || (int) $claims['exp'] + $skew < $now) {
            return ['result' => self::RESULT_INVALID, 'claims' => $claims];
        }

        if (isset($claims['iat']) && (int) $claims['iat'] - $skew > $now) {
            return ['result' => self::RESULT_INVALID, 'claims' => $claims];
        }

        $issuers = array_map('strval', (array) $this->config->get('hub.gmail.push.issuers', ['https://accounts.google.com', 'accounts.google.com']));

        if (! in_array((string) ($claims['iss'] ?? ''), $issuers, true)) {
            return ['result' => self::RESULT_INVALID, 'claims' => $claims];
        }

        $expectedAudience = trim((string) $this->config->get('hub.gmail.push.audience', ''));
        $audience = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? array_map('strval', $audience) : [(string) $audience];

        if ($expectedAudience === '' || ! in_array($expectedAudience, $audiences, true)) {
            return ['result' => self::RESULT_BAD_AUDIENCE, 'claims' => $claims];
        }

        $expectedEmail = strtolower(trim((string) $this->config->get('hub.gmail.push.service_account_email', '')));
        $email = strtolower((string) ($claims['email'] ?? ''));

        if ($expectedEmail === '' || $email !== $expectedEmail || ! $this->truthy($claims['email_verified'] ?? false)) {
            return ['result' => self::RESULT_BAD_EMAIL, 'claims' => $claims];
        }

        return ['result' => self::RESULT_OK, 'claims' => $claims];
    }

    /**
     * Öffentlichen Schlüssel (PEM) zur kid liefern; bei unbekannter kid einmal neu laden (Key-Rotation).
     */
    private function publicKeyFor(string $kid): ?\OpenSSLAsymmetricKey
    {
        $keys = $this->keys(false);

        if (! isset($keys[$kid])) {
            $keys = $this->keys(true);
        }

        $jwk = $keys[$kid] ?? null;

        if (! is_array($jwk)) {
            return null;
        }

        $pem = $this->jwkToPem($jwk);

        if ($pem === null) {
            return null;
        }

        $key = openssl_pkey_get_public($pem);

        return $key === false ? null : $key;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function keys(bool $forceReload): array
    {
        if (! $forceReload) {
            $cached = $this->cache->get(self::CACHE_KEY);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            // gemäß Google OpenID-Konfiguration (JWKS), vor Produktivbetrieb am Original prüfen
            $response = $this->http->acceptJson()->timeout(10)->get((string) $this->config->get('hub.gmail.push.certs_url'));
        } catch (ConnectionException $exception) {
            Log::warning('Gmail Push: Google-Zertifikate nicht erreichbar.', ['error' => $exception->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $keys = [];

        foreach ((array) $response->json('keys', []) as $jwk) {
            if (is_array($jwk) && isset($jwk['kid'], $jwk['n'], $jwk['e']) && ($jwk['kty'] ?? 'RSA') === 'RSA') {
                $keys[(string) $jwk['kid']] = $jwk;
            }
        }

        if ($keys !== []) {
            $this->cache->put(self::CACHE_KEY, $keys, (int) $this->config->get('hub.gmail.push.certs_cache_seconds', 3600));
        }

        return $keys;
    }

    /**
     * RSA-JWK (n, e) nach PEM (SubjectPublicKeyInfo, DER selbst kodiert).
     *
     * @param  array<string, mixed>  $jwk
     */
    private function jwkToPem(array $jwk): ?string
    {
        $modulus = $this->base64urlDecode((string) $jwk['n']);
        $exponent = $this->base64urlDecode((string) $jwk['e']);

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        $rsaKey = $this->derSequence($this->derInteger($modulus).$this->derInteger($exponent));
        $algorithm = $this->derSequence("\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"."\x05\x00");
        $spki = $this->derSequence($algorithm.$this->derBitString($rsaKey));

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private function derInteger(string $value): string
    {
        if ($value !== '' && (ord($value[0]) & 0x80) !== 0) {
            $value = "\x00".$value;
        }

        return "\x02".$this->derLength(strlen($value)).$value;
    }

    private function derSequence(string $content): string
    {
        return "\x30".$this->derLength(strlen($content)).$content;
    }

    private function derBitString(string $content): string
    {
        return "\x03".$this->derLength(strlen($content) + 1)."\x00".$content;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $segment): ?array
    {
        $decoded = json_decode($this->base64urlDecode($segment), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function base64urlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? '' : $decoded;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}
