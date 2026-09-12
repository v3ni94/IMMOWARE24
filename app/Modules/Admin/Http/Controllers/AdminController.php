<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\AuditSource;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;

/**
 * Basis aller Admin-Controller. Jede mutierende Aktion ruft audit() auf; der Eintrag ist append-only
 * (Hash-Kette, Maskierung über SecretMasker im AuditLogger). Rechte werden über die Gates aus der
 * Permission-Map (config/hub/security.php) geprüft.
 */
abstract class AdminController extends Controller
{
    /**
     * Schreibt einen Auditeintrag für eine mutierende Admin-Aktion. Aktion im Format bereich.verb,
     * z. B. connections.paused, users.role_changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function audit(string $action, ?Model $entity = null, array $before = [], array $after = [], ?int $connectionId = null): AuditLog
    {
        /** @var AuditLogger $logger */
        $logger = App::make(AuditLogger::class);

        return $logger->record(
            action: 'admin.'.$action,
            entity: $entity,
            before: $before,
            after: $after,
            source: AuditSource::User,
            request: App::make(Request::class),
            connectionId: $connectionId,
        );
    }

    /**
     * Prüft ein Recht der Permission-Map (403 bei Verweigerung).
     */
    protected function requirePermission(string $permission): void
    {
        /** @var Gate $gate */
        $gate = App::make(Gate::class);

        if (! $gate->allows($permission)) {
            abort(403, 'Für diese Aktion fehlt das Recht '.$permission.'.');
        }
    }

    /**
     * Prüft, ob das Bestätigungswort eines confirm-form korrekt eingegeben wurde.
     */
    protected function requireConfirmation(Request $request, string $field = 'confirmation'): void
    {
        $expected = (string) config('hub.admin.confirm_word', 'BESTÄTIGEN');
        $given = trim((string) $request->input($field, ''));

        if ($given !== $expected) {
            abort(422, 'Die Aktion wurde nicht bestätigt. Bitte das Bestätigungswort eingeben.');
        }
    }

    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    protected function redirectWithStatus(string $route, string $message, array $parameters = []): RedirectResponse
    {
        return redirect()->route($route, $parameters)->with('status', $message);
    }

    protected function redirectWithWarning(string $route, string $message, array $parameters = []): RedirectResponse
    {
        return redirect()->route($route, $parameters)->with('warning', $message);
    }

    protected function perPage(): int
    {
        return max(10, min(200, (int) config('hub.admin.per_page', 50)));
    }
}
