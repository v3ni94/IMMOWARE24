<?php

declare(strict_types=1);

namespace App\Modules\Documents\Connectors;

use App\Core\Contracts\ImmowareConnectorInterface;
use App\Core\DTO\CheckResult;
use App\Core\DTO\ConnectionResult;
use App\Core\DTO\SyncRequest;
use App\Core\DTO\SyncResult;
use App\Core\Enums\SyncMode;
use App\Core\Enums\SyncStatus;
use App\Core\Exceptions\WriteBlockedException;
use App\Modules\Connector\Enums\CapabilityKey;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Connector\Support\ConnectorContext;
use App\Modules\Documents\DTO\ScanContext;
use App\Modules\Documents\Http\WebDavClient;
use App\Modules\Documents\Services\DocumentMirrorService;
use App\Modules\Documents\Support\WebDavPath;
use App\Modules\Sync\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * WebDAV-Adapter für den Dokumentenspiegel. pull() traversiert die konfigurierten Wurzelordner
 * breitensuchend mit Chunking: je Aufruf höchstens folders_per_run Ordner, der Cursor ist die
 * verbleibende Ordner-Queue (JSON). push() ist gesperrt; der einzige Schreibpfad ist der
 * PosteingangUploadService.
 */
final class WebDavConnector implements ImmowareConnectorInterface
{
    public const string NAME = 'webdav';

    public const string ENTITY_TYPE = 'document';

    public const string RUN_TYPE = 'documents_scan';

    public function __construct(
        private readonly ConnectorContext $context,
        private readonly WebDavClient $client,
        private readonly DocumentMirrorService $mirror,
        private readonly ConfigRepository $config,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function client(): WebDavClient
    {
        return $this->client;
    }

    public function authenticate(): bool
    {
        try {
            $result = $this->client->propfind('/', 0);
        } catch (Throwable) {
            return false;
        }

        return $result->isMultistatus() || ($result->status >= 200 && $result->status < 300);
    }

    public function testConnection(): ConnectionResult
    {
        $checks = [];

        try {
            $start = hrtime(true);
            $options = $this->client->options('/');
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            $checks['options'] = $options->status >= 200 && $options->status < 300
                ? CheckResult::ok(sprintf('DAV: %s', $options->davClasses !== [] ? implode(', ', $options->davClasses) : 'ohne DAV-Header'), $latency)
                : CheckResult::failed(sprintf('OPTIONS antwortet %d', $options->status), $latency);
        } catch (Throwable $e) {
            $checks['options'] = CheckResult::failed($e::class);
        }

        try {
            $start = hrtime(true);
            $propfind = $this->client->propfind('/', 0);
            $latency = (int) ((hrtime(true) - $start) / 1_000_000);

            $checks['propfind_root'] = $propfind->isMultistatus()
                ? CheckResult::ok('207 Multistatus', $latency)
                : CheckResult::failed(sprintf('PROPFIND Depth 0 antwortet %d', $propfind->status), $latency);
        } catch (Throwable $e) {
            $checks['propfind_root'] = CheckResult::failed($e::class);
        }

        return ConnectionResult::fromChecks($checks);
    }

    /**
     * @return array<int, string>
     */
    public function capabilities(): array
    {
        return [CapabilityKey::DocumentsRead->value, CapabilityKey::DocumentsWrite->value];
    }

    public function pull(SyncRequest $request): SyncResult
    {
        if (! (bool) $this->config->get('hub.core.read.enabled', true)) {
            return new SyncResult(errors: [['reason' => 'read_disabled']]);
        }

        $cursor = $this->decodeCursor($request->cursor);
        $queue = $cursor['queue'];
        $visited = $cursor['visited'];
        $runId = $cursor['run_id'] ?? null;
        $ownRun = false;

        if ($runId === null && $request->runId !== null) {
            // Lauf des Orchestrators (RunSyncJob, Bootstrap, Replay): nur befüllen, nie schließen.
            $runId = $request->runId;
            $ownRun = false;
        } elseif ($runId === null) {
            $runId = $this->resolveRunId($request->mode);
            $ownRun = true;
        } else {
            $ownRun = (bool) ($cursor['own_run'] ?? false);
        }

        $scanContext = $this->scanContext($runId);
        $foldersPerRun = max(1, (int) $this->config->get('hub.documents.scan.folders_per_run', 25));
        $maxDepth = max(1, (int) $this->config->get('hub.documents.scan.max_depth', 12));

        $processed = $created = $updated = $deleted = $failed = 0;
        $errors = [];
        $scanned = 0;

        while ($queue !== [] && $scanned < $foldersPerRun) {
            $folder = WebDavPath::normalize((string) array_shift($queue), true);

            if (isset($visited[$folder])) {
                continue;
            }

            $visited[$folder] = 1;
            $scanned++;

            try {
                $result = $this->mirror->scanFolder($this->client, $folder, $scanContext);
            } catch (Throwable $e) {
                $failed++;
                $errors[] = ['path' => $folder, 'reason' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 500)];
                Log::warning('Ordner-Scan fehlgeschlagen.', ['connection_id' => $this->context->connectionId, 'path' => $folder, 'error' => $e::class]);

                continue;
            }

            $processed += $result->processed;
            $created += $result->created;
            $updated += $result->updated + $result->moved;
            $deleted += $result->deleted;
            $failed += $result->failed;
            $errors = [...$errors, ...$result->errors];

            foreach ($result->childFolders as $child) {
                if (WebDavPath::depth($child) <= $maxDepth && ! isset($visited[$child])) {
                    $queue[] = $child;
                }
            }
        }

        $nextCursor = $queue === [] ? null : $this->encodeCursor($queue, $visited, $runId, $ownRun);

        if ($nextCursor === null && $ownRun) {
            $this->finishRun($runId, $failed === 0 ? SyncStatus::Succeeded : SyncStatus::Failed, [
                'processed' => $processed, 'created' => $created, 'updated' => $updated, 'deleted' => $deleted, 'failed' => $failed,
                'folders_visited' => count($visited), 'full_enumeration' => 1,
            ]);
        }

        return new SyncResult(
            processed: $processed,
            created: $created,
            updated: $updated,
            deleted: $deleted,
            failed: $failed,
            cursor: $nextCursor,
            errors: $errors,
        );
    }

