<?php

declare(strict_types=1);

namespace App\Modules\Imports\Jobs;

use App\Modules\Imports\Enums\HubExportFormat;
use App\Modules\Imports\Enums\HubExportStatus;
use App\Modules\Imports\Models\HubExport;
use App\Modules\Imports\Services\HubExportService;
use App\Modules\Imports\Services\ImportStorage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Schreibt einen Hub-Export chunked (lazyById) als CSV oder JSON auf die Export-Disk.
 * Kein Model::all(), kein vollständiges Laden in den Speicher.
 */
final class ExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 4;

    public function __construct(public readonly int $exportId)
    {
        $this->onQueue((string) config('hub.core.queues.low', 'low'));
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        /** @var array<int, int> $backoff */
        $backoff = (array) config('hub.core.job_backoff', [30, 120, 600, 1800]);

        return $backoff;
    }

    public function handle(HubExportService $service, ImportStorage $storage): void
    {
        /** @var HubExport|null $export */
        $export = HubExport::query()->withoutGlobalScope('organization')->find($this->exportId);

        if ($export === null || $export->status === HubExportStatus::Completed) {
            return;
        }

        $export->forceFill(['status' => HubExportStatus::Running->value, 'started_at' => CarbonImmutable::now()])->save();

        $format = $export->format instanceof HubExportFormat ? $export->format : HubExportFormat::from((string) $export->format);
        $prefix = trim((string) config('hub.imports.exports.prefix', 'exports'), '/');
        $key = sprintf('%s/%d/%s_%d.%s', $prefix, (int) $export->organization_id, $export->entity, (int) $export->getKey(), $format->value);
        $chunk = max(50, (int) config('hub.imports.exports.chunk_size', 500));
        $columns = $service->columns($export);

        $temp = tempnam(sys_get_temp_dir(), 'hubexport');

        if ($temp === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }

        $handle = fopen($temp, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht geöffnet werden.');
        }

        $rows = 0;

        try {
            if ($format === HubExportFormat::Csv) {
                fwrite($handle, "\xEF\xBB\xBF");
                fputcsv($handle, $columns, ';', '"', '\\', "\r\n");
            } else {
                fwrite($handle, '[');
            }

            $query = $service->query($export);
            $query->select($columns);

            $query->lazyById($chunk)->each(function (Model $model) use ($handle, $columns, $format, &$rows): void {
                $data = [];
                foreach ($columns as $column) {
                    $value = $model->getAttribute($column);
                    $data[$column] = $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : (is_bool($value) ? ($value ? 1 : 0) : $value);
                }

                if ($format === HubExportFormat::Csv) {
                    fputcsv($handle, array_map(self::csvCell(...), $data), ';', '"', '\\', "\r\n");
                } else {
                    fwrite($handle, ($rows > 0 ? ',' : '').json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                }

                $rows++;
            });

            if ($format === HubExportFormat::Json) {
                fwrite($handle, ']');
            }

            fclose($handle);

            $disk = $storage->exportDisk();
            $stream = fopen($temp, 'r');

            if ($stream === false) {
                throw new \RuntimeException('Exportdatei konnte nicht gelesen werden.');
            }

            $disk->writeStream($key, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $export->forceFill([
                'status' => HubExportStatus::Completed->value,
                'storage_disk' => (string) config('hub.imports.exports.disk', 'local'),
                'storage_key' => $key,
                'row_count' => $rows,
                'size_bytes' => (int) filesize($temp),
                'content_hash' => (string) hash_file('sha256', $temp),
                'finished_at' => CarbonImmutable::now(),
            ])->save();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    /**
     * CSV-Zelle für Tabellenkalkulationen entschärfen: Strings, die mit =, +, -, @, Tab oder CR beginnen,
     * würden in Excel als Formel ausgewertet (CSV-Injection über Verwendungszwecke, Namen, Notizen).
     * Numerische Werte (z. B. negative amount_cents) bleiben unverändert.
     */
    public static function csvCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $string = (string) $value;

        if ($string !== '' && (str_starts_with($string, '=') || str_starts_with($string, '+') || str_starts_with($string, '-') || str_starts_with($string, '@') || str_starts_with($string, "\t") || str_starts_with($string, "\r"))) {
            // Numerische Strings (z. B. "-12,50") nicht verändern, nur Text.
            if (! is_numeric(str_replace(',', '.', $string))) {
                return "'".$string;
            }
        }

        return $string;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Hub-Export fehlgeschlagen.', ['hub_export_id' => $this->exportId, 'error' => $exception?->getMessage()]);

        HubExport::query()->withoutGlobalScope('organization')->whereKey($this->exportId)->update([
            'status' => HubExportStatus::Failed->value,
            'error_summary' => mb_substr((string) $exception?->getMessage(), 0, 2000),
            'finished_at' => CarbonImmutable::now(),
        ]);
    }
}
