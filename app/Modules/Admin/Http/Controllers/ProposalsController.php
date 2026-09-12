<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Controllers\Concerns\ResolvesOrganizationConnections;
use App\Modules\Sync\Enums\ProposedChangeStatus;
use App\Modules\Sync\Models\ProposedChange;
use App\Modules\Sync\Services\ProposedChangeService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Änderungsvorschläge (proposed_changes): der manuelle Rückweg nach Immoware24. Der Hub schreibt nie
 * zurück; ein Mitarbeiter setzt die Änderung in Immoware24 um und markiert sie hier als übertragen.
 * Markieren erfordert conflicts.resolve (Rolle Operator, 08-security.md Abschnitt 4).
 */
final class ProposalsController extends AdminController
{
    use ResolvesOrganizationConnections;

    /** @var array<string, string> */
    public const array STATUS_LABELS = [
        'open' => 'offen',
        'transferred' => 'in Immoware24 übertragen',
        'confirmed' => 'durch Sync bestätigt',
        'rejected' => 'abgelehnt',
    ];

    public function __construct(private readonly ProposedChangeService $proposals) {}

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'entity_type' => (string) $request->query('entity_type', ''),
        ];

        $query = $this->scoped()->with(['requestedBy', 'transferredBy'])->orderByDesc('created_at');

        if (ProposedChangeStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['entity_type'] !== '') {
            $query->where('entity_type', mb_substr($filters['entity_type'], 0, 40));
        }

        return view('admin::proposals.index', [
            'proposals' => $query->paginate($this->perPage())->withQueryString(),
            'filters' => $filters,
            'statusLabels' => self::STATUS_LABELS,
            'statuses' => array_map(static fn (ProposedChangeStatus $s): string => $s->value, ProposedChangeStatus::cases()),
        ]);
    }

    public function show(int $id): View
    {
        /** @var ProposedChange $proposal */
        $proposal = $this->scoped()->with(['connection', 'requestedBy', 'transferredBy', 'confirmedBySyncRun'])->findOrFail($id);

        return view('admin::proposals.show', [
            'proposal' => $proposal,
            'statusLabels' => self::STATUS_LABELS,
            'canTransfer' => $this->allows('conflicts.resolve') && $proposal->getAttribute('status') === ProposedChangeStatus::Open,
        ]);
    }

    public function transfer(Request $request, int $id): RedirectResponse
    {
        $this->requirePermission('conflicts.resolve');
        $this->requireConfirmation($request);

        /** @var ProposedChange $proposal */
        $proposal = $this->scoped()->findOrFail($id);
        $user = $this->currentUser($request);

        try {
            $proposal = $this->proposals->markTransferred((int) $proposal->getKey(), $user);
        } catch (InvalidArgumentException $exception) {
            return $this->redirectWithWarning('admin.proposals.show', $exception->getMessage(), ['id' => $id]);
        }

        $this->audit('proposals.transferred', $proposal, ['status' => ProposedChangeStatus::Open->value], [
            'status' => ProposedChangeStatus::Transferred->value,
            'transferred_by' => $user->getKey(),
            'transferred_at' => $proposal->getAttribute('transferred_at')?->toIso8601String(),
            'note' => (string) $request->input('note', ''),
        ], $proposal->getAttribute('connection_id') !== null ? (int) $proposal->getAttribute('connection_id') : null);

        return $this->redirectWithStatus('admin.proposals.show', 'Als in Immoware24 übertragen markiert. Der nächste Sync bestätigt den neuen Wert im Spiegel.', ['id' => $id]);
    }

    /**
     * Vorschläge des Mandanten: organization_id passend oder Connection des Mandanten.
     *
     * @return Builder<ProposedChange>
     */
    private function scoped(): Builder
    {
        $organizationId = $this->currentUser(request())->getAttribute('organization_id');

        return ProposedChange::query()->where(function (Builder $query) use ($organizationId): void {
            $query->whereIn('connection_id', $this->connectionIdsQuery());

            if ($organizationId !== null) {
                $query->orWhere('organization_id', (int) $organizationId);
            }
        });
    }

    private function allows(string $permission): bool
    {
        /** @var Gate $gate */
        $gate = app(Gate::class);

        return $gate->allows($permission);
    }
}
