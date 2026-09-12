<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Http\Requests\ConfirmImportFormatRequest;
use App\Modules\Admin\Http\Requests\StoreExportScheduleRequest;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Enums\ImportFormatStatus;
use App\Modules\Imports\Exceptions\ImportException;
use App\Modules\Imports\Models\ExportSchedule;
use App\Modules\Imports\Models\ImportFile;
use App\Modules\Imports\Models\ImportFormat;
use App\Modules\Imports\Services\ExportScheduleService;
use App\Modules\Imports\Services\HeaderNormalizer;
use App\Modules\Imports\Services\ImporterRegistry;
use App\Modules\Imports\Services\ImportFormatService;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Importe: import_files mit Status, Detail mit Fehlerliste, Quarantäne mit Bestätigung des Spaltenmappings
 * (ImportFormatService::confirm) sowie Verwaltung der Exportrhythmen (export_schedules).
 */
final class ImportsController extends AdminController
{
    public function __construct(
        private readonly ImportFormatService $formats,
        private readonly ImporterRegistry $importers,
        private readonly ExportScheduleService $schedules,
    ) {}

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', '');
        $type = (string) $request->query('export_type', '');

        $files = ImportFile::query()
            ->with(['connection:id,name', 'format:id,format_key,version,status'])
            ->when($status !== '' && ImportFileStatus::tryFrom($status) !== null, static fn (Builder $q) => $q->where('status', $status))
            ->when($type !== '' && ExportType::tryFrom($type) !== null, static fn (Builder $q) => $q->where('export_type', $type))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        $counts = ImportFile::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all();

