<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Controllers;

use App\Modules\Security\Models\User;
use App\Modules\Security\Services\SessionManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SessionController
{
    public function __construct(private readonly SessionManager $sessions) {}

    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('security::sessions.index', [
            'sessions' => $this->sessions->activeSessions($user, $request->session()->getId()),
            'databaseDriver' => config('session.driver') === 'database',
        ]);
    }

    public function destroyOthers(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $count = $this->sessions->logoutOtherSessions($user, $request->session()->getId(), $request);

        return redirect()->route('security.sessions.index')->with('status', sprintf('%d andere Sitzung(en) beendet.', $count));
    }
}
