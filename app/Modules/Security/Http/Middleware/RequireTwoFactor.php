<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Middleware;

use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\TwoFactorService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin-Routen verlangen eine in dieser Sitzung bestätigte 2FA für alle Rollen (08-security.md 3.1). Die Liste
 * hub.security.totp.exempt_roles ist standardmäßig leer (Änderungsvermerk 12.09.2026).
 * Ohne eingerichtete 2FA wird auf die Einrichtungsseite geleitet, mit eingerichteter 2FA auf die Code-Abfrage.
 */
final class RequireTwoFactor
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Nicht authentifiziert.'], 401)
                : redirect()->route('login');
        }

        if ($user->hasConfirmedTotp()) {
            if ($request->session()->has(LoginService::SESSION_TWO_FACTOR_VERIFIED)) {
                return $next($request);
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Zwei-Faktor-Bestätigung erforderlich.'], 403)
                : redirect()->route('security.two-factor.challenge');
        }

        if (! $this->twoFactor->isRequiredFor($user->role)) {
            return $next($request);
        }

        return $request->expectsJson()
            ? response()->json(['message' => 'Zwei-Faktor-Einrichtung erforderlich.'], 403)
            : redirect()->route('security.two-factor.setup')->with('status', 'Bitte richten Sie zuerst die Zwei-Faktor-Authentifizierung ein.');
    }
}
