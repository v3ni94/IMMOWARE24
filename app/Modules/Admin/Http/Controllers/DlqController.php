<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Controllers\Concerns\ResolvesOrganizationConnections;
use App\Modules\Admin\Http\Requests\DlqIgnoreRequest;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Sync\Enums\DlqStatus;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Models\DlqItem;
use App\Modules\Sync\Services\DlqService;
use App\Modules\Sync\Services\FieldMappingService;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Fehlerqueue (DLQ): Liste, Detail mit maskiertem Payload, Fehler, aktivem Mapping, Retry (nur dieser
 * Eintrag, ProcessDlqRetryJob) und Ignorieren mit Begründung. Mutationen erfordern sync.run.
 */
final class DlqController extends AdminController
{
    use ResolvesOrganizationConnections;

    /** @var array<string, string> */
    public const array STATUS_LABELS = [
        'open' => 'offen',
        'retrying' => 'Wiederaufnahme eingeplant',
        'replayed' => 'erfolgreich wiederholt',
        'ignored' => 'ignoriert',
        'failed' => 'erneut fehlgeschlagen',
    ];

    public function __construct(
        private readonly DlqService $dlq,
        private readonly FieldMappingService $mappings,
    ) {}

    public function index(Request $request): View
    {
        $filters = [
            'status' => (string) $request->query('status', 'open'),
            'connection_id' => (int) $request->query('connection_id', '0'),
            'entity_type' => (string) $request->query('entity_type', ''),
            'job_class' => (string) $request->query('job_class', ''),
        ];

        $query = $this->scoped()->orderByDesc('failed_at');

        if (DlqStatus::tryFrom($filters['status']) !== null) {
            $query->where('status', $filters['status']);
        }

        if ($filters['connection_id'] > 0) {
            $query->where('connection_id', $filters['connection_id']);
        }

        if (in_array($filters['entity_type'], SyncEntity::values(), true)) {
            $query->where('entity_type', $filters['entity_type']);
        }

        if ($filters['job_class'] !== '') {
            $query->where('job_class', 'like', '%'.addcslashes(mb_substr($filters['job_class'], 0, 120), '%_\\').'%');
        }

        $items = $query->paginate($this->perPage())->withQueryString();

        return view('admin::dlq.index', [
            'items' => $items,
            'filters' => $filters,
            'connectionOptions' => $this->connectionOptions(),
            'connectionNames' => $this->namesById(ImmowareConnection::query()->whereKey($items->getCollection()->pluck('connection_id')->filter()->unique()->all())->get()),
            'statusLabels' => self::STATUS_LABELS,
            'statuses' => array_map(static fn (DlqStatus $s): string => $s->value, DlqStatus::cases()),
        ]);
    }

    public function show(int $id): View
    {
        /** @var DlqItem $item */
        $item = $this->scoped()->with(['connection', 'replayedBy'])->findOrFail($id);

        $entityType = $item->getAttribute('entity_type');
        $entity = is_string($entityType) ? SyncEntity::tryFrom($entityType) : null;
        $mapping = $entity !== null ? $this->mappings->active($entity->value, $entity->sourceFormat()) : null;
        $payload = (array) $item->getAttribute('payload_json');
        $arguments = (array) ($payload['arguments'] ?? []);

        return view('admin::dlq.show', [
            'item' => $item,
            'payload' => $this->dlq->payload((int) $item->getKey()),
            'mapping' => $mapping,
            'singleRecord' => (bool) ($arguments['singleRecord'] ?? false),
            'statusLabels' => self::STATUS_LABELS,
            'canMutate' => $this->allows('sync.run'),
            'retryable' => in_array($item->getAttribute('status'), [DlqStatus::Open, DlqStatus::Failed], true),
        ]);
    }

    public function retry(Request $request, int $id): RedirectResponse
    {
        $this->requirePermission('sync.run');
        $this->requireConfirmation($request);

        /** @var DlqItem $item */
        $item = $this->scoped()->findOrFail($id);
        $before = ['status' => $item->status?->value];

        try {
            $item = $this->dlq->retry((int) $item->getKey(), $this->currentUser($request));
        } catch (InvalidArgumentException $exception) {
            return $this->redirectWithWarning('admin.dlq.show', $exception->getMessage(), ['id' => $id]);
        }

        $this->audit('dlq.retried', $item, $before, [
            'status' => $item->status?->value,
            'job_class' => $item->getAttribute('job_class'),
            'entity_type' => $item->getAttribute('entity_type'),
        ], $item->getAttribute('connection_id') !== null ? (int) $item->getAttribute('connection_id') : null);

        return $this->redirectWithStatus('admin.dlq.show', 'Wiederaufnahme eingeplant. Es wird ausschließlich dieser Eintrag erneut verarbeitet (ProcessDlqRetryJob, ein Versuch).', ['id' => $id]);
    }

    public function ignore(DlqIgnoreRequest $request, int $id): RedirectResponse
    {
        $this->requirePermission('sync.run');
        $this->requireConfirmation($request);

        /** @var DlqItem $item */
        $item = $this->scoped()->findOrFail($id);
        $before = ['status' => $item->status?->value];
        $note = (string) $request->validated()['note'];

        if ($item->getAttribute('status') === DlqStatus::Ignored) {
            return $this->redirectWithWarning('admin.dlq.show', 'Der Eintrag ist bereits ignoriert.', ['id' => $id]);
        }

        $item = $this->dlq->ignore((int) $item->getKey(), $this->currentUser($request), $note);

        $this->audit('dlq.ignored', $item, $before, [
            'status' => $item->status?->value,
            'note' => $note,
        ], $item->getAttribute('connection_id') !== null ? (int) $item->getAttribute('connection_id') : null);

        return $this->redirectWithStatus('admin.dlq.show', 'Eintrag ignoriert. Begründung im Auditlog gespeichert.', ['id' => $id]);
    }

    /**
     * DLQ-Einträge des Mandanten: mit Connection des Mandanten oder ohne Connection (globale Jobs).
     *
     * @return Builder<DlqItem>
     */
    private function scoped(): Builder
    {
        return DlqItem::query()->where(function (Builder $query): void {
            $query->whereIn('connection_id', $this->connectionIdsQuery())->orWhereNull('connection_id');
        });
    }

    private function allows(string $permission): bool
    {
        /** @var Gate $gate */
        $gate = app(Gate::class);

        return $gate->allows($permission);
    }
}
