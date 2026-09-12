<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services;

use App\Core\Support\SecretMasker;
use App\Modules\Sync\Models\ExternalPayload;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Archiviert Rohnutzlasten je Entität: maskiert, komprimiert, mit Retention. Nutzlasten über dem
 * Inline-Limit liegen auf der konfigurierten Storage-Disk, kleinere inline in external_payloads.
 */
final class ExternalPayloadArchiver
{
    /** @var array<int, string> Nur für Importdateien gilt die Duplikatsperre über content_hash. */
    public const array FILE_PAYLOAD_TYPES = ['csv_file', 'datev_file', 'camt_file'];

    private const string ENCODING_GZIP = 'gzip';

    private const string ENCODING_PLAIN = 'plain';

    public function __construct(
        private readonly SecretMasker $masker,
        private readonly FilesystemFactory $filesystems,
    ) {}

    /**
     * @param  array<string, mixed>|string  $content
     * @param  array<string, mixed>  $metadata
     */
    public function archive(
        int $connectionId,
        string $payloadType,
        array|string $content,
        ?string $externalId = null,
        ?int $syncRunId = null,
        array $metadata = [],
        ?int $httpStatus = null,
        ?string $remoteEtag = null,
        ?CarbonImmutable $remoteLastModified = null,
        bool $containsPersonalData = false,
    ): ExternalPayload {
        $raw = $this->normalize($content);
        $contentHash = hash('sha256', $raw);
        $sizeBytes = strlen($raw);
        $compress = (bool) config('hub.sync.payloads.compress', true);
        $stored = $compress ? (string) gzencode($raw, 6) : $raw;
        $inlineLimit = (int) config('hub.sync.payloads.inline_limit_bytes', ExternalPayload::INLINE_LIMIT_BYTES);

        $metadata = $this->masker->maskArray($metadata);
        $metadata['_encoding'] = $compress ? self::ENCODING_GZIP : self::ENCODING_PLAIN;
        $metadata['_masked'] = true;

        $payload = new ExternalPayload;
        $payload->forceFill([
            'connection_id' => $connectionId,
            'sync_run_id' => $syncRunId,
            'payload_type' => $payloadType,
            'external_id_hash' => $externalId !== null ? hash('sha256', $externalId) : null,
            'content_hash' => $contentHash,
            'size_bytes' => $sizeBytes,
            'http_status' => $httpStatus,
            'remote_etag' => $remoteEtag,
            'remote_last_modified' => $remoteLastModified,
            'received_at' => CarbonImmutable::now(),
            'contains_personal_data' => $containsPersonalData,
            'dedup_hash' => in_array($payloadType, self::FILE_PAYLOAD_TYPES, true) ? $contentHash : hash('sha256', (string) Str::uuid()),
        ]);

        if (strlen($stored) > $inlineLimit) {
            $key = $this->storageKey($connectionId, $payloadType, $contentHash);
            $this->disk()->put($key, $stored);
            $payload->setAttribute('storage_key', $key);
            $payload->setAttribute('content_inline', null);
        } else {
            $payload->setAttribute('content_inline', $stored);
            $payload->setAttribute('storage_key', null);
        }

        $payload->setAttribute('import_metadata', $metadata);
        $payload->save();

        return $payload;
    }

    /**
     * Liefert die archivierte (maskierte) Nutzlast als String.
     */
    public function contents(ExternalPayload $payload): ?string
    {
        $stored = $payload->getAttribute('content_inline');

        if (! is_string($stored) || $stored === '') {
            $key = $payload->getAttribute('storage_key');

            if (! is_string($key) || ! $this->disk()->exists($key)) {
                return null;
            }

            $stored = (string) $this->disk()->get($key);
        }

        $metadata = (array) ($payload->getAttribute('import_metadata') ?? []);

        if (($metadata['_encoding'] ?? self::ENCODING_PLAIN) === self::ENCODING_GZIP) {
            $decoded = @gzdecode($stored);

            return $decoded === false ? null : $decoded;
        }

        return $stored;
    }

    /**
     * Entfernt Nutzlasten, die älter als die Retention sind. Nutzlasten mit personenbezogenen Daten,
     * die noch nicht pseudonymisiert wurden, bleiben bis zur Pseudonymisierung erhalten.
     *
     * @return int Anzahl entfernter Nutzlasten
     */
    public function prune(?int $retentionDays = null, bool $dryRun = false): int
    {
        $retentionDays ??= (int) config('hub.sync.payloads.retention_days', 90);
        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $count = 0;

        ExternalPayload::query()
            ->where('received_at', '<', $cutoff)
            ->where(function ($query): void {
                $query->where('contains_personal_data', false)->orWhereNotNull('pseudonymized_at');
            })
            ->lazyById(200)
            ->each(function (ExternalPayload $payload) use (&$count, $dryRun): void {
                $count++;

                if ($dryRun) {
                    return;
                }

                $key = $payload->getAttribute('storage_key');

                if (is_string($key) && $this->disk()->exists($key)) {
                    $this->disk()->delete($key);
                }

                $payload->delete();
            });

        return $count;
    }

    /**
     * @param  array<string, mixed>|string  $content
     */
    private function normalize(array|string $content): string
    {
        if (is_array($content)) {
            return json_encode($this->masker->maskArray($content), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $this->masker->maskString($content);
    }

    private function storageKey(int $connectionId, string $payloadType, string $contentHash): string
    {
        $prefix = trim((string) config('hub.sync.payloads.path_prefix', 'sync/payloads'), '/');

        return sprintf('%s/%d/%s/%s/%s.bin', $prefix, $connectionId, $payloadType, substr($contentHash, 0, 2), $contentHash.'-'.Str::lower((string) Str::ulid()));
    }

    private function disk(): Filesystem
    {
        return $this->filesystems->disk((string) config('hub.sync.payloads.disk', 'local'));
    }
}
