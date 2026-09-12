<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Middleware;

use App\Modules\Security\Http\ProblemResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verlangt einen Scope des API-Keys: Middleware scope:<scope>[,<scope>]. Der Scope admin umfasst alle Scopes.
 */
final class RequireScope
{
    public const string ADMIN_SCOPE = 'admin';

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $apiKey = AuthenticateApiKey::fromRequest($request);

        if ($apiKey === null) {
            return ProblemResponse::unauthenticated(detail: 'Kein authentifizierter API-Key im Request.');
        }

        if ($apiKey->hasScope(self::ADMIN_SCOPE)) {
            return $next($request);
        }

        foreach ($scopes as $scope) {
            if ($apiKey->hasScope($scope)) {
                return $next($request);
            }
        }

        return ProblemResponse::forbidden('insufficient_scope', sprintf('Erforderlicher Scope: %s.', implode(' oder ', $scopes)));
    }
}
