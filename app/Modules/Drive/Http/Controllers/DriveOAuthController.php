<?php

declare(strict_types=1);

namespace App\Modules\Drive\Http\Controllers;

use App\Modules\Drive\Services\DriveOAuthService;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OAuth2-Verbindung der Organisation mit Google Drive (nur lesend): connect (Weiterleitung zu Google), callback
 * (Code gegen Tokens), revoke (Widerruf). Recht mail.integrations.manage (MailAccess::can, organisationsweit).
 * Die Integrationsseite gehört dem Modul MailUi (Route mail.integrations.index); fehlt sie, wird auf die
 * Übersicht geleitet. Routen ohne Parameter, damit die Integrationsseite sie direkt verlinken kann.
 */
final class DriveOAuthController
{
    public function __construct(
        private readonly DriveOAuthService $oauth,
        private readonly MailAccess $access,
    ) {}

    public function connect(Request $request): RedirectResponse
    {
        $user = $this->authorize($request);

        try {
            $authorization = $this->oauth->beginAuthorization($user);
        } catch (MailIntegrationNotConfiguredException) {
            return $this->back()->withErrors(['drive' => 'Google Drive ist nicht eingerichtet (Client-ID, Client-Secret oder Redirect-URI fehlen).']);
        }

        return redirect()->away($authorization['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $this->authorize($request);
        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            return $this->back()->withErrors(['drive' => 'Google hat die Autorisierung abgelehnt ('.mb_substr($error, 0, 80).').']);
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return $this->back()->withErrors(['drive' => 'Antwort von Google unvollständig (state oder code fehlt).']);
        }

        try {
            $connection = $this->oauth->completeAuthorization($state, $code, $user);
        } catch (MailRemoteException $exception) {
            return $this->back()->withErrors(['drive' => 'Autorisierung nicht abgeschlossen: '.$exception->getMessage()]);
        }

        $account = $connection->getAttribute('account_email');

        return $this->back()->with('status', 'Google Drive ist verbunden (nur lesend)'.(is_string($account) && $account !== '' ? ', Konto '.$account : '').'.');
    }

    public function revoke(Request $request): RedirectResponse
    {
        $user = $this->authorize($request);
        $confirmed = $this->oauth->revoke($user);

        if ($confirmed === null) {
            return $this->back()->withErrors(['drive' => 'Keine Google-Drive-Verbindung vorhanden.']);
        }

        return $this->back()->with('status', $confirmed
            ? 'Zugriff auf Google Drive widerrufen und von Google bestätigt.'
            : 'Zugriff auf Google Drive lokal widerrufen; die Bestätigung durch Google steht aus.');
    }

    private function authorize(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if (! $this->access->can($user, 'mail.integrations.manage')) {
            abort(403, 'Recht mail.integrations.manage fehlt.');
        }

        return $user;
    }

    private function back(): RedirectResponse
    {
        return app('router')->has('mail.integrations.index') ? redirect()->route('mail.integrations.index') : redirect()->route('mail.dashboard');
    }
}
