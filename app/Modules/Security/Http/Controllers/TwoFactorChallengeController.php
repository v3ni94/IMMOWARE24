<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Core\Enums\AuditSource;
use App\Modules\Security\Http\Requests\TwoFactorCodeRequest;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\TwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Zweiter Faktor nach der Passwortanmeldung: TOTP-Code oder Wiederherstellungscode.
 */
final class TwoFactorChallengeController
{
    public function __construct(
        private readonly TwoFactorService $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View|RedirectResponse
    {
        $user = auth()->user();

        if ($user instanceof User && ! $user->hasConfirmedTotp()) {
            return redirect()->route('security.two-factor.setup');
        }

        return view('security::two-factor.challenge');
    }

    public function verify(TwoFactorCodeRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $code = (string) $request->string('code');
        $recovery = (string) $request->string('recovery_code');

        $valid = $code !== ''
            ? $this->twoFactor->verifyCode($user, $code)
            : $this->twoFactor->consumeRecoveryCode($user, $recovery, $request);

        if (! $valid) {
            $this->audit->record('security.two_factor.failed', $user, [], ['method' => $code !== '' ? 'totp' : 'recovery'], AuditSource::User, request: $request);

            throw ValidationException::withMessages(['code' => 'Der Code ist ungültig.']);
        }

        $request->session()->put(LoginService::SESSION_TWO_FACTOR_VERIFIED, now()->toIso8601String());
        $request->session()->regenerate();

        return redirect()->intended(route('security.sessions.index'));
    }
}
