<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\AuditSource;
use App\Core\Enums\Role;
use App\Core\Support\GermanDate;
use App\Modules\Security\Models\AuditAnchor;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditChainVerifier;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Auditlog: Liste mit Filtern (Zeit, Benutzer, Aktion, Entität, Quelle, Correlation-ID), Detail vorher/nachher
 * (maskiert), Kettenstatus mit Prüfung. Keine Lösch- oder Bearbeitungsfunktion; das Modell ist append-only.
 * Rolle Nur Lesen sieht Einträge ohne before_json und after_json (08-security.md Abschnitt 7).
 */
final class AuditController extends AdminController
{
    private const string SESSION_VERIFY = 'admin.audit.verify';

    public function __construct(private readonly AuditChainVerifier $verifier) {}

    public function index(Request $request): View
    {
        $this->requirePermission('audit.view');
        $user = $this->currentUser($request);

        $filter = [
            'from' => trim((string) $request->query('from', '')),
            'to' => trim((string) $request->query('to', '')),
            'actor_id' => (int) $request->query('actor_id', '0'),
            'action' => trim((string) $request->query('action', '')),
            'entity_type' => trim((string) $request->query('entity_type', '')),
            'entity_id' => (int) $request->query('entity_id', '0'),
            'source' => trim((string) $request->query('source', '')),
            'correlation_id' => trim((string) $request->query('correlation_id', '')),
        ];

        $from = $filter['from'] !== '' ? GermanDate::parse($filter['from']) : null;
        $to = $filter['to'] !== '' ? GermanDate::parse($filter['to']) : null;

        $logs = $this->baseQuery($user)
            ->when($from !== null, static fn (Builder $q) => $q->where('occurred_at', '>=', $from))
            ->when($to !== null, static fn (Builder $q) => $q->where('occurred_at', '<', $to?->addDay()))
            ->when($filter['actor_id'] > 0, static fn (Builder $q) => $q->where('actor_type', 'user')->where('actor_id', $filter['actor_id']))
            ->when($filter['action'] !== '', static fn (Builder $q) => $q->where('action', 'like', $filter['action'].'%'))
            ->when($filter['entity_type'] !== '', static fn (Builder $q) => $q->where('entity_type', $filter['entity_type']))
            ->when($filter['entity_id'] > 0, static fn (Builder $q) => $q->where('entity_id', $filter['entity_id']))
            ->when($filter['source'] !== '' && AuditSource::tryFrom($filter['source']) !== null, static fn (Builder $q) => $q->where('source', $filter['source']))
            ->when($filter['correlation_id'] !== '', static fn (Builder $q) => $q->where('correlation_id', $filter['correlation_id']))
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $verify = $request->session()->pull(self::SESSION_VERIFY);
        $lastAnchor = AuditAnchor::query()->orderByDesc('last_audit_id')->first();

        return view('admin::audit.index', [
            'logs' => $logs,
            'filter' => $filter,
            'users' => User::query()->orderBy('name')->get(),
            'userNames' => User::query()->pluck('name', 'id')->all(),
            'sources' => AuditSource::cases(),
            'entityTypes' => $this->baseQuery($user)->whereNotNull('entity_type')->distinct()->orderBy('entity_type')->limit(100)->pluck('entity_type')->all(),
            'verify' => is_array($verify) ? $verify : null,
            'lastAnchor' => $lastAnchor,
            'total' => AuditLog::query()->count(),
            'showDiff' => $this->mayViewDiff($user),
        ]);
    }

    public function show(Request $request, int $log): View
    {
        $this->requirePermission('audit.view');
        $user = $this->currentUser($request);

        /** @var AuditLog $entry */
        $entry = $this->baseQuery($user)->whereKey($log)->firstOrFail();
        $previous = AuditLog::query()->where('id', '<', $entry->getKey())->orderByDesc('id')->first();

        return view('admin::audit.show', [
            'entry' => $entry,
            'chainOk' => $entry->verifyChain($previous instanceof AuditLog ? $previous : null),
            'actorName' => $entry->actor_type === 'user' && $entry->actor_id !== null ? User::query()->allOrganizations()->whereKey($entry->actor_id)->value('name') : null,
            'showDiff' => $this->mayViewDiff($user),
            'related' => $entry->correlation_id !== null
                ? $this->baseQuery($user)->where('correlation_id', $entry->correlation_id)->whereKeyNot($entry->getKey())->orderBy('id')->limit(50)->get()
                : collect(),
        ]);
    }

    /**
     * Prüft die gesamte Hash-Kette (lesend) und schreibt das Ergebnis als Auditeintrag.
     */
    public function verify(Request $request): RedirectResponse
    {
        $this->requirePermission('audit.view');

        $result = $this->verifier->verify();

        $request->session()->flash(self::SESSION_VERIFY, [
            'valid' => $result->valid,
            'checked' => $result->checked,
            'first_broken_id' => $result->firstBrokenId,
            'last_hash' => $result->lastHash,
            'message' => $result->message,
        ]);

        $this->audit('audit.chain_verified', null, [], [
            'valid' => $result->valid,
            'checked' => $result->checked,
            'first_broken_id' => $result->firstBrokenId,
            'last_hash' => $result->lastHash,
        ]);

        return $result->valid
            ? $this->redirectWithStatus('admin.audit.index', $result->message ?? 'Kette intakt.')
            : redirect()->route('admin.audit.index')->with('error', $result->message ?? 'Kette unterbrochen.');
    }

    /**
     * Einträge des eigenen Mandanten sowie mandantenlose Systemeinträge.
     *
     * @return Builder<AuditLog>
     */
    private function baseQuery(User $user): Builder
    {
        $organizationId = $user->getAttribute('organization_id');

        return AuditLog::query()->where(static function (Builder $q) use ($organizationId): void {
            $q->whereNull('organization_id');

            if ($organizationId !== null) {
                $q->orWhere('organization_id', (int) $organizationId);
            }
        });
    }

    private function mayViewDiff(User $user): bool
    {
        return $user->role !== Role::ReadOnly;
    }
}