    /**
     * Uploads laufen ausschließlich über den PosteingangUploadService (write_operations, Idempotenz, Audit).
     */
    public function push(SyncRequest $request): SyncResult
    {
        throw new WriteBlockedException('Der WebDAV-Adapter schreibt nicht direkt. Einziger Schreibpfad ist der PosteingangUploadService (create-only PUT in den Posteingang).', 'PUT');
    }

    /**
     * @return array<int, string>
     */
    public function roots(): array
    {
        $roots = (array) $this->config->get('hub.documents.scan.roots', ['/Posteingang/', '/Dokumente/']);
        $normalized = [];

        foreach ($roots as $root) {
            if (is_string($root) && trim($root) !== '') {
                $normalized[] = WebDavPath::normalize($root, true);
            }
        }

        return $normalized === [] ? ['/Posteingang/', '/Dokumente/'] : array_values(array_unique($normalized));
    }

    private function scanContext(int $runId): ScanContext
    {
        $etagStable = (bool) $this->config->get('hub.documents.scan.etag_stable_default', false);

        $connection = ImmowareConnection::query()->withoutGlobalScopes()->find($this->context->connectionId);
        $probe = $connection?->getAttribute('probe_result');

        if (is_array($probe) && array_key_exists('etag_stable', $probe)) {
            $etagStable = (bool) $probe['etag_stable'];
        }

        return new ScanContext(
            connectionId: $this->context->connectionId,
            organizationId: $this->context->organizationId,
            syncRunId: $runId,
            etagStable: $etagStable,
            contentHashEnabled: (bool) $this->config->get('hub.documents.content_hash.enabled', false),
            contentHashMaxBytes: (int) $this->config->get('hub.documents.content_hash.max_bytes', 10485760),
            maxEntriesPerFolder: (int) $this->config->get('hub.documents.scan.max_entries_per_folder', 5000),
            sweepMaxMissingRatio: (float) $this->config->get('hub.documents.sweep.max_missing_ratio', 0.2),
            sweepMinCountForRatio: (int) $this->config->get('hub.documents.sweep.min_count_for_ratio', 10),
            sweepMaxMissingCount: (int) $this->config->get('hub.documents.sweep.max_missing_count', 500),
        );
    }

    /**
     * Ohne runId im SyncRequest (eigenständiger Scan über ScanDocumentFoldersJob): einen noch laufenden
     * eigenen Scan-Run (run_type documents_scan) fortsetzen oder einen eigenen anlegen. Läufe anderer
     * Orchestratoren oder Entitäten werden nie übernommen und daher auch nie von hier geschlossen.
     */
    private function resolveRunId(SyncMode $mode): int
    {
        $running = SyncRun::query()
            ->where('connection_id', $this->context->connectionId)
            ->where('run_type', self::RUN_TYPE)
            ->where('trigger_source', 'connector')
            ->where('status', SyncStatus::Running->value)
            ->orderByDesc('id')
            ->value('id');

        if ($running !== null) {
            return (int) $running;
        }

        $run = SyncRun::query()->create([
            'connection_id' => $this->context->connectionId,
            'entity_type' => self::ENTITY_TYPE,
            'run_type' => self::RUN_TYPE,
            'mode' => $mode->value,
            'trigger_source' => 'connector',
            'phase' => 'process',
            'status' => SyncStatus::Running->value,
            'started_at' => CarbonImmutable::now(),
            'counters' => [],
        ]);

        return (int) $run->getKey();
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function finishRun(int $runId, SyncStatus $status, array $counters): void
    {
        SyncRun::query()->whereKey($runId)->update([
            'status' => $status->value,
            'phase' => 'done',
            'finished_at' => CarbonImmutable::now(),
            'counters' => json_encode($counters, JSON_THROW_ON_ERROR),
        ]);
    }

    /**
     * @return array{queue: array<int, string>, visited: array<string, int>, run_id: int|null, own_run: bool}
     */
    private function decodeCursor(?string $cursor): array
    {
        $default = ['queue' => $this->roots(), 'visited' => [], 'run_id' => null, 'own_run' => false];

        if ($cursor === null || trim($cursor) === '') {
            return $default;
        }

        try {
            $decoded = json_decode($cursor, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return $default;
        }

        if (! is_array($decoded) || ! isset($decoded['queue']) || ! is_array($decoded['queue'])) {
            return $default;
        }

        $queue = array_values(array_filter($decoded['queue'], 'is_string'));
        $visited = [];

        foreach ((array) ($decoded['visited'] ?? []) as $path) {
            if (is_string($path)) {
                $visited[$path] = 1;
            }
        }

        return [
            'queue' => $queue,
            'visited' => $visited,
            'run_id' => isset($decoded['run_id']) && is_numeric($decoded['run_id']) ? (int) $decoded['run_id'] : null,
            'own_run' => (bool) ($decoded['own_run'] ?? false),
        ];
    }

    /**
     * @param  array<int, string>  $queue
     * @param  array<string, int>  $visited
     */
    private function encodeCursor(array $queue, array $visited, int $runId, bool $ownRun): string
    {
        return json_encode([
            'queue' => array_values($queue),
            'visited' => array_keys($visited),
            'run_id' => $runId,
            'own_run' => $ownRun,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
