<?php

declare(strict_types=1);

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Enums\ExportType;
use App\Modules\Imports\Enums\ImportFileStatus;
use App\Modules\Imports\Models\ExportSchedule;
use App\Modules\Imports\Models\ImportFile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Exportrhythmen je Verbindung und Exporttyp, Fälligkeiten, Erinnerungen und Datenalter (data_age).
 */
final class ExportScheduleService
{
    public function markImported(ImportFile $file): void
    {
        if ($file->connection_id === null || $file->export_type === null) {
            return;
        }

        $importedAt = $file->processed_at ?? CarbonImmutable::now();

        ExportSchedule::query()
            ->where('connection_id', $file->connection_id)
            ->where('export_type', $file->export_type)
            ->lazyById(100)
            ->each(static function (ExportSchedule $schedule) use ($importedAt): void {
                $schedule->forceFill([
                    'last_import_at' => $importedAt,
                    'next_due_at' => $importedAt->addDays((int) $schedule->interval_days),
                    'reminder_sent_at' => null,
                ])->save();
            });
    }

    /**
     * Überfällige Zeitpläne (next_due_at in der Vergangenheit oder nie importiert).
     *
     * @return Collection<int, ExportSchedule>
     */
    public function overdue(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $grace = (int) config('hub.imports.reminder_grace_days', 0);
        $threshold = $now->subDays($grace);

        $query = ExportSchedule::query()
            ->with('responsibleUser')
            ->where(static function ($q) use ($threshold): void {
                $q->whereNull('next_due_at')->orWhere('next_due_at', '<=', $threshold);
            });
        $query->orderBy('next_due_at')->limit(500);

        return $query->get();
    }

    public function markReminded(ExportSchedule $schedule, ?CarbonImmutable $now = null): void
    {
        $schedule->forceFill(['reminder_sent_at' => $now ?? CarbonImmutable::now()])->save();
    }

    /**
     * Datenalter je Exporttyp in Sekunden seit dem letzten erfolgreichen Import; null = noch nie importiert.
     */
    public function dataAgeSeconds(int $organizationId, ExportType $exportType, ?CarbonImmutable $now = null): ?int
    {
        $last = $this->lastImportedAt($organizationId, $exportType);

        if ($last === null) {
            return null;
        }

        return max(0, ($now ?? CarbonImmutable::now())->getTimestamp() - $last->getTimestamp());
    }

    public function lastImportedAt(int $organizationId, ExportType $exportType): ?CarbonImmutable
    {
        /** @var string|null $value */
        $value = ImportFile::query()->withoutGlobalScope('organization')
            ->where('organization_id', $organizationId)
            ->where('export_type', $exportType->value)
            ->where('status', ImportFileStatus::Imported->value)
            ->max('processed_at');

        return $value === null ? null : CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * Datenalter aller Exporttypen eines Mandanten.
     *
     * @return array<string, array{last_imported_at: string|null, age_seconds: int|null, age_days: int|null}>
     */
    public function dataAge(int $organizationId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $result = [];

        foreach (ExportType::cases() as $type) {
            $last = $this->lastImportedAt($organizationId, $type);
            $seconds = $last === null ? null : max(0, $now->getTimestamp() - $last->getTimestamp());
            $result[$type->value] = [
                'last_imported_at' => $last?->toIso8601String(),
                'age_seconds' => $seconds,
                'age_days' => $seconds === null ? null : intdiv($seconds, 86400),
            ];
        }

        return $result;
    }
}
