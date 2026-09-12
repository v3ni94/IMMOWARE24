<?php

declare(strict_types=1);

namespace App\Modules\Api\Support;

use App\Core\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Liest den authentifizierten Aufrufer (API-Key) aus dem Request, ohne die Security-Klassen hart zu binden.
 */
final class ApiCaller
{
    public const string ATTRIBUTE = 'api_key';

    public function __construct(private readonly OrganizationContext $organizationContext) {}

    public function apiKey(Request $request): ?Model
    {
        $key = $request->attributes->get(self::ATTRIBUTE);

        return $key instanceof Model ? $key : null;
    }

    public function apiKeyId(Request $request): ?int
    {
        $key = $this->apiKey($request);

        return $key !== null ? (int) $key->getKey() : null;
    }

    public function organizationId(Request $request): ?int
    {
        $key = $this->apiKey($request);

        if ($key !== null && $key->getAttribute('organization_id') !== null) {
            return (int) $key->getAttribute('organization_id');
        }

        return $this->organizationContext->get();
    }

    /**
     * @return array<int, string>
     */
    public function scopes(Request $request): array
    {
        $key = $this->apiKey($request);

        return $key !== null ? array_values(array_map('strval', (array) $key->getAttribute('scopes'))) : [];
    }

    public function hasScope(Request $request, string $scope): bool
    {
        $scopes = $this->scopes($request);

        return in_array('admin', $scopes, true) || in_array($scope, $scopes, true);
    }

    /**
     * Löst einen Bearer-Token optional auf (Health-Endpunkte ohne Pflicht-Auth).
     */
    public function resolveOptional(Request $request): ?Model
    {
        $existing = $this->apiKey($request);

        if ($existing !== null) {
            return $existing;
        }

        $token = $request->bearerToken();
        $serviceClass = 'App\Modules\Security\Services\ApiKeyService';

        if ($token === null || $token === '' || ! class_exists($serviceClass)) {
            return null;
        }

        /** @var object{resolve: callable} $service */
        $service = app($serviceClass);
        $key = $service->resolve($token);

        if (! $key instanceof Model || $key->getAttribute('revoked_at') !== null) {
            return null;
        }

        $expires = $key->getAttribute('expires_at');

        if ($expires === null || $expires->isPast()) {
            return null;
        }

        $request->attributes->set(self::ATTRIBUTE, $key);

        return $key;
    }
}
