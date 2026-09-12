<?php

declare(strict_types=1);

namespace App\Modules\Connector\Services;

use App\Core\Support\CorrelationId;
use App\Core\Support\SecretMasker;
use App\Modules\Connector\Enums\RemoteRequestOutcome;
use App\Modules\Connector\Models\RemoteRequest;
use App\Modules\Connector\Support\ResponseSchemaFingerprint;
use App\Modules\Connector\Support\UrlSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Protokolliert jeden Request an Immoware24 in remote_requests. Secrets werden vor dem Schreiben
 * über SecretMasker maskiert; Request-Bodies werden nie gespeichert.
 */
final class RemoteRequestLogger
{
    /**
     * @param  array<string, mixed>  $config  Inhalt von config('hub.connector.remote_requests')
     */
    public function __construct(
        private readonly SecretMasker $masker,
        private readonly UrlSanitizer $urls,
        private readonly ResponseSchemaFingerprint $fingerprint,
        private readonly CorrelationId $correlationId,
        private readonly array $config,
    ) {}

    /**
     * @param  array<string, array<int, string>|string>  $requestHeaders
     * @param  array<string, array<int, string>|string>  $responseHeaders
     */
    public function record(
        ?int $connectionId,
        ?string $connectorName,
        string $method,
        string $url,
        ?int $status,
        int $durationMs,
        array $requestHeaders = [],
        array $responseHeaders = [],
        ?int $requestBytes = null,
        ?int $responseBytes = null,
        ?string $responseBody = null,
        ?Throwable $error = null,
        ?RemoteRequestOutcome $outcome = null,
    ): ?RemoteRequest {
        if (! (bool) ($this->config['enabled'] ?? true)) {
            return null;
        }

        $path = $this->urls->sanitize($url);
        $contentType = $this->headerValue($responseHeaders, 'Content-Type');

        $outcome ??= $this->determineOutcome($status, $error);

        try {
            $record = RemoteRequest::query()->create([
                'connection_id' => $connectionId,
                'connector_name' => $connectorName !== null ? substr($connectorName, 0, 32) : null,
                'correlation_id' => $this->correlationId->current(),
                'method' => strtoupper(substr($method, 0, 16)),
                'path' => substr($path, 0, 2048),
                'path_hash' => hash('sha256', $path),
                'request_headers_masked' => $this->masker->maskHeaders($this->normalizeHeaders($requestHeaders)),
                'response_status' => $status,
                'response_headers' => $this->masker->maskHeaders($this->normalizeHeaders($responseHeaders)),
                'request_bytes' => $requestBytes,
                'response_bytes' => $responseBytes,
                'response_schema_fingerprint' => $this->fingerprint->compute($responseBody, $contentType),
                'duration_ms' => max(0, $durationMs),
                'outcome' => $outcome->value,
                'error_class' => $error !== null ? substr($error::class, 0, 200) : null,
                'error_message_masked' => $error !== null ? substr($this->masker->maskString($error->getMessage()), 0, 2000) : null,
                'requested_at' => CarbonImmutable::now(),
            ]);
        } catch (Throwable $e) {
            // Das Protokoll darf den fachlichen Request nie zum Scheitern bringen.
            Log::error('remote_requests konnte nicht geschrieben werden.', ['error' => $this->masker->maskString($e->getMessage())]);

            return null;
        }

        Log::debug('Remote-Request protokolliert.', [
            'remote_request_id' => $record->getKey(),
            'connection_id' => $connectionId,
            'method' => $method,
            'status' => $status,
            'duration_ms' => $durationMs,
            'outcome' => $outcome->value,
        ]);

        return $record;
    }

    /**
     * Löscht Einträge, die älter als die konfigurierte Aufbewahrung sind. remote_requests sind technische
     * Protokolle und keine Spiegeldaten, deshalb ist Hard Delete zulässig. Löschung in Blöcken.
     *
     * @return int Anzahl gelöschter Zeilen
     */
    public function prune(?int $retentionDays = null): int
    {
        $days = $retentionDays ?? (int) ($this->config['retention_days'] ?? 90);
        $chunk = max(100, (int) ($this->config['prune_chunk'] ?? 1000));
        $cutoff = CarbonImmutable::now()->subDays($days);
        $total = 0;

        do {
            $ids = RemoteRequest::query()
                ->where('requested_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $total += RemoteRequest::query()->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return $total;
    }

    private function determineOutcome(?int $status, ?Throwable $error): RemoteRequestOutcome
    {
        if ($error !== null) {
            $message = strtolower($error->getMessage());

            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                return RemoteRequestOutcome::Timeout;
            }

            return $status === null ? RemoteRequestOutcome::ConnectionFailed : RemoteRequestOutcome::fromStatus($status);
        }

        return $status === null ? RemoteRequestOutcome::Unknown : RemoteRequestOutcome::fromStatus($status);
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, array<int, string>|string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $result = [];

        foreach ($headers as $name => $value) {
            $result[(string) $name] = is_array($value) ? array_map(fn ($v): string => $this->masker->maskString((string) $v), $value) : $this->masker->maskString((string) $value);
        }

        return $result;
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return is_array($value) ? (string) ($value[0] ?? '') : (string) $value;
            }
        }

        return null;
    }
}
