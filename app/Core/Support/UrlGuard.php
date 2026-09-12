<?php

declare(strict_types=1);

namespace App\Core\Support;

use Closure;

/**
 * Gemeinsamer SSRF-Schutz für alle ausgehenden Ziel-URLs (Connection-Basis-URL, Webhook-Endpunkte,
 * 08-security.md Abschnitt 9, Änderungsvermerk 12.09.2026): nur https, keine Zugangsdaten in der URL,
 * keine lokalen Hostnamen, keine IP-Literale aus privaten, Loopback-, Link-Local-, Multicast- oder
 * reservierten Bereichen. Optional wird der Hostname aufgelöst und jede Adresse (A und AAAA) geprüft
 * (DNS-Rebinding); ohne Resolver bleibt die Prüfung auf Literale und Namensmuster beschränkt.
 */
final class UrlGuard
{
    /** @var array<int, string> */
    public const array BLOCKED_HOST_SUFFIXES = ['localhost', '.localhost', '.internal', '.local', '.localdomain', '.home.arpa'];

    /** @var array<int, string> */
    public const array BLOCKED_CIDRS = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
        '224.0.0.0/4', '240.0.0.0/4', '255.255.255.255/32',
        '::/128', '::1/128', '::ffff:0:0/96', '64:ff9b::/96', '100::/64', '2001:db8::/32', 'fc00::/7', 'fe80::/10', 'ff00::/8',
    ];

    /** @var (Closure(string): array<int, string>)|null */
    private ?Closure $resolver;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  Hostname => aufgelöste IP-Adressen; null = keine DNS-Prüfung
     */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * Guard mit System-DNS-Auflösung (Produktion).
     */
    public static function withSystemResolver(): self
    {
        return new self(static fn (string $host): array => self::systemResolve($host));
    }

    /**
     * Grund der Ablehnung oder null, wenn das Ziel zulässig ist. Gründe: url_invalid, scheme_not_https,
     * credentials_in_url, host_blocked, host_invalid, ip_blocked, dns_unresolved, resolves_to_blocked_ip.
     */
    public function reason(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return 'url_invalid';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'scheme_not_https';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'credentials_in_url';
        }

        $host = self::normalizeHost($parts['host']);

        if ($host === '') {
            return 'url_invalid';
        }

        if (self::isBlockedHostName($host)) {
            return 'host_blocked';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return self::isBlockedIp($host) ? 'ip_blocked' : null;
        }

        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || ! str_contains($host, '.')) {
            return 'host_invalid';
        }

        if ($this->resolver === null) {
            return null;
        }

        $addresses = ($this->resolver)($host);

        if ($addresses === []) {
            return 'dns_unresolved';
        }

        foreach ($addresses as $address) {
            if (self::isBlockedIp($address)) {
                return 'resolves_to_blocked_ip';
            }
        }

        return null;
    }

    public function allows(string $url): bool
    {
        return $this->reason($url) === null;
    }

    public static function normalizeHost(string $host): string
    {
        return strtolower(trim(trim($host), '[]'));
    }

    /**
     * Lokale oder nur intern auflösbare Hostnamen.
     */
    public static function isBlockedHostName(string $host): bool
    {
        $host = self::normalizeHost($host);

        foreach (self::BLOCKED_HOST_SUFFIXES as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hostname oder IP-Literal, das nie Ziel einer ausgehenden Verbindung sein darf.
     */
    public static function isForbiddenHost(string $host): bool
    {
        $host = self::normalizeHost($host);

        if (self::isBlockedHostName($host)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return self::isBlockedIp($host);
    }

    public static function isBlockedIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return true;
        }

        foreach (self::BLOCKED_CIDRS as $cidr) {
            if (self::inCidr($packed, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private static function inCidr(string $packed, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $networkPacked = inet_pton($network);

        if ($networkPacked === false || strlen($networkPacked) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && substr($packed, 0, $bytes) !== substr($networkPacked, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($networkPacked[$bytes]) & $mask);
    }

    /**
     * @return array<int, string>
     */
    private static function systemResolve(string $host): array
    {
        $addresses = [];

        foreach (gethostbynamel($host) ?: [] as $ipv4) {
            $addresses[] = (string) $ipv4;
        }

        $records = @dns_get_record($host, DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
