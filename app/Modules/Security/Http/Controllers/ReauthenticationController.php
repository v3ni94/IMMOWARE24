<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Core\Enums\AuditSource;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\TwoFactorService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Erneute Authentifizierung vor sicherheitskritischen Aktionen (Middleware 2fa.fresh): Passwort oder TOTP-Code.
 * Setzt security.reauthenticated_at in der Sitzung; das Fenster bestimmt hub.security.totp.fresh_minutes.
 */
final class ReauthenticationController
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        return view('security::confirm', ['minutes' => (int) config('hub.security.totp.fresh_minutes', 15)]);
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:1024'],
            'code' => ['nullable', 'string', 'max:16'],
        ]);

        $password = (string) ($data['password'] ?? '');
        $code = (string) ($data['code'] ?? '');

        if ($password !== '') {
            $method = 'password';
            $valid = $this->hasher->check($password, (string) $user->getAttribute('password'));
        } elseif ($code !== '') {
            $method = 'totp';
            $valid = $this->twoFactor->verifyCode($user, $code);
        } else {
            throw ValidationException::withMessages(['password' => 'Bitte Passwort oder Zwei-Faktor-Code eingeben.']);
        }

        if (! $valid) {
            $this->audit->record('security.reauthentication.failed', $user, [], ['method' => $method], AuditSource::User, request: $request);

            throw ValidationException::withMessages([$method === 'password' ? 'password' : 'code' => 'Die Bestätigung ist ungültig.']);
        }

        $request->session()->put(LoginService::SESSION_REAUTHENTICATED_AT, now()->toIso8601String());
        $this->audit->record('security.reauthentication.succeeded', $user, [], ['method' => $method], AuditSource::User, request: $request);

        return redirect()->intended(route('security.sessions.index'))->with('status', 'Bestätigung erfolgreich. Bitte führen Sie die Aktion jetzt erneut aus.');
    }
}
