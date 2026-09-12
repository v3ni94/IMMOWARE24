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
 * Re-Authentifizierung für sicherheitskritische Aktionen (08-security.md Abschnitt 3.1, Änderungsvermerk 12.09.2026):
 * API-Key anlegen, Rolle oder Passwort fremder Nutzer ändern, 2FA zurücksetzen, Webhook anlegen, Connection ändern,
 * Connection-Status (degraded aufheben), Schreib-Flags mit Freigabe anzeigen. Äquivalent zu password.confirm:
 * die letzte Bestätigung (TOTP-Code an der Challenge oder Passwort an /security/confirm) darf höchstens
 * hub.security.totp.fresh_minutes (Standard 15) alt sein. Nutzer ohne eingerichtete 2FA haben keinen Zugriff.
 */
final class RequireFreshTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->hasConfirmedTotp()) {
            abort(403, 'Für diese Aktion ist eine eingerichtete Zwei-Faktor-Authentifizierung erforderlich.');
        }

        if (self::isFresh($request)) {
            return $next($request);
        }

        // Für die erneute Abfrage die ursprüngliche Zielseite merken; POST-Daten gehen bewusst verloren,
        // die Aktion muss nach der Bestätigung erneut ausgelöst werden.
        $request->session()->put('url.intended', $request->isMethod('GET') ? $request->fullUrl() : url()->previous());

        return $request->expectsJson()
            ? response()->json(['message' => 'Bitte bestätigen Sie diese Aktion erneut mit Passwort oder Zwei-Faktor-Code.'], 403)
            : redirect()->route('security.confirm.show')->with('status', 'Bitte bestätigen Sie diese Aktion mit Ihrem Passwort oder einem aktuellen Zwei-Faktor-Code und führen Sie sie danach erneut aus.');
    }

    /**
     * Jüngste Bestätigung (2FA-Challenge oder Passwortbestätigung) innerhalb des Frischefensters.
     */
    public static function isFresh(Request $request): bool
    {
        $maxAge = max(1, (int) config('hub.security.totp.fresh_minutes', 15));

        foreach ([LoginService::SESSION_TWO_FACTOR_VERIFIED, LoginService::SESSION_REAUTHENTICATED_AT] as $key) {
            $value = $request->session()->get($key);

            if (is_string($value) && self::withinMinutes($value, $maxAge)) {
                return true;
            }
        }

        return false;
    }

    private static function withinMinutes(string $timestamp, int $minutes): bool
    {
        try {
            return CarbonImmutable::parse($timestamp)->addMinutes($minutes)->isFuture();
        } catch (Throwable) {
            return false;
        }
    }
}
