<?php

declare(strict_types=1);

namespace App\Modules\Gmail\Http\Controllers;

use App\Modules\Gmail\Services\OAuth\GoogleOAuthService;
use App\Modules\Mail\Exceptions\MailIntegrationNotConfiguredException;
use App\Modules\Mail\Exceptions\MailRemoteException;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * OAuth2-Verbindung eines Postfachs mit Gmail: connect (Weiterleitung zu Google), callback (Code gegen Tokens),
 * revoke (Widerruf). Recht mail.integrations.manage auf beiden Ebenen (MailAccess::can), gleiche Organisation.
 * Die Integrationsseite selbst gehört dem Modul MailUi (Route mail.integrations.index); fehlt sie, wird auf die
 * Übersicht geleitet.
 */
final class OAuthController
{
    public function __construct(
        private readonly GoogleOAuthService $oauth,
        private readonly MailAccess $access,
    ) {}

    public function connect(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $user = $this->authorize($request, $mailbox);
        $functions = array_values(array_intersect((array) $request->input('functions', ['import']), ['import', 'drafts', 'send', 'aliases']));

        try {
            $authorization = $this->oauth->beginAuthorization($mailbox, $user, $functions === [] ? ['import'] : $functions);
        } catch (MailIntegrationNotConfiguredException) {
            return $this->back()->withErrors(['gmail' => 'Gmail ist nicht eingerichtet (Client-ID, Client-Secret oder Redirect-URI fehlen).']);
        }

        return redirect()->away($authorization['url']);
    }

    public function callback(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $error = $request->query('error');

        if (is_string($error) && $error !== '') {
            return $this->back()->withErrors(['gmail' => 'Google hat die Autorisierung abgelehnt ('.mb_substr($error, 0, 80).').']);
        }

        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');

        if ($state === '' || $code === '') {
            return $this->back()->withErrors(['gmail' => 'Antwort von Google unvollständig (state oder code fehlt).']);
        }

        try {
            $mailbox = $this->oauth->completeAuthorization($state, $code, $user);
        } catch (MailRemoteException $exception) {
            return $this->back()->withErrors(['gmail' => 'Autorisierung nicht abgeschlossen: '.$exception->getMessage()]);
        }

        return $this->back()->with('status', 'Postfach '.$mailbox->getAttribute('label').' ist mit Gmail verbunden. Der Import startet erst nach Aktivierung.');
    }

    public function revoke(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $user = $this->authorize($request, $mailbox);
        $confirmed = $this->oauth->revoke($mailbox, $user);

        return $this->back()->with('status', $confirmed
            ? 'Zugriff widerrufen und von Google bestätigt.'
            : 'Zugriff lokal widerrufen; die Bestätigung durch Google steht aus.');
    }

    private function authorize(Request $request, Mailbox $mailbox): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        if ((int) $user->getAttribute('organization_id') !== (int) $mailbox->getAttribute('organization_id')) {
            abort(404);
        }

        if (! $this->access->can($user, 'mail.integrations.manage', $mailbox->team_id === null ? null : (int) $mailbox->team_id)) {
            abort(403, 'Recht mail.integrations.manage fehlt.');
        }

        return $user;
    }

    private function back(): RedirectResponse
    {
        return app('router')->has('mail.integrations.index') ? redirect()->route('mail.integrations.index') : redirect()->route('mail.dashboard');
    }
}
