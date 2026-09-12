<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\Role;
use App\Modules\Connector\Enums\RemoteRequestOutcome;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Models\RemoteRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Discovery-Konsole: protokollierte Requests an Immoware24 (remote_requests) mit Filtern und Detail
 * (Header maskiert, Request-Bodies werden nie gespeichert). Nur Rollen Developer, Administrator, Owner.
 */
final class DiscoveryController extends AdminController
{
    private const array ALLOWED_ROLES = [Role::Developer, Role::Administrator, Role::Owner];

    public function index(Request $request): View
    {
        $this->requireRole($request);

        $filter = [
            'connection' => (int) $request->query('connection', '0'),
            'connector' => trim((string) $request->query('connector', '')),
            'method' => strtoupper(trim((string) $request->query('method', ''))),
            'outcome' => trim((string) $request->query('outcome', '')),
            'status' => (int) $request->query('status', '0'),
            'path' => trim((string) $request->query('path', '')),
            'correlation_id' => trim((string) $request->query('correlation_id', '')),
            'fingerprint' => trim((string) $request->query('fingerprint', '')),
        ];

        $requests = $this->baseQuery()
            ->with('connection:id,name')
            ->when($filter['connection'] > 0, static fn (Builder $q) => $q->where('connection_id', $filter['connection']))
            ->when($filter['connector'] !== '', static fn (Builder $q) => $q->where('connector_name', $filter['connector']))
            ->when($filter['method'] !== '', static fn (Builder $q) => $q->where('method', $filter['method']))
            ->when($filter['outcome'] !== '' && RemoteRequestOutcome::tryFrom($filter['outcome']) !== null, static fn (Builder $q) => $q->where('outcome', $filter['outcome']))
            ->when($filter['status'] > 0, static fn (Builder $q) => $q->where('response_status', $filter['status']))
            ->when($filter['path'] !== '', static fn (Builder $q) => $q->where('path', 'like', '%'.$filter['path'].'%'))
            ->when($filter['correlation_id'] !== '', static fn (Builder $q) => $q->where('correlation_id', $filter['correlation_id']))
            ->when($filter['fingerprint'] !== '', static fn (Builder $q) => $q->where('response_schema_fingerprint', 'like', $filter['fingerprint'].'%'))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin::discovery.index', [
            'requests' => $requests,
            'filter' => $filter,
            'connections' => ImmowareConnection::query()->orderBy('name')->get(),
            'connectors' => $this->baseQuery()->whereNotNull('connector_name')->distinct()->orderBy('connector_name')->limit(50)->pluck('connector_name')->all(),
            'methods' => $this->baseQuery()->distinct()->orderBy('method')->limit(20)->pluck('method')->all(),
            'outcomes' => RemoteRequestOutcome::cases(),
            'environment' => (string) config('app.env', 'production'),
            'retentionDays' => (int) config('hub.connector.remote_requests.retention_days', 90),
        ]);
    }

    public function show(Request $httpRequest, int $request): View
    {
        $this->requireRole($httpRequest);

        /** @var RemoteRequest $entry */
        $entry = $this->baseQuery()->with('connection:id,name,connector_type')->whereKey($request)->firstOrFail();

        $sameFingerprint = $entry->response_schema_fingerprint !== null
            ? $this->baseQuery()->where('response_schema_fingerprint', $entry->response_schema_fingerprint)->count()
            : 0;

        return view('admin::discovery.show', [
            'entry' => $entry,
            'sameFingerprint' => $sameFingerprint,
            'environment' => (string) config('app.env', 'production'),
        ]);
    }

    /**
     * Requests der Connections des eigenen Mandanten sowie Requests ohne Connection (Probe ohne Zuordnung).
     *
     * @return Builder<RemoteRequest>
     */
    private function baseQuery(): Builder
    {
        return RemoteRequest::query()->where(static function (Builder $q): void {
            $q->whereNull('connection_id')->orWhereIn('connection_id', ImmowareConnection::query()->select('id'));
        });
    }

    private function requireRole(Request $request): void
    {
        $user = $this->currentUser($request);

        if (! $user->hasRole(...self::ALLOWED_ROLES)) {
            abort(403, 'Die Discovery-Konsole steht nur den Rollen Entwickler, Administrator und Owner offen.');
        }
    }
}
