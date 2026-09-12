<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use App\Core\Support\OrganizationContext;
use App\Modules\Security\Models\User;
use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Zugang zur Admin-Oberfläche: nur Rollen mit canLogin() (nie api_client), keine gesperrten oder
 * deaktivierten Konten. Setzt den Mandantenkontext aus dem angemeldeten Nutzer, damit alle
 * Global Scopes (BelongsToOrganization) im Web-Request greifen.
 */
final class EnsureAdminAccess
{
    public function __construct(
        private readonly OrganizationContext $organizationContext,
        private readonly AuthFactory $auth,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        if (! $user->role->canLogin()) {
            abort(403, 'Die Rolle API-Client hat keinen Zugang zur Admin-Oberfläche.');
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

        $organizationId = $user->getAttribute('organization_id');

        if ($organizationId !== null) {
            $this->organizationContext->set((int) $organizationId);
        }

        return $next($request);
    }
}
