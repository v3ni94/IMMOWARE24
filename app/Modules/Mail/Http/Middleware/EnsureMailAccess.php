<?php

declare(strict_types=1);

namespace App\Modules\Mail\Http\Middleware;

use App\Core\Support\OrganizationContext;
use App\Modules\Mail\Services\MailAccess;
use App\Modules\Security\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zugang zur Mail-Oberfläche (Alias mail.access): nur Rollen mit canLogin() (nie api_client), keine gesperrten oder
 * deaktivierten Konten, globales Recht mail.inbox.view. Setzt den Mandantenkontext aus dem angemeldeten Nutzer.
 */
final class EnsureMailAccess
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly AuthFactory $auth,
        private readonly MailAccess $access,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if (! $user->role->canLogin()) {
            abort(403, 'Die Rolle API-Client hat keinen Zugang zur Mail-Oberfläche.');
        }

        if ($user->isDisabled() || $user->isLocked()) {
            $guard = $this->auth->guard('web');

            if (method_exists($guard, 'logout')) {
                $guard->logout();
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Das Konto ist gesperrt oder deaktiviert.']);
        }

        if (! $this->access->hasGlobalPermission($user, 'mail.inbox.view')) {
            abort(403, 'Kein Zugang zur Mail-Oberfläche (Recht mail.inbox.view fehlt).');
        }

        $organizationId = $user->getAttribute('organization_id');

        if ($organizationId !== null) {
            $this->organizationContext->set((int) $organizationId);
        }

        return $next($request);
    }
}
