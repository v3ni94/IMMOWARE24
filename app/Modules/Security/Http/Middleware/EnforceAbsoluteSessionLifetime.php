<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Middleware;

use App\Modules\Security\Services\LoginService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Absolute Sitzungsdauer (08-security.md Abschnitt 3.1: 8 Stunden ab Anmeldung, unabhängig von Aktivität).
 * Die gleitende Inaktivitätsgrenze liefert session.lifetime. Fehlt der Anmeldezeitpunkt in einer
 * bestehenden authentifizierten Sitzung, wird er nachgetragen, damit auch diese Sitzung endlich ist.
 */
final class EnforceAbsoluteSessionLifetime
{
    public function __construct(private readonly StatefulGuard $guard) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $this->guard->check()) {
            return $next($request);
        }

        $session = $request->session();
        $loginAt = $session->get(LoginService::SESSION_LOGIN_AT);
        $limitMinutes = max(1, (int) config('hub.security.sessions.absolute_minutes', 480));

        if (! is_string($loginAt) || $loginAt === '') {
            $session->put(LoginService::SESSION_LOGIN_AT, now()->toIso8601String());

            return $next($request);
        }

        try {
            $expired = CarbonImmutable::parse($loginAt)->addMinutes($limitMinutes)->isPast();
        } catch (Throwable) {
            $expired = true;
        }

        if (! $expired) {
            return $next($request);
        }

        $this->guard->logout();
        $session->invalidate();
        $session->regenerateToken();

        return $request->expectsJson()
            ? response()->json(['message' => 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.'], 401)
            : redirect()->route('login')->with('status', 'Die Sitzung ist abgelaufen. Bitte erneut anmelden.');
    }
}
