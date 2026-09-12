<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Core\Support\UrlGuard;
use Closure;

/**
 * SSRF-Schutz für Webhook-Ziele (08-security.md, Review 12.09.2026). Seit dem Abschlusslauf 12.09.2026 eine dünne
 * Fassade über den gemeinsamen Core-Dienst App\Core\Support\UrlGuard (eine Regelmenge für Connection-Basis-URLs und
 * Webhook-Ziele): nur https, keine Zugangsdaten in der URL, kein localhost, keine .internal- oder .local-Namen, und
 * jede aufgelöste Adresse (A und AAAA) darf nicht in privaten, Loopback-, Link-Local-, Multicast- oder reservierten
 * Bereichen liegen. Die Prüfung läuft beim Anlegen des Endpunkts und unmittelbar vor jedem Versand (DNS-Rebinding).
 * Redirects werden im Versand nicht gefolgt.
 */
final class WebhookUrlGuard
{
    /** @var array<int, string> */
    public const array BLOCKED_HOST_SUFFIXES = UrlGuard::BLOCKED_HOST_SUFFIXES;

    /** @var array<int, string> */
    public const array BLOCKED_CIDRS = UrlGuard::BLOCKED_CIDRS;

    private readonly UrlGuard $guard;

    /**
     * @param  (Closure(string): array<int, string>)|null  $resolver  Hostname => Liste aufgelöster IP-Adressen (Tests)
     * @param  UrlGuard|null  $guard  Vorkonfigurierter Core-Guard (Container); ohne beides System-DNS-Auflösung
     */
    public function __construct(?Closure $resolver = null, ?UrlGuard $guard = null)
    {
        $this->guard = $resolver !== null ? new UrlGuard($resolver) : ($guard ?? UrlGuard::withSystemResolver());
    }

    /**
     * Grund der Ablehnung oder null, wenn das Ziel zulässig ist (Gründe siehe UrlGuard::reason()).
     */
    public function reason(string $url): ?string
    {
        return $this->guard->reason($url);
    }

    public function allows(string $url): bool
    {
        return $this->guard->allows($url);
    }

    public static function isBlockedIp(string $ip): bool
    {
        return UrlGuard::isBlockedIp($ip);
    }
}
