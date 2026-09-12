<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\StoreApiKeyRequest;
use App\Modules\Security\Models\ApiKey;
use App\Modules\Security\Services\ApiKeyService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * API: Schlüsselliste (Prefix, Scopes, Ablauf, letzte Nutzung, IP-Allowlist), Anlage mit Scope-Auswahl
 * (Klartext genau einmal sichtbar), Widerruf mit Bestätigung, Rate-Limits und Link zur Dokumentation.
 */
final class ApiKeysController extends AdminController
{
    private const string SESSION_PLAIN = 'admin.api.plain_key';

    public function __construct(private readonly ApiKeyService $keys) {}

    public function index(Request $request): View
    {
        $this->requirePermission('api_keys.manage');

        $state = (string) $request->query('state', 'active');

        $keys = ApiKey::query()
            ->with('createdBy:id,name')
            ->when($state === 'active', static fn (Builder $q) => $q->whereNull('revoked_at'))
            ->when($state === 'revoked', static fn (Builder $q) => $q->whereNotNull('revoked_at'))
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $revealed = $request->session()->pull(self::SESSION_PLAIN);

        return view('admin::api.index', [
            'keys' => $keys,
            'state' => $state,
            'revealed' => is_array($revealed) ? $revealed : null,
            'apiEnabled' => (bool) config('hub.core.api_keys.enabled', false),
            'rateLimits' => [
                'Lesende Anfragen je Minute und Key' => (int) config('hub.api.rate_limits.read_per_minute', 600),
                'Schreibende Anfragen je Minute und Key' => (int) config('hub.api.rate_limits.write_per_minute', 60),
                'Grundlimit je Key (Middleware throttle.apikey)' => (int) config('hub.security.api_keys.rate_limit_per_minute', 60),
            ],
            'docsUrl' => url('/api/docs'),
            'openapiUrl' => url('/api/docs/openapi.json'),
            'maxMonths' => (int) config('hub.security.api_keys.max_lifetime_months', 12),
        ]);
    }

    public function create(Request $request): View
    {
        $this->requirePermission('api_keys.manage');

        $months = (int) config('hub.security.api_keys.max_lifetime_months', 12);

        return view('admin::api.create', [
            'scopes' => $this->keys->knownScopes(),
            'maxMonths' => $months,
            'defaultExpiry' => CarbonImmutable::now()->addMonths(min(6, $months))->format('Y-m-d'),
            'maxExpiry' => CarbonImmutable::now()->addMonths($months)->format('Y-m-d'),
        ]);
    }

    public function store(StoreApiKeyRequest $request): RedirectResponse
    {
        $this->requirePermission('api_keys.manage');
        $data = $request->validated();
        $user = $this->currentUser($request);

        $ips = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($data['allowed_ips'] ?? '')) ?: []), static fn (string $ip): bool => $ip !== ''));
        $expiresAt = CarbonImmutable::parse((string) $data['expires_at'], 'Europe/Berlin')->endOfDay()->utc();

        try {
            $created = $this->keys->create(
                organizationId: (int) $user->getAttribute('organization_id'),
                name: (string) $data['name'],
                scopes: array_values((array) $data['scopes']),
                expiresAt: $expiresAt,
                createdBy: $user,
                allowedIps: $ips === [] ? null : $ips,
                request: $request,
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->route('admin.api.create')->withErrors(['scopes' => $e->getMessage()])->withInput();
        }

        $this->audit('api_keys.created', $created->apiKey, [], [
            'name' => $created->apiKey->name,
            'prefix' => $created->apiKey->prefix,
            'scopes' => $created->apiKey->scopes,
            'expires_at' => $expiresAt->toIso8601String(),
            'allowed_ips' => $ips,
        ]);

        $request->session()->flash(self::SESSION_PLAIN, ['id' => (int) $created->apiKey->getKey(), 'name' => $created->apiKey->name, 'key' => $created->plainTextKey]);

        return $this->redirectWithStatus('admin.api.index', 'API-Key angelegt. Der Schlüssel wird nur dieses eine Mal angezeigt.');
    }

    public function revoke(Request $request, int $key): RedirectResponse
    {
        $this->requirePermission('api_keys.manage');
        $this->requireConfirmation($request);
        /** @var ApiKey $key Explizite Suche, damit der Organization-Scope greift (Kontext aus admin.access). */
        $key = ApiKey::query()->whereKey($key)->firstOrFail();

        if ($key->revoked_at !== null) {
            return $this->redirectWithWarning('admin.api.index', 'Der Schlüssel ist bereits widerrufen.');
        }

        $this->keys->revoke($key, $request);
        $this->audit('api_keys.revoked', $key, ['revoked_at' => null], ['revoked_at' => $key->revoked_at?->toIso8601String(), 'prefix' => $key->prefix, 'reason' => (string) $request->input('reason', '')]);

        return $this->redirectWithStatus('admin.api.index', 'API-Key widerrufen. Anfragen mit diesem Schlüssel werden ab sofort abgelehnt.');
    }
}
