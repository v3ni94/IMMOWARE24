<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Services\IntegrationOverview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Integrationen: je Verbindung Status, Scopes, Capabilities, letzter erfolgreicher Abgleich, Rückstand, Fehler.
 * Verbinden und Widerrufen sind Links auf Routen der Fachmodule; fehlt die Route, ist der Button deaktiviert.
 */
final class IntegrationsController extends MailUiController
{
    public function __construct(
        private readonly IntegrationOverview $overview,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function index(Request $request): View
    {
        $user = $this->currentUser($request);
        $this->requirePermission($user, 'mail.integrations.manage');

        return view('mail::integrations.index', [
            'title' => 'Integrationen',
            'rows' => $this->overview->connections((int) $user->getAttribute('organization_id')),
            'states' => IntegrationOverview::STATES,
            'flags' => $this->flags->all(),
        ]);
    }
}
