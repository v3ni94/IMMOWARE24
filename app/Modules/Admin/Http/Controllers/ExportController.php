<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Core\Enums\Role;
use App\Modules\Admin\Http\Requests\StoreHubExportRequest;
use App\Modules\Imports\Enums\HubExportFormat;
use App\Modules\Imports\Enums\HubExportStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Models\HubExport;
use App\Modules\Imports\Services\HubExportService;
use App\Modules\Imports\Services\ImportStorage;
use App\Modules\Security\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export: Formular Entität, Filter, Format, asynchroner ExportJob, Liste hub_exports mit Status und Download.
 * Download nur für den anfordernden Benutzer oder die Rollen Administrator und Owner.
 */
final class ExportController extends AdminController
{
    public function __construct(
        private readonly HubExportService $exports,
        private readonly ImportStorage $storage,
    ) {}

    public function index(Request $request): View
    {
        $this->requirePermission('exports.run');
        $user = $this->currentUser($request);

        $exports = HubExport::query()
            ->with('requestedBy:id,name')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $columns = [];

        foreach (HubExportService::ENTITIES as $entity => $definition) {
            $columns[$entity] = array_values(array_diff($definition['columns'], ['id', 'deleted_at']));
        }

        return view('admin::export.index', [
            'exports' => $exports,
            'entities' => array_keys(HubExportService::ENTITIES),
            'columns' => $columns,
            'formats' => HubExportFormat::cases(),
            'mayDownload' => fn (HubExport $export): bool => $this->mayDownload($user, $export),
            'queue' => (string) config('hub.core.queues.low', 'low'),
        ]);
    }

    public function store(StoreHubExportRequest $request): RedirectResponse
    {
        $this->requirePermission('exports.run');
        $user = $this->currentUser($request);
        $data = $request->validated();

        $filter = [];
        $column = trim((string) ($data['filter_column'] ?? ''));
        $value = (string) ($data['filter_value'] ?? '');

        if ($column !== '') {
            $allowed = HubExportService::ENTITIES[$data['entity']]['columns'] ?? [];

            if (! in_array($column, $allowed, true)) {
                return redirect()->route('admin.export.index')->withErrors(['filter_column' => 'Die Filterspalte ist für diese Entität nicht zulässig.'])->withInput();
            }

            $filter[$column] = $value === '' ? null : $value;
        }

        if ((bool) ($data['include_deleted'] ?? false)) {
            $filter['include_deleted'] = true;
        }

        try {
            $export = $this->exports->request(
                organizationId: (int) $user->getAttribute('organization_id'),
                entity: (string) $data['entity'],
                filter: $filter,
                format: HubExportFormat::from((string) $data['format']),
                requestedBy: (int) $user->getKey(),
            );
        } catch (ImportException $e) {
            return redirect()->route('admin.export.index')->withErrors(['entity' => $e->getMessage()])->withInput();
        }

        $this->audit('export.requested', $export, [], ['entity' => $export->entity, 'format' => $export->format->value, 'filter' => $filter]);

        return $this->redirectWithStatus('admin.export.index', sprintf('Export #%d eingereiht (Queue %s). Die Datei steht nach Abschluss zum Download bereit.', (int) $export->getKey(), (string) config('hub.core.queues.low', 'low')));
    }

    public function download(Request $request, int $export): StreamedResponse|RedirectResponse
    {
        $this->requirePermission('exports.run');
        $user = $this->currentUser($request);
        /** @var HubExport $export Explizite Suche, damit der Organization-Scope greift (Kontext aus admin.access). */
        $export = HubExport::query()->whereKey($export)->firstOrFail();

        if (! $this->mayDownload($user, $export)) {
            abort(403, 'Nur der anfordernde Benutzer oder ein Administrator darf diesen Export herunterladen.');
        }

        if ($export->status !== HubExportStatus::Completed || $export->storage_key === null) {
            return $this->redirectWithWarning('admin.export.index', 'Der Export ist noch nicht abgeschlossen.');
        }

        $disk = $this->storage->exportDisk();
        $key = (string) $export->storage_key;

        if (! $disk->exists($key)) {
            return redirect()->route('admin.export.index')->with('error', 'Die Exportdatei ist auf der Disk nicht mehr vorhanden.');
        }

        $this->audit('export.downloaded', $export, [], ['storage_key' => $key, 'row_count' => $export->row_count]);

        $filename = sprintf('%s_%d.%s', (string) $export->entity, (int) $export->getKey(), $export->format->value);
        $contentType = $export->format === HubExportFormat::Csv ? 'text/csv; charset=utf-8' : 'application/json';

        return response()->streamDownload(static function () use ($disk, $key): void {
            $stream = $disk->readStream($key);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, $filename, ['Content-Type' => $contentType, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function mayDownload(User $user, HubExport $export): bool
    {
        return (int) $export->requested_by === (int) $user->getKey() || $user->hasRole(Role::Administrator, Role::Owner);
    }
}
