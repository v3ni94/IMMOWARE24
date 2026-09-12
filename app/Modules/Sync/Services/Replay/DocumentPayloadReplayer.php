<?php

declare(strict_types=1);

namespace App\Modules\Sync\Services\Replay;

use App\Core\Enums\SyncMode;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\DTO\ScanContext;
use App\Modules\Documents\Http\PropfindResult;
use App\Modules\Documents\Http\WebDavClientFactory;
use App\Modules\Documents\Services\DocumentMirrorService;
use App\Modules\Documents\Support\MultistatusParser;
use App\Modules\Documents\Support\WebDavPath;
use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Models\ExternalPayload;
use App\Modules\Sync\Models\SyncRun;
use App\Modules\Sync\Services\SyncRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Replay archivierter PROPFIND-Antworten (payload_type propfind_xml) über MultistatusParser und
 * DocumentMirrorService (upsertFolder, upsertDocument). Kein Sweep, kein Soft Delete, kein Inhalts-Hash:
 * der Replay stellt Metadatenzeilen wieder her und sendet keinen Request an Immoware24.
 * import_metadata: path (Ordnerpfad der Anfrage), optional organization_id, base_url.
 * sync_events des Replays hängen an einem Lauf vom Typ replay je Connection.
 */
final class DocumentPayloadReplayer implements PayloadReplayerInterface
{
    public const string PAYLOAD_TYPE = 'propfind_xml';

    /** @var array<int, SyncRun> */
    private array $runs = [];

    /** @var array<int, ImmowareConnection> */
    private array $connections = [];

    public function __construct(
        private readonly MultistatusParser $parser,
        private readonly DocumentMirrorService $mirror,
        private readonly WebDavClientFactory $clients,
        private readonly SyncRunService $runService,
    ) {}

    public function entityType(): string
    {
        return SyncEntity::Document->value;
    }

    public function payloadType(): string
    {
        return self::PAYLOAD_TYPE;
    }

    public function replay(ExternalPayload $payload, string $content): ReplayOutcome
    {
        $metadata = (array) ($payload->getAttribute('import_metadata') ?? []);
        $connection = $this->connection((int) $payload->getAttribute('connection_id'));
        $baseUrl = (string) ($metadata['base_url'] ?? $connection->getAttribute('base_url') ?? '');
        $entries = $this->parser->parse($content, $baseUrl !== '' ? $baseUrl : null);

        if ($entries === []) {
            throw new RuntimeException('Nutzlast enthält keine Multistatus-Antwort.');
        }

        $folderPath = (string) ($metadata['path'] ?? '');

        if ($folderPath === '') {
            // Ohne Metadaten gilt der erste Collection-Eintrag als angefragter Ordner.
            foreach ($entries as $entry) {
                if ($entry->isCollection) {
                    $folderPath = $entry->path;
                    break;
                }
            }
        }

        if ($folderPath === '') {
            throw new RuntimeException('Nutzlast ohne Ordnerpfad (import_metadata.path), Replay nicht möglich.');
        }

        $folderPath = WebDavPath::normalize($folderPath, true);
        $result = new PropfindResult(207, $folderPath, $entries);
        $context = $this->context($connection, PayloadMetadata::organizationId($payload, $metadata));
        $receivedAt = $payload->getAttribute('received_at');
        $scannedAt = $receivedAt instanceof CarbonImmutable ? $receivedAt : CarbonImmutable::now();
        $client = $this->clients->forConnection($connection);

        $folder = $this->mirror->upsertFolder($folderPath, $context, $result->self(), $scannedAt);
        $outcome = new ReplayOutcome(externalIds: [$folderPath]);

        foreach ($result->children() as $entry) {
            try {
                if ($entry->isCollection) {
                    $this->mirror->upsertFolder($entry->path, $context, $entry, null, $folder);
                    $outcome = $outcome->merge(new ReplayOutcome(unchanged: 1, externalIds: [WebDavPath::normalize($entry->path, true)]));

                    continue;
                }

                $state = $this->mirror->upsertDocument($client, $entry, $folder, $context, $scannedAt);
                $outcome = $outcome->merge(new ReplayOutcome(
                    created: $state === 'created' ? 1 : 0,
                    updated: in_array($state, ['updated', 'restored', 'moved'], true) ? 1 : 0,
                    unchanged: in_array($state, ['unchanged', 'unchanged_meta_noise'], true) ? 1 : 0,
                    externalIds: [WebDavPath::normalize($entry->path, false)],
                ));
            } catch (Throwable $e) {
                Log::warning('Replay: Dokumenteintrag konnte nicht wiederhergestellt werden.', [
                    'connection_id' => $context->connectionId,
                    'payload_id' => (int) $payload->getKey(),
                    'path' => $entry->path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $outcome;
    }

    public function finish(): void
    {
        foreach ($this->runs as $run) {
            $this->runService->finish($run);
        }

        $this->runs = [];
        $this->connections = [];
    }

    private function connection(int $connectionId): ImmowareConnection
    {
        if (! isset($this->connections[$connectionId])) {
            /** @var ImmowareConnection|null $connection */
            $connection = ImmowareConnection::query()->find($connectionId);

            if ($connection === null) {
                throw new RuntimeException(sprintf('Connection %d der Nutzlast existiert nicht, Replay nicht möglich.', $connectionId));
            }

            $this->connections[$connectionId] = $connection;
        }

        return $this->connections[$connectionId];
    }

    private function context(ImmowareConnection $connection, int $organizationId): ScanContext
    {
        $connectionId = (int) $connection->getKey();

        if (! isset($this->runs[$connectionId])) {
            $this->runs[$connectionId] = $this->runService->start(
                $connectionId,
                SyncEntity::Document->value,
                SyncMode::Full,
                'replay',
                null,
                null,
                SyncRun::TYPE_REPLAY,
            );
        }

        return new ScanContext(
            connectionId: $connectionId,
            organizationId: $organizationId,
            syncRunId: (int) $this->runs[$connectionId]->getKey(),
            etagStable: false,
            contentHashEnabled: false,
            healthOk: false,
        );
    }
}