        return view('admin::imports.index', [
            'files' => $files,
            'counts' => $counts,
            'statuses' => ImportFileStatus::cases(),
            'types' => ExportType::cases(),
            'filter' => ['status' => $status, 'export_type' => $type],
            'overdue' => $this->schedules->overdue()->count(),
        ]);
    }

    public function show(Request $request, int $file): View
    {
        $file = $this->findFile($file);
        $file->load(['connection:id,name', 'format', 'uploadedBy:id,name', 'syncRun:id,status,started_at,finished_at']);

        return view('admin::imports.show', [
            'file' => $file,
            'errors_list' => array_values((array) ($file->errors ?? [])),
        ]);
    }

    /**
     * Quarantäne-Ansicht mit Header-Fingerprint, Spaltenliste und Formular zur Bestätigung des Mappings.
     */
    public function quarantine(Request $request, int $file): View
    {
        $this->requirePermission('imports.run');
        $file = $this->findFile($file);

        $format = $file->format;
        $exportType = ExportType::tryFrom((string) $file->export_type);
        $targetFields = $exportType !== null && $this->importers->has($exportType) ? $this->importers->for($exportType)->targetFields() : [];
        $headers = $format !== null ? array_map(static fn ($h): string => (string) $h, (array) $format->header_columns) : [];

        return view('admin::imports.quarantine', [
            'file' => $file,
            'format' => $format,
            'exportType' => $exportType,
            'headers' => $headers,
            'normalizedHeaders' => HeaderNormalizer::normalizeAll($headers),
            'targetFields' => $targetFields,
            'existingMapping' => $format !== null ? (array) $format->column_mapping : [],
            'existingKeys' => $format !== null ? (array) $format->key_schema : [],
            'confirmable' => $format !== null && $format->status !== ImportFormatStatus::Confirmed->value && $targetFields !== [],
        ]);
    }

    public function confirmFormat(ConfirmImportFormatRequest $request, int $file): RedirectResponse
    {
        $this->requirePermission('imports.run');
        $file = $this->findFile($file);
        $this->requireConfirmation($request);

        $format = $file->format;

        if (! $format instanceof ImportFormat) {
            return $this->redirectWithWarning('admin.imports.show', 'Zu dieser Datei ist kein Format erfasst, eine Bestätigung ist nicht möglich.', ['file' => $file->getKey()]);
        }

        $mapping = array_filter(array_map('strval', (array) $request->validated('mapping', [])), static fn (string $v): bool => $v !== '');
        $keySchema = array_values(array_map('strval', (array) $request->validated('key_schema', [])));
        $exportType = ExportType::tryFrom((string) $file->export_type);
        $before = ['status' => $format->status, 'column_mapping' => (array) $format->column_mapping, 'key_schema' => (array) $format->key_schema];

        try {
            $confirmed = $this->formats->confirm((string) $format->header_fingerprint, $mapping, $keySchema, (int) $this->currentUser($request)->getKey(), $exportType);
        } catch (ImportException $e) {
            return redirect()->route('admin.imports.quarantine', ['file' => $file->getKey()])->withErrors(['mapping' => $e->getMessage()])->withInput();
        }

        $this->audit('imports.format_confirmed', $confirmed, $before, [
            'status' => $confirmed->status,
            'header_fingerprint' => $confirmed->header_fingerprint,
            'column_mapping' => $mapping,
            'key_schema' => $keySchema,
            'import_file_id' => (int) $file->getKey(),
        ], $file->connection_id !== null ? (int) $file->connection_id : null);

        return $this->redirectWithStatus('admin.imports.show', 'Spaltenmapping bestätigt. Die Datei wird beim nächsten Lauf von hub:imports:scan --process erneut verarbeitet.', ['file' => $file->getKey()]);
    }

    public function schedules(Request $request): View
    {
        $now = CarbonImmutable::now();
        $grace = (int) config('hub.imports.reminder_grace_days', 0);
        $threshold = $now->subDays($grace);

        $schedules = ExportSchedule::query()
            ->with(['connection:id,name', 'responsibleUser:id,name'])
            ->whereIn('connection_id', $this->connectionIds())
            ->orderByRaw('case when next_due_at is null then 0 else 1 end')
            ->orderBy('next_due_at')
            ->paginate($this->perPage())
            ->withQueryString();

        return view('admin::imports.schedules', [
            'schedules' => $schedules,
            'threshold' => $threshold,
            'connections' => ImmowareConnection::query()->orderBy('name')->get(),
            'users' => User::query()->whereNull('disabled_at')->orderBy('name')->get(),
            'types' => ExportType::cases(),
            'canManage' => $request->user()?->can('imports.run') ?? false,
        ]);
    }

    public function storeSchedule(StoreExportScheduleRequest $request): RedirectResponse
    {
        $this->requirePermission('imports.run');
        $data = $request->validated();

        if (! $this->connectionIds()->contains((int) $data['connection_id'])) {
            abort(403, 'Die Verbindung gehört nicht zum eigenen Mandanten.');
        }

        $exists = ExportSchedule::query()->where('connection_id', (int) $data['connection_id'])->where('export_type', $data['export_type'])->exists();

        if ($exists) {
            return redirect()->route('admin.imports.schedules.index')->withErrors(['export_type' => 'Für diese Verbindung und diesen Exporttyp existiert bereits ein Zeitplan.'])->withInput();
        }

        $schedule = ExportSchedule::query()->create([
            'connection_id' => (int) $data['connection_id'],
            'export_type' => $data['export_type'],
            'responsible_user_id' => isset($data['responsible_user_id']) && $data['responsible_user_id'] !== '' ? (int) $data['responsible_user_id'] : null,
            'interval_days' => (int) $data['interval_days'],
            'next_due_at' => CarbonImmutable::now()->addDays((int) $data['interval_days']),
        ]);

        $this->audit('imports.schedule_created', $schedule, [], $schedule->only(['connection_id', 'export_type', 'responsible_user_id', 'interval_days']), (int) $schedule->connection_id);

        return $this->redirectWithStatus('admin.imports.schedules.index', 'Exportrhythmus angelegt.');
    }

    public function updateSchedule(StoreExportScheduleRequest $request, int $schedule): RedirectResponse
    {
        $this->requirePermission('imports.run');
        $model = $this->findSchedule($schedule);
        $data = $request->validated();
        $before = $model->only(['responsible_user_id', 'interval_days', 'next_due_at']);

        $interval = (int) $data['interval_days'];
        $base = $model->last_import_at ?? CarbonImmutable::now();

        $model->forceFill([
            'responsible_user_id' => isset($data['responsible_user_id']) && $data['responsible_user_id'] !== '' ? (int) $data['responsible_user_id'] : null,
            'interval_days' => $interval,
            'next_due_at' => $base->addDays($interval),
        ])->save();

        $this->audit('imports.schedule_updated', $model, $before, $model->only(['responsible_user_id', 'interval_days', 'next_due_at']), (int) $model->connection_id);

        return $this->redirectWithStatus('admin.imports.schedules.index', 'Exportrhythmus aktualisiert.');
    }

    public function destroySchedule(Request $request, int $schedule): RedirectResponse
    {
        $this->requirePermission('imports.run');
        $this->requireConfirmation($request);
        $model = $this->findSchedule($schedule);
        $before = $model->only(['connection_id', 'export_type', 'responsible_user_id', 'interval_days']);

        // export_schedules sind Steuerdaten des Hubs, keine Spiegeldaten: Löschen ist zulässig.
        $model->delete();

        $this->audit('imports.schedule_deleted', $model, $before, [], (int) $before['connection_id']);

        return $this->redirectWithStatus('admin.imports.schedules.index', 'Exportrhythmus entfernt.');
    }

    /**
     * Explizite Suche statt Route-Model-Binding: der Mandantenkontext wird erst in admin.access gesetzt,
     * das Binding liefe davor und würde den Organization-Scope umgehen.
     */
    private function findFile(int $id): ImportFile
    {
        /** @var ImportFile $file */
        $file = ImportFile::query()->whereKey($id)->firstOrFail();

        return $file;
    }

    private function findSchedule(int $id): ExportSchedule
    {
        /** @var ExportSchedule $model */
        $model = ExportSchedule::query()->whereKey($id)->whereIn('connection_id', $this->connectionIds())->firstOrFail();

        return $model;
    }

    /**
     * @return Collection<int, int>
     */
    private function connectionIds(): Collection
    {
        return ImmowareConnection::query()->pluck('id')->map(static fn ($id): int => (int) $id);
    }
}
