<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Modules\Security\Http\Requests\TwoFactorCodeRequest;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\LoginService;
use App\Modules\Security\Services\TwoFactorService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Einrichtung der Zwei-Faktor-Authentifizierung und Verwaltung der Wiederherstellungscodes.
 */
final class TwoFactorSetupController
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $secret = $user->getAttribute('totp_secret');

        if ($user->hasConfirmedTotp()) {
            return view('security::two-factor.setup', [
                'confirmed' => true,
                'remainingCodes' => $this->twoFactor->remainingRecoveryCodes($user),
            ]);
        }

        if (! is_string($secret) || $secret === '') {
            $secret = $this->twoFactor->beginSetup($user);
        }

        return view('security::two-factor.setup', [
            'confirmed' => false,
            'secret' => $secret,
            'otpauthUri' => $this->twoFactor->otpauthUri($user),
        ]);
    }

    public function confirm(TwoFactorCodeRequest $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $codes = $this->twoFactor->confirmSetup($user, (string) $request->string('code'), $request);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Der Bestätigungscode ist ungültig.']);
        }

        $request->session()->put(LoginService::SESSION_TWO_FACTOR_VERIFIED, now()->toIso8601String());

        return view('security::two-factor.recovery-codes', ['codes' => $codes]);
    }

    public function regenerateRecoveryCodes(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('security::two-factor.recovery-codes', [
            'codes' => $this->twoFactor->regenerateRecoveryCodes($user, $request),
        ]);
    }

    public function disable(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->twoFactor->disable($user, $request);
        $request->session()->forget(LoginService::SESSION_TWO_FACTOR_VERIFIED);

        return redirect()->route('security.two-factor.setup')->with('status', 'Die Zwei-Faktor-Authentifizierung wurde deaktiviert.');
    }
}
