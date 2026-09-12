<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Middleware;

use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Re-Authentifizierung für sicherheitskritische Aktionen (08-security.md Abschnitt 3.1: API-Key anlegen,
 * Rolle ändern, degraded aufheben, write_enabled setzen). Die TOTP-Bestätigung der Sitzung darf höchstens
 * hub.security.totp.fresh_minutes alt sein, sonst wird die Code-Abfrage erneut verlangt. Nutzer ohne
 * eingerichtete 2FA (ausgenommene Rollen) haben auf diese Aktionen keinen Zugriff.
 */
final class RequireFreshTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasConfirmedTotp()) {
            abort(403, 'Für diese Aktion ist eine eingerichtete Zwei-Faktor-Authentifizierung erforderlich.');
        }

        $verifiedAt = $request->session()->get(LoginService::SESSION_TWO_FACTOR_VERIFIED);
        $maxAge = max(1, (int) config('hub.security.totp.fresh_minutes', 5));

        if (is_string($verifiedAt) && $this->isFresh($verifiedAt, $maxAge)) {
            return $next($request);
        }

        // Für die erneute Abfrage die ursprüngliche Zielseite merken; POST-Daten gehen bewusst verloren,
        // die Aktion muss nach der Bestätigung erneut ausgelöst werden.
        $request->session()->put('url.intended', url()->previous());

        return $request->expectsJson()
            ? response()->json(['message' => 'Bitte bestätigen Sie diese Aktion mit einem aktuellen Zwei-Faktor-Code.'], 403)
            : redirect()->route('security.two-factor.challenge')->with('status', 'Bitte bestätigen Sie diese Aktion mit einem aktuellen Zwei-Faktor-Code und führen Sie sie danach erneut aus.');
    }

    private function isFresh(string $verifiedAt, int $maxAgeMinutes): bool
    {
        try {
            return CarbonImmutable::parse($verifiedAt)->addMinutes($maxAgeMinutes)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }
}
