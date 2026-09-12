<?php

declare(strict_types=1);

namespace App\Modules\MailUi\Http\Controllers;

use App\Core\Enums\AuditSource;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\MailUi\DTO\WorkflowResult;
use App\Modules\MailUi\Services\CaseVisibility;
use App\Modules\Security\Http\Middleware\RequireFreshTwoFactor;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditLogger;
use App\Modules\Security\Services\LoginService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;

/**
 * Basis der Controller der Mail-Oberfläche. Rechte werden zweistufig geprüft (MailAccess::can, Systemrolle und
 * Team-Rolle), Postfachinhalte zusätzlich über CaseVisibility. Jede mutierende Aktion schreibt einen Auditeintrag
 * mail.<bereich>.<verb> (AuditSource::Mail, append-only).
 */
abstract class MailUiController extends Controller
{
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }

    protected function access(): MailAccess
    {
        return App::make(MailAccess::class);
    }

    protected function visibility(): CaseVisibility
    {
        return App::make(CaseVisibility::class);
    }

    protected function requirePermission(User $user, string $permission, ?int $teamId = null): void
    {
        if (! $this->access()->can($user, $permission, $teamId)) {
            abort(403, 'Für diese Aktion fehlt das Recht '.$permission.'.');
        }
    }

    protected function requireCaseVisible(User $user, MailCase $case): void
    {
        if (! $this->visibility()->canView($user, $case)) {
            abort(403, 'Kein Postfachrecht für diesen Vorgang.');
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    protected function audit(string $action, ?Model $entity = null, array $before = [], array $after = []): AuditLog
    {
        /** @var AuditLogger $logger */
        $logger = App::make(AuditLogger::class);

        return $logger->record(
            action: 'mail.'.$action,
            entity: $entity,
            before: $before,
            after: $after,
            source: AuditSource::Mail,
            request: App::make(Request::class),
        );
    }

    protected function redirectWithResult(string $route, WorkflowResult $result, array $parameters = []): RedirectResponse
    {
        return redirect()->route($route, $parameters)->with($result->flashKey(), $result->message);
    }

    /**
     * Zeitpunkt der letzten Re-Authentifizierung aus der Sitzung; null, wenn kein Marker vorliegt. Kein Rückfall auf
     * now(), sonst wäre der Nachweis immer gefüllt und reauth_missing nie auslösbar.
     */
    protected function reauthTimestamp(Request $request): ?CarbonImmutable
    {
        foreach ([LoginService::SESSION_REAUTHENTICATED_AT, LoginService::SESSION_TWO_FACTOR_VERIFIED] as $key) {
            $value = $request->session()->get($key);

            if (is_string($value)) {
                try {
                    return CarbonImmutable::parse($value)->utc();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Aktuelle Re-Authentifizierung aus der Sitzung, sonst 403 mit Auditeintrag mail.<auditAction>. Die Prüfung
     * greift unabhängig davon, ob die Route in der Gruppe 2fa.fresh liegt (Verteidigung in der Tiefe).
     */
    protected function requireFreshReauth(Request $request, string $auditAction, ?Model $entity = null): CarbonImmutable
    {
        $reauth = $this->reauthTimestamp($request);

        if ($reauth === null || ! RequireFreshTwoFactor::isFresh($request)) {
            $this->audit($auditAction, $entity);
            abort(403, 'Diese Aktion ist ohne aktuelle Re-Authentifizierung nicht zulässig.');
        }

        return $reauth;
    }

    protected function perPage(): int
    {
        return max(10, min(200, (int) config('hub.mailui.per_page', 50)));
    }
}
