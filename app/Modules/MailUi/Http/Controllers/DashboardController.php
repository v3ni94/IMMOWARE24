<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Modules\Mail\Services\MailFeatureFlags;
use App\Modules\MailUi\Services\DashboardMetrics;
use App\Modules\MailUi\Services\IntegrationOverview;
use App\Modules\MailUi\Services\MailOpsMetrics;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Übersicht: Notfälle, unzugeordnete Vorgänge, überfällige Rückmeldungen, offene Freigaben, blockierte Integrationen
 * ("Nicht eingerichtet" oder Fehler), Teamlast sowie Betrieb (Push-Drosselungen 429, Watch-Ablauf je Postfach). Alle Zahlen aus sichtbaren Vorgängen des Nutzers.
 */
final class DashboardController extends MailUiController
{
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly IntegrationOverview $integrations,
        private readonly MailFeatureFlags $flags,
        private readonly MailOpsMetrics $ops,
    ) {}

    public function __invoke(Request $request): View
    {
        $user = $this->currentUser($request);
        $organizationId = (int) $user->getAttribute('organization_id');

        return view('mail::dashboard', [
            'title' => 'Übersicht',
            'emergencies' => $this->metrics->emergencies($user),
            'alerts' => $this->metrics->openAlerts($user),
            'unassigned' => $this->metrics->unassigned($user),
            'overdue' => $this->metrics->overdueReplies($user),
            'approvals' => $this->metrics->openApprovals($user),
            'incomplete' => $this->metrics->incompleteCount($user),
            'blocked' => $this->integrations->blocked($organizationId),
            'teamLoad' => $this->metrics->teamLoad($user),
            'pushRateLimited' => $this->ops->pushRateLimited(),
            'watchProblems' => $this->ops->watchProblems($organizationId),
            'flags' => $this->flags->all(),
            'canApprove' => $this->access()->can($user, 'mail.approve.standard'),
        ]);
    }
}
