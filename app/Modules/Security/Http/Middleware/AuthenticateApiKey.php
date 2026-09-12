<?php

declare(strict_types=1);

namespace App\Modules\Security\Http\Middleware;

use App\Core\Support\OrganizationContext;
use App\Modules\Security\Http\ProblemResponse;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Services\ApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authentifiziert Requests über Authorization: Bearer hub_live_<prefix>_<secret>.
 * Legt den Key als Request-Attribut api_key ab und setzt den Mandantenkontext.
 */
final class AuthenticateApiKey
{
    public const string ATTRIBUTE = 'api_key';

    public function __construct(
        private readonly ApiKeyService $apiKeys,
        private readonly OrganizationContext $organizationContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return ProblemResponse::unauthenticated(detail: 'Authorization-Header mit Bearer-Token fehlt.');
        }

        $apiKey = $this->apiKeys->resolve($token);

        if ($apiKey === null) {
            return ProblemResponse::unauthenticated(detail: 'Der API-Key ist ungültig.');
        }

        if ($apiKey->getAttribute('revoked_at') !== null) {
            return ProblemResponse::unauthenticated('key_revoked', 'Der API-Key wurde widerrufen.');
        }

        $expires = $apiKey->getAttribute('expires_at');

        if ($expires === null || $expires->isPast()) {
            return ProblemResponse::unauthenticated('key_expired', 'Der API-Key ist abgelaufen.');
        }

        if (! $this->apiKeys->ipAllowed($apiKey, $request->ip())) {
            return ProblemResponse::forbidden('ip_not_allowed', 'Die Quell-IP ist für diesen API-Key nicht freigegeben.');
        }

        $request->attributes->set(self::ATTRIBUTE, $apiKey);
        $this->organizationContext->set((int) $apiKey->getAttribute('organization_id'));
        $this->apiKeys->touch($apiKey, $request);

        return $next($request);
    }

    public static function fromRequest(Request $request): ?ApiKey
    {
        $key = $request->attributes->get(self::ATTRIBUTE);

        return $key instanceof ApiKey ? $key : null;
    }
}
