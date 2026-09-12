<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Modules\Security\Http\Requests\LoginRequest;
use App\Modules\Security\Services\LoginService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class LoginController
{
    public function __construct(private readonly LoginService $login) {}

    public function show(): View
    {
        return view('security::auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $email = (string) $request->string('email');

        if ($this->login->isLockedOut($email)) {
            throw ValidationException::withMessages([
                'email' => 'Das Konto ist vorübergehend gesperrt. Bitte versuchen Sie es später erneut.',
            ]);
        }

        $user = $this->login->attempt($email, (string) $request->string('password'), $request, $request->boolean('remember'));

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'Die Anmeldedaten sind ungültig oder das Konto ist gesperrt.',
            ]);
        }

        if ($user->hasConfirmedTotp()) {
            return redirect()->route('security.two-factor.challenge');
        }

        return redirect()->intended(route('security.two-factor.setup'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $this->login->logout($request);

        return redirect()->route('login')->with('status', 'Sie wurden abgemeldet.');
    }
}
