<?php

use App\Core\Http\Middleware\CorrelationIdMiddleware;
use App\Modules\Security\Http\Middleware\EnforceAbsoluteSessionLifetime;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

/*
 * Vertrauenswürdige Reverse-Proxies (TLS-Terminierung vor dem Container, docs/operations/01-deployment.md
 * Abschnitt 7). TRUSTED_PROXIES: kommagetrennte IP-Adressen oder CIDR-Netze. Standard leer, dann werden keine
 * X-Forwarded-*-Header ausgewertet und $request->ip() liefert die direkte Gegenstelle. Nie '*' verwenden:
 * IP-Allowlist der API-Keys, Login-Throttle und ip_address_hash im Audit hängen an der korrekten Client-IP.
 */
$trustedProxies = array_values(array_filter(array_map(
    static fn (string $proxy): string => trim($proxy),
    explode(',', (string) ($_ENV['TRUSTED_PROXIES'] ?? $_SERVER['TRUSTED_PROXIES'] ?? getenv('TRUSTED_PROXIES') ?: '')),
), static fn (string $proxy): bool => $proxy !== '' && $proxy !== '*'));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) use ($trustedProxies): void {
        $middleware->prepend(CorrelationIdMiddleware::class);

        // Absolute Sitzungsdauer (08-security.md 3.1: 8 Stunden ab Anmeldung) für alle Web-Routen. Muss hier stehen:
        // Gruppen, die ein Provider per pushMiddlewareToGroup ergänzt, werden beim Auflösen des HTTP-Kernels überschrieben.
        $middleware->web(append: [EnforceAbsoluteSessionLifetime::class]);

        $middleware->trustProxies(
            at: $trustedProxies,
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
