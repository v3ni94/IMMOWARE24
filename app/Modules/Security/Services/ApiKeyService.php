<?php

declare(strict_types=1);

namespace App\Modules\Security\Services;

use App\Core\Enums\AuditSource;
use App\Modules\Security\DTO\AuditActor;
use App\Modules\Security\DTO\CreatedApiKey;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Erzeugung, Auflösung und Widerruf von API-Keys. Format: hub_live_<prefix>_<secret>.
 * Gespeichert wird ausschließlich key_hash = SHA-256 des vollständigen Schlüssels.
 */
final class ApiKeyService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<int, string>  $scopes
     * @param  array<int, string>|null  $allowedIps
     */
    public function create(
        int $organizationId,
        string $name,
        array $scopes,
        CarbonImmutable $expiresAt,
        ?User $createdBy = null,
        ?array $allowedIps = null,
        ?Request $request = null,
    ): CreatedApiKey {
        $scopes = $this->validateScopes($scopes);
        $this->validateExpiry($expiresAt);
        $allowedIps = $this->validateIps($allowedIps);

        $config = (array) config('hub.security.api_keys', []);
        $keyPrefix = (string) ($config['key_prefix'] ?? 'hub_live');
        $prefixLength = (int) ($config['prefix_length'] ?? 8);
        $secretBytes = (int) ($config['secret_bytes'] ?? 32);

        do {
            $prefix = self::alphanumeric($prefixLength);
        } while (ApiKey::query()->allOrganizations()->where('prefix', $prefix)->exists());

        $plain = $keyPrefix.'_'.$prefix.'_'.self::base64url(random_bytes($secretBytes));

        $apiKey = ApiKey::query()->create([
            'organization_id' => $organizationId,
            'name' => $name,
            'prefix' => $prefix,
            'key_hash' => self::hashKey($plain),
            'scopes' => $scopes,
            'allowed_ips' => $allowedIps,
            'expires_at' => $expiresAt,
            'created_by' => $createdBy?->getKey(),
        ]);

        $this->audit->record(
            'security.api_key.created',
            $apiKey,
            [],
            ['name' => $name, 'prefix' => $prefix, 'scopes' => $scopes, 'expires_at' => $expiresAt->toIso8601String(), 'allowed_ips' => $allowedIps],
            AuditSource::User,
            request: $request,
            actor: $createdBy !== null ? AuditActor::user((int) $createdBy->getKey(), $organizationId) : null,
        );

        return new CreatedApiKey($apiKey, $plain);
    }

    /**
     * Löst einen Bearer-Wert zu einem Key auf. Prüft nur Existenz und Hash, nicht Ablauf oder Widerruf.
     */
    public function resolve(string $plainTextKey): ?ApiKey
    {
        $parts = explode('_', trim($plainTextKey), 4);

        if (count($parts) !== 4 || $parts[3] === '') {
            return null;
        }

        [$scheme, $env, $prefix] = $parts;
        $expected = explode('_', (string) config('hub.security.api_keys.key_prefix', 'hub_live'));

        if ([$scheme, $env] !== $expected) {
            return null;
        }

        $apiKey = ApiKey::query()->allOrganizations()->where('prefix', $prefix)->first();

        if ($apiKey === null || ! hash_equals((string) $apiKey->getAttribute('key_hash'), self::hashKey($plainTextKey))) {
            return null;
        }

        return $apiKey;
    }

    public function revoke(ApiKey $apiKey, ?Request $request = null): void
    {
        if ($apiKey->getAttribute('revoked_at') !== null) {
            return;
        }

        $apiKey->forceFill(['revoked_at' => now()->toImmutable()])->save();

        $this->audit->record('security.api_key.revoked', $apiKey, [], ['prefix' => $apiKey->getAttribute('prefix')], AuditSource::User, request: $request);
    }

    /**
     * last_used_at auf Minute gerundet, um Schreiblast zu begrenzen. Erste Nutzung je Tag wird auditiert.
     */
    public function touch(ApiKey $apiKey, ?Request $request = null): void
    {
        $now = now()->toImmutable()->startOfMinute();
        $last = $apiKey->getAttribute('last_used_at');

        if ($last instanceof CarbonImmutable && $last->equalTo($now)) {
            return;
        }

        $firstUseToday = ! ($last instanceof CarbonImmutable) || ! $last->isSameDay($now);

        $apiKey->forceFill(['last_used_at' => $now])->save();

        if ($firstUseToday) {
            $this->audit->record(
                'security.api_key.used',
                $apiKey,
                [],
                ['prefix' => $apiKey->getAttribute('prefix')],
                AuditSource::Api,
                request: $request,
                actor: AuditActor::apiKey((int) $apiKey->getKey(), (int) $apiKey->getAttribute('organization_id')),
            );
        }
    }

    public function ipAllowed(ApiKey $apiKey, ?string $ip): bool
    {
        $allowed = $apiKey->getAttribute('allowed_ips');

        if (! is_array($allowed) || $allowed === []) {
            return true;
        }

        if ($ip === null || $ip === '') {
            return false;
        }

        foreach ($allowed as $entry) {
            if (self::ipMatches($ip, (string) $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function knownScopes(): array
    {
        return array_values((array) config('hub.security.api_keys.scopes', []));
    }

    public static function hashKey(string $plainTextKey): string
    {
        return hash('sha256', $plainTextKey);
    }

    /**
     * IPv4 und IPv6, einzeln oder als CIDR.
     */
    public static function ipMatches(string $ip, string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return inet_pton($ip) !== false && inet_pton($rule) !== false && inet_pton($ip) === inet_pton($rule);
        }

        [$subnet, $bits] = explode('/', $rule, 2);
        $ipBin = inet_pton($ip);
        $subnetBin = inet_pton($subnet);

        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin) || ! ctype_digit($bits)) {
            return false;
        }

        $bits = (int) $bits;
        $maxBits = strlen($ipBin) * 8;

        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF & (0xFF << (8 - $remainder));

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /**
     * @param  array<int, string>  $scopes
     * @return array<int, string>
     */
    private function validateScopes(array $scopes): array
    {
        $scopes = array_values(array_unique(array_map('strval', $scopes)));

        if ($scopes === []) {
            throw new InvalidArgumentException('Mindestens ein Scope ist erforderlich.');
        }

        $unknown = array_diff($scopes, $this->knownScopes());

        if ($unknown !== []) {
            throw new InvalidArgumentException('Unbekannte Scopes: '.implode(', ', $unknown));
        }

        return $scopes;
    }

    private function validateExpiry(CarbonImmutable $expiresAt): void
    {
        $months = (int) config('hub.security.api_keys.max_lifetime_months', 12);

        if ($expiresAt->isPast()) {
            throw new InvalidArgumentException('expires_at muss in der Zukunft liegen.');
        }

        if ($expiresAt->greaterThan(CarbonImmutable::now()->addMonths($months)->addDay())) {
            throw new InvalidArgumentException(sprintf('expires_at darf höchstens %d Monate in der Zukunft liegen.', $months));
        }
    }

    /**
     * @param  array<int, string>|null  $ips
     * @return array<int, string>|null
     */
    private function validateIps(?array $ips): ?array
    {
        if ($ips === null || $ips === []) {
            return null;
        }

        $clean = [];

        foreach ($ips as $entry) {
            $entry = trim((string) $entry);
            $address = str_contains($entry, '/') ? explode('/', $entry, 2)[0] : $entry;

            if (inet_pton($address) === false) {
                throw new InvalidArgumentException('Ungültige IP-Adresse oder CIDR: '.$entry);
            }

            $clean[] = $entry;
        }

        return $clean;
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /**
     * Prefix ohne Unterstrich, damit das Schlüsselformat eindeutig zerlegbar bleibt.
     */
    private static function alphanumeric(int $length): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $result;
    }
}
