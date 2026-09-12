<?php

declare(strict_types=1);

namespace App\Modules\Documents\Services;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Contracts\CapabilityRegistryInterface;
use App\Core\Enums\AuditSource;
use App\Core\Enums\WriteOperationStatus;
use App\Core\Exceptions\WriteBlockedException;
use App\Core\Support\CorrelationId;
use App\Core\Support\SecretMasker;
use App\Modules\Connector\Enums\CapabilityKey;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Documents\DTO\UploadRequest;
use App\Modules\Documents\DTO\UploadResult;
use App\Modules\Documents\Http\WebDavClient;
use App\Modules\Documents\Http\WebDavClientFactory;
use App\Modules\Documents\Jobs\ResolveUnknownWriteOperationJob;
use App\Modules\Documents\Models\Document;
use App\Modules\Documents\Support\DavEntry;
use App\Modules\Documents\Support\FilenameSanitizer;
use App\Modules\Documents\Support\WebDavPath;
use App\Modules\Security\Models\User;
use App\Modules\Sync\Models\WriteOperation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Einziger Schreibpfad Richtung Immoware24: create-only PUT einer neuen Datei in den WebDAV-Posteingang
 * gemäß docs/immoware/05-write-capabilities.md. Ablauf: Capability und Flags prüfen, Zielpfad gegen
 * allowed_write_prefix, Dateiname sanitizen, write_operations mit operation_uuid und idempotency_key,
 * PROPFIND-Precheck, PUT mit If-None-Match: *, Verifikation per PROPFIND und optional GET plus SHA-256.
 * Status unknown wird ausschließlich über PROPFIND aufgelöst. Ein Retry erzeugt nie ein zweites PUT.
 *
 * Änderungsvermerk 12.09.2026: Anträge mit requested_via aus einem API-Key-Kontext (api_key, mcp, n8n) bleiben
 * pending, bis ein Mensch (Rolle operator oder höher) sie über approve() freigibt (09-api-documentation.md 3.5,
 * 08-security.md Abschnitt 8). Der Inhalt eines pending-Antrags liegt im Blob-Speicher (source_storage_key).
 * Nach Status unknown wird ResolveUnknownWriteOperationJob eingeplant; resume() führt sent, unknown und
 * verifying ausschließlich per PROPFIND weiter (05 3.4).
 */
final class PosteingangUploadService
{
    public const string CONFLICT_TARGET_EXISTS = 'write_target_exists';

    public const string CONFLICT_VERIFY_FAILED = 'write_verify_failed';

    public const string CONFLICT_UNKNOWN_UNRESOLVED = 'write_unknown_unresolved';

    public function __construct(
        private readonly WebDavClientFactory $clients,
        private readonly CapabilityRegistryInterface $capabilities,
        private readonly AuditLoggerInterface $audit,
        private readonly ConfigRepository $config,
        private readonly CorrelationId $correlation,
        private readonly SecretMasker $masker,
        private readonly UploadContentStore $contents,
        private readonly Dispatcher $bus,
    ) {}

    /**
     * Nimmt einen Upload-Antrag an und führt ihn, sofern freigegeben, unmittelbar aus.
     */
    public function upload(UploadRequest $request): UploadResult
    {
        /** @var ImmowareConnection $connection */
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->findOrFail($request->connectionId);

        $contentHash = $request->contentHash();
        $intentKey = $this->intentKey($request);
        $idempotencyKey = WriteOperation::idempotencyKey($request->connectionId, $contentHash, $intentKey);

        /** @var WriteOperation|null $existing */
        $existing = WriteOperation::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing !== null) {
            // Zweiter Aufruf mit gleichem Schlüssel: bestehender Datensatz ohne Netzwerkzugriff, nie ein zweites PUT.
            return new UploadResult($existing, UploadResult::OUTCOME_IDEMPOTENT_REPLAY);
        }

        $prefix = $this->allowedPrefix($connection);
        $sanitizer = $this->sanitizer();
        $sanitized = $sanitizer->sanitize($request->originalFilename, $this->suffix());
        $targetPath = WebDavPath::normalize(rtrim($prefix, '/').'/'.$sanitized, false);

        $operation = new WriteOperation;
        $operation->fill([
            'connection_id' => $request->connectionId,
            'idempotency_key' => $idempotencyKey,
            'intent_key' => $intentKey,
            'payload_hash' => $contentHash,
            'operation' => WriteOperation::OPERATION_WEBDAV_CREATE,
            'target_path' => $targetPath,
            'target_path_hash' => Document::hashPath($targetPath),
            'original_filename' => mb_substr($request->originalFilename, 0, 512),
            'sanitized_filename' => $sanitized,
            'content_hash' => $contentHash,
            'size_bytes' => $request->sizeBytes(),
            'source_storage_key' => $request->sourceStorageKey,
            'source_document_id' => $request->sourceDocumentId,
            'case_id' => $request->caseId,
            'status' => WriteOperationStatus::Pending,
            'precheck_attempts' => 0,
            'verify_attempts' => 0,
            'put_attempts' => 0,
            'requested_by' => $request->requestedBy,
            'requested_via' => $request->requestedVia,
            'correlation_id' => $this->correlation->current(),
        ]);
        $operation->save();

        $this->audit('write.queued', $operation, [], $this->snapshot($operation), $request->requestedVia);

        // Harte Ablehnungen (Pfad, Größe): rejected.
        $rejection = $this->rejectionReason($request, $connection, $targetPath, $prefix);

        if ($rejection !== null) {
            $this->transition($operation, WriteOperationStatus::Rejected, ['precheck_result' => ['rejected_reason' => $rejection], 'failed_at' => CarbonImmutable::now(), 'last_error' => $rejection], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_REJECTED);
        }

        // Freigaben (Flags, Capability, Connection, Vier-Augen): Antrag bleibt pending, Inhalt im Blob-Speicher, kein Verlust.
        $denial = $this->denialReason($connection);

        if ($denial !== null) {
            $this->keepPending($operation, $request, ['denied_reason' => $denial]);
            $this->audit($denial === 'write_disabled_global' ? 'write.denied_global' : 'write.denied', $operation, [], ['denied_reason' => $denial], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_DENIED);
        }

        // Anträge aus einem API-Key-Kontext führt erst ein Mensch aus (approve), nie der Request selbst.
        if ($this->requiresHumanApproval($request->requestedVia)) {
            $this->keepPending($operation, $request, ['approval_required' => true, 'requested_via' => $request->requestedVia]);
            $this->audit('write.approval_required', $operation, [], ['requested_via' => $request->requestedVia], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_PENDING_APPROVAL);
        }

        return $this->execute($operation, $connection, $request);
    }

    /**
     * Menschliche Freigabe eines pending-Antrags (Rolle operator oder höher). Der Inhalt kommt aus dem Blob-Speicher,
     * die Flag- und Connection-Guards werden erneut geprüft. Ein Antrag in sent, unknown oder verified löst nie ein PUT aus.
     */
    public function approve(WriteOperation $operation, User $approver, ?string $content = null, bool $dryRun = false): UploadResult
    {
        if (! $approver->role->canLogin() || $approver->role === \App\Core\Enums\Role::ReadOnly) {
            throw new WriteBlockedException('Freigabe eines Uploads erfordert die Rolle operator oder höher.', 'PUT');
        }

        if ($this->status($operation) !== WriteOperationStatus::Pending) {
            return new UploadResult($operation, UploadResult::OUTCOME_IDEMPOTENT_REPLAY);
        }

        $content ??= $this->contents->retrieve($operation);

        if ($content === null) {
            throw new WriteBlockedException('Für diesen Antrag liegt kein Inhalt im Blob-Speicher vor, Freigabe nicht möglich.', 'PUT');
        }

        $precheck = (array) ($operation->getAttribute('precheck_result') ?? []);
        $operation->setAttribute('precheck_result', [...$precheck, 'approved_by' => (int) $approver->getKey(), 'approved_at' => CarbonImmutable::now()->toIso8601String()]);
        $operation->save();
        $this->audit('write.approved', $operation, [], ['approved_by' => (int) $approver->getKey()], 'ui');

        $result = $this->process($operation, $content, $dryRun);

        if ($result->status()->mayHaveReachedRemote() || in_array($result->status(), [WriteOperationStatus::Failed, WriteOperationStatus::Rejected], true)) {
            $this->contents->forget($operation);
        }

        return $result;
    }

    /**
     * Neustartverhalten (05 3.4): Anträge in sent, unknown und verifying werden ausschließlich per PROPFIND
     * weitergeführt. Ein erneutes PUT ist ausgeschlossen (put_attempts, Statusmaschine).
     */
    public function resume(WriteOperation $operation): UploadResult
    {
        $status = $this->status($operation);

        if ($status === WriteOperationStatus::Unknown) {
            return $this->resolveUnknown($operation);
        }

        if ($status !== WriteOperationStatus::Sent) {
            return new UploadResult($operation, UploadResult::OUTCOME_IDEMPOTENT_REPLAY);
        }

        // sent ohne Abschluss (z. B. Worker-Abbruch nach dem PUT): wie unknown behandeln, nur PROPFIND.
        $this->transition($operation, WriteOperationStatus::Unknown, ['last_error' => 'resume: Antrag ohne Abschluss nach PUT, Prüfung nur per PROPFIND.'], (string) $operation->getAttribute('requested_via'));

        return $this->resolveUnknown($operation);
    }

    private function requiresHumanApproval(string $requestedVia): bool
    {
        $required = array_map('strval', (array) $this->config->get('hub.core.write.approval_required_via', ['api_key', 'api', 'mcp', 'n8n']));

        return in_array($requestedVia, $required, true);
    }

    /**
     * @param  array<string, mixed>  $precheck
     */
    private function keepPending(WriteOperation $operation, UploadRequest $request, array $precheck): void
    {
        if ($operation->getAttribute('source_storage_key') === null) {
            try {
                $operation->setAttribute('source_storage_key', $this->contents->store($operation, $request->content));
            } catch (Throwable $e) {
                Log::error('Inhalt des Upload-Antrags konnte nicht im Blob-Speicher abgelegt werden.', ['operation_id' => $operation->getKey(), 'error' => $this->masker->maskString($e->getMessage())]);
                $precheck['content_stored'] = false;
            }
        }

        $existing = (array) ($operation->getAttribute('precheck_result') ?? []);
        $operation->setAttribute('precheck_result', [...$existing, ...$precheck]);
        $operation->save();
    }

    /**
     * Führt einen Antrag in status pending aus (z. B. nach Freigabe). Ein Antrag in sent, unknown oder
     * verified löst nie ein weiteres PUT aus.
     */
    public function process(WriteOperation $operation, ?string $content = null, bool $dryRun = false): UploadResult
    {
        $status = $this->status($operation);

        if ($status === WriteOperationStatus::Unknown) {
            return $this->resolveUnknown($operation);
        }

        if ($status !== WriteOperationStatus::Pending) {
            return new UploadResult($operation, UploadResult::OUTCOME_IDEMPOTENT_REPLAY);
        }

        $content ??= $this->contents->retrieve($operation);

        if ($content === null) {
            throw new WriteBlockedException('Ohne Inhalt kann kein Upload ausgeführt werden.', 'PUT');
        }

        if (hash('sha256', $content) !== (string) $operation->getAttribute('content_hash')) {
            throw new WriteBlockedException('Inhalt passt nicht zum content_hash der Operation.', 'PUT');
        }

        /** @var ImmowareConnection $connection */
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->findOrFail((int) $operation->getAttribute('connection_id'));

        $denial = $this->denialReason($connection);

        if ($denial !== null) {
            return new UploadResult($operation, UploadResult::OUTCOME_DENIED);
        }

        $request = new UploadRequest(
            connectionId: (int) $connection->getKey(),
            content: $content,
            originalFilename: (string) $operation->getAttribute('original_filename'),
            intentKey: (string) $operation->getAttribute('intent_key'),
            requestedVia: (string) $operation->getAttribute('requested_via'),
            dryRun: $dryRun,
        );

        return $this->execute($operation, $connection, $request);
    }

    /**
     * Löst einen Antrag in status unknown ausschließlich per PROPFIND auf. Nie ein zweites PUT.
     * Nach IMMOWARE_WRITE_UNKNOWN_PROPFIND_ATTEMPTS vergeblichen Prüfungen wird der Antrag failed.
     */
    public function resolveUnknown(WriteOperation $operation): UploadResult
    {
        if ($this->status($operation) !== WriteOperationStatus::Unknown) {
            return new UploadResult($operation, UploadResult::OUTCOME_IDEMPOTENT_REPLAY);
        }

        /** @var ImmowareConnection $connection */
        $connection = ImmowareConnection::query()->withoutGlobalScopes()->findOrFail((int) $operation->getAttribute('connection_id'));
        $client = $this->clients->forConnection($connection);
        $maxAttempts = max(1, (int) $this->config->get('hub.core.write.unknown_propfind_attempts', 3));

        $operation->setAttribute('precheck_attempts', (int) $operation->getAttribute('precheck_attempts') + 1);
        $operation->save();

        try {
            $head = $client->head((string) $operation->getAttribute('target_path'));
        } catch (Throwable $e) {
            $operation->setAttribute('last_error', $this->masker->maskString(mb_substr($e->getMessage(), 0, 1000)));
            $operation->save();

            return $this->unknownAttemptExhausted($operation, $maxAttempts);
        }

        if ($head['status'] === 207 && $head['entry'] !== null) {
            return $this->verify($operation, $connection, $client, $head['entry']);
        }

        if ($head['status'] !== 404) {
            $operation->setAttribute('http_status', $head['status']);
            $operation->save();
        }

        return $this->unknownAttemptExhausted($operation, $maxAttempts);
    }

    public function isUnresolvedUnknown(WriteOperation $operation): bool
    {
        return $this->status($operation) === WriteOperationStatus::Unknown;
    }

    public function unknownRetryIntervalSeconds(): int
    {
        return max(1, (int) $this->config->get('hub.core.write.unknown_propfind_interval_seconds', 300));
    }

    private function execute(WriteOperation $operation, ImmowareConnection $connection, UploadRequest $request): UploadResult
    {
        $client = $this->clients->forConnection($connection);
        $targetPath = (string) $operation->getAttribute('target_path');
        $dryRun = $request->dryRun ?? (bool) $this->config->get('hub.core.write.dry_run', false);

        // Precheck: PROPFIND Depth 0 auf den Zielpfad.
        $this->transition($operation, WriteOperationStatus::Prechecked, ['precheck_attempts' => (int) $operation->getAttribute('precheck_attempts') + 1], $request->requestedVia);

        try {
            $head = $client->head($targetPath);
        } catch (Throwable $e) {
            $this->transition($operation, WriteOperationStatus::Failed, [
                'failed_at' => CarbonImmutable::now(),
                'last_error' => 'precheck: '.$this->masker->maskString(mb_substr($e->getMessage(), 0, 1000)),
                'precheck_result' => ['error' => $e::class],
            ], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_FAILED);
        }

        if ($head['status'] === 207) {
            return $this->handleExistingTarget($operation, $connection, $client, $head['entry'], $request);
        }

        if ($head['status'] !== 404) {
            $this->transition($operation, WriteOperationStatus::Failed, [
                'http_status' => $head['status'],
                'failed_at' => CarbonImmutable::now(),
                'last_error' => sprintf('Precheck PROPFIND antwortet %d.', $head['status']),
                'precheck_result' => ['status' => $head['status']],
            ], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_FAILED);
        }

        if ($dryRun) {
            $operation->setAttribute('precheck_result', ['status' => 404, 'dry_run' => true]);
            $operation->save();
            $this->audit('write.dry_run', $operation, [], ['target_path' => $targetPath], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_DRY_RUN);
        }

        // Status sent wird VOR dem Senden persistiert; put_attempts wird genau einmal erhöht.
        $this->transition($operation, WriteOperationStatus::Sent, [
            'precheck_result' => ['status' => 404],
            'put_attempts' => 1,
            'sent_at' => CarbonImmutable::now(),
        ], $request->requestedVia);

        try {
            $response = $client->putCreateOnly(
                $targetPath,
                $request->content,
                $request->contentType ?? (string) $this->config->get('hub.documents.upload.default_content_type', 'application/octet-stream'),
            );
        } catch (ConnectionException $e) {
            $this->transition($operation, WriteOperationStatus::Unknown, [
                'last_error' => 'put: '.$this->masker->maskString(mb_substr($e->getMessage(), 0, 1000)),
            ], $request->requestedVia);
            $this->scheduleUnknownResolution($operation);

            return new UploadResult($operation, UploadResult::OUTCOME_UNKNOWN, true);
        } catch (WriteBlockedException $e) {
            $this->transition($operation, WriteOperationStatus::Failed, [
                'failed_at' => CarbonImmutable::now(),
                'last_error' => 'blocked: '.$e->getMessage(),
            ], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_FAILED);
        } catch (Throwable $e) {
            // Kein verwertbarer Status: nur PROPFIND darf folgen.
            $this->transition($operation, WriteOperationStatus::Unknown, [
                'last_error' => 'put: '.$this->masker->maskString(mb_substr($e->getMessage(), 0, 1000)),
            ], $request->requestedVia);
            $this->scheduleUnknownResolution($operation);

            return new UploadResult($operation, UploadResult::OUTCOME_UNKNOWN, true);
        }

        $status = $response->status();
        $operation->setAttribute('http_status', $status);
        $operation->save();

        if ($status === 201 || $status === 204 || $status === 200) {
            return $this->verify($operation, $connection, $client, null, true);
        }

        if ($status === 412) {
            // Race mit Scanner: Ziel existiert. Nach sent ist laut Statusmaschine nur failed erreichbar (kein Rücksprung),
            // fachlich entspricht das skipped_exists; Konflikt write_target_exists, kein zweites PUT.
            $this->transition($operation, WriteOperationStatus::Failed, [
                'failed_at' => CarbonImmutable::now(),
                'last_error' => '412 Precondition Failed: Ziel existiert bereits (If-None-Match: *).',
                'precheck_result' => ['status' => 404, 'put_status' => 412, 'conflict' => self::CONFLICT_TARGET_EXISTS, 'rejected_reason' => 'precondition_failed'],
            ], $request->requestedVia);

            return new UploadResult($operation, UploadResult::OUTCOME_REJECTED, true);
        }

        // 4xx oder 5xx: kein Retry-PUT. Ob die Ressource dennoch existiert, klärt ein PROPFIND.
        try {
            $check = $client->head($targetPath);
        } catch (Throwable) {
            $check = ['status' => 0, 'entry' => null];
        }

        if ($check['status'] === 207 && $check['entry'] !== null) {
            return $this->verify($operation, $connection, $client, $check['entry'], true);
        }

        $this->transition($operation, WriteOperationStatus::Failed, [
            'failed_at' => CarbonImmutable::now(),
            'last_error' => sprintf('PUT antwortet %d.', $status),
        ], $request->requestedVia);

        return new UploadResult($operation, UploadResult::OUTCOME_FAILED, true);
    }

    /**
     * Ziel existiert bereits im Precheck: gleicher Inhalt gilt als verified ohne PUT, abweichender Inhalt als rejected.
     */
    private function handleExistingTarget(WriteOperation $operation, ImmowareConnection $connection, WebDavClient $client, ?DavEntry $entry, UploadRequest $request): UploadResult
    {
        $expectedSize = (int) $operation->getAttribute('size_bytes');
        $sameSize = $entry?->contentLength === null || $entry->contentLength === $expectedSize;
        $identical = false;

        if ($sameSize) {
            try {
                $download = $client->download((string) $operation->getAttribute('target_path'), $this->maxUploadBytes($connection));
                $identical = $download->isOk() && $download->sha256 === (string) $operation->getAttribute('content_hash');
            } catch (Throwable) {
                $identical = false;
            }
        }

        if ($identical) {
            $this->transition($operation, WriteOperationStatus::Verified, [
                'verified_at' => CarbonImmutable::now(),
                'precheck_result' => ['status' => 207, 'exists_identical' => true],
                'verify_result' => ['method' => 'precheck_hash', 'hash_match' => true, 'put_skipped' => true],
            ], $request->requestedVia);
            $this->mirrorUploadedDocument($operation, $connection, $entry);

            return new UploadResult($operation, UploadResult::OUTCOME_EXISTS_IDENTICAL);
        }

        $this->transition($operation, WriteOperationStatus::Rejected, [
            'failed_at' => CarbonImmutable::now(),
            'last_error' => 'Zielpfad existiert bereits mit abweichendem Inhalt.',
            'precheck_result' => ['status' => 207, 'exists_identical' => false, 'conflict' => self::CONFLICT_TARGET_EXISTS, 'remote_size' => $entry?->contentLength],
        ], $request->requestedVia);

        return new UploadResult($operation, UploadResult::OUTCOME_REJECTED);
    }

    /**
     * Verifikation: PROPFIND (getcontentlength = size_bytes) und, wenn verify_with_hash, GET plus SHA-256.
     */
    private function verify(WriteOperation $operation, ImmowareConnection $connection, WebDavClient $client, ?DavEntry $entry, bool $putSent = false): UploadResult
    {
        $operation->setAttribute('verify_attempts', (int) $operation->getAttribute('verify_attempts') + 1);
        $operation->save();

        $targetPath = (string) $operation->getAttribute('target_path');
        $result = ['method' => 'propfind'];

        if ($entry === null) {
            try {
                $head = $client->head($targetPath);
            } catch (Throwable $e) {
                return $this->verifyFailed($operation, ['error' => $e::class, 'message' => $this->masker->maskString(mb_substr($e->getMessage(), 0, 500))], $putSent);
            }

            if ($head['status'] !== 207 || $head['entry'] === null) {
                return $this->verifyFailed($operation, ['propfind_status' => $head['status']], $putSent);
            }

            $entry = $head['entry'];
        }

        $expectedSize = (int) $operation->getAttribute('size_bytes');
        $result['remote_size'] = $entry->contentLength;
        $result['size_match'] = $entry->contentLength === null || $entry->contentLength === $expectedSize;

        if (! $result['size_match']) {
            return $this->verifyFailed($operation, $result, $putSent);
        }

        if ($this->verifyWithHash($connection)) {
            try {
                $download = $client->download($targetPath, $this->maxUploadBytes($connection));
            } catch (Throwable $e) {
                return $this->verifyFailed($operation, [...$result, 'error' => $e::class], $putSent);
            }

            $result['method'] = 'propfind_get_hash';
            $result['hash_match'] = $download->isOk() && $download->sha256 === (string) $operation->getAttribute('content_hash');

            if (! $result['hash_match']) {
                return $this->verifyFailed($operation, $result, $putSent);
            }
        }

        $this->transition($operation, WriteOperationStatus::Verified, [
            'verified_at' => CarbonImmutable::now(),
            'verify_result' => $result,
        ], (string) $operation->getAttribute('requested_via'));

        $this->mirrorUploadedDocument($operation, $connection, $entry);

        return new UploadResult($operation, UploadResult::OUTCOME_UPLOADED, $putSent);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function verifyFailed(WriteOperation $operation, array $result, bool $putSent): UploadResult
    {
        $this->transition($operation, WriteOperationStatus::Failed, [
            'failed_at' => CarbonImmutable::now(),
            'verify_result' => [...$result, 'conflict' => self::CONFLICT_VERIFY_FAILED],
            'last_error' => 'Verifikation fehlgeschlagen: Länge oder Hash abweichend. Kein zweites PUT, manuelle Prüfung im DMS.',
        ], (string) $operation->getAttribute('requested_via'));

        return new UploadResult($operation, UploadResult::OUTCOME_FAILED, $putSent);
    }

    private function unknownAttemptExhausted(WriteOperation $operation, int $maxAttempts): UploadResult
    {
        if ((int) $operation->getAttribute('precheck_attempts') >= $maxAttempts + 1) {
            $this->transition($operation, WriteOperationStatus::Failed, [
                'failed_at' => CarbonImmutable::now(),
                'verify_result' => ['conflict' => self::CONFLICT_UNKNOWN_UNRESOLVED, 'propfind_attempts' => (int) $operation->getAttribute('precheck_attempts') - 1],
                'last_error' => 'PUT ohne Antwort, PROPFIND findet die Datei nicht. Manuelle Entscheidung, kein zweites PUT.',
            ], (string) $operation->getAttribute('requested_via'));

            return new UploadResult($operation, UploadResult::OUTCOME_FAILED);
        }

        return new UploadResult($operation, UploadResult::OUTCOME_UNKNOWN);
    }

    /**
     * Dokumentzeile mit origin hub_upload auf der gekoppelten Lese-Connection, damit der nächste
     * Posteingang-Scan dieselbe Zeile aktualisiert statt ein Duplikat anzulegen.
     */
    private function mirrorUploadedDocument(WriteOperation $operation, ImmowareConnection $connection, ?DavEntry $entry): void
    {
        $readConnectionId = $connection->getAttribute('paired_read_connection_id') !== null
            ? (int) $connection->getAttribute('paired_read_connection_id')
            : (int) $connection->getKey();

        $path = (string) $operation->getAttribute('target_path');
        $pathHash = Document::hashPath($path);

        try {
            /** @var Document|null $document */
            $document = Document::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where('connection_id', $readConnectionId)
                ->where('path_hash', $pathHash)
                ->first();

            if ($document === null) {
                $document = new Document;
                $document->setAttribute('organization_id', (int) $connection->getAttribute('organization_id'));
                $document->setAttribute('connection_id', $readConnectionId);
                $document->setAttribute('source_system', Document::SOURCE_IMMOWARE24);
                $document->setAttribute('external_id', $path);
                $document->setAttribute('first_synced_at', CarbonImmutable::now());
            }

            $document->setAttribute('path', $path);
            $document->setAttribute('path_hash', $pathHash);
            $document->setAttribute('filename', (string) $operation->getAttribute('sanitized_filename'));
            $document->setAttribute('size_bytes', (int) $operation->getAttribute('size_bytes'));
            $document->setAttribute('content_hash', (string) $operation->getAttribute('content_hash'));
            $document->setAttribute('content_stored', false);
            $document->setAttribute('remote_etag', $entry?->etag);
            $document->setAttribute('remote_last_modified', $entry?->lastModifiedAt());
            $document->setAttribute('origin', DocumentMirrorService::ORIGIN_HUB_UPLOAD);
            $document->setAttribute('write_operation_id', (int) $operation->getKey());
            $document->setAttribute('external_parent_id', WebDavPath::parent($path));
            $document->setAttribute('last_synced_at', CarbonImmutable::now());
            $document->setAttribute('deleted_at', null);
            $document->setAttribute('missing_since', null);
            $document->applyChecksum(['path' => $path, 'content_hash' => (string) $operation->getAttribute('content_hash')]);
            $document->save();
        } catch (Throwable $e) {
            Log::warning('Dokumentzeile für Upload konnte nicht angelegt werden.', ['operation_id' => $operation->getKey(), 'error' => $e->getMessage()]);
        }
    }

    private function rejectionReason(UploadRequest $request, ImmowareConnection $connection, string $targetPath, string $prefix): ?string
    {
        if (trim($prefix) === '' || $prefix === '/') {
            return 'allowed_prefix_missing';
        }

        if (str_contains($request->originalFilename, '..') && str_contains($targetPath, '..')) {
            return 'path_traversal';
        }

        if (! WebDavPath::isWithin($targetPath, $prefix)) {
            return 'target_outside_allowed_prefix';
        }

        if ($request->sizeBytes() <= 0) {
            return 'empty_content';
        }

        if ($request->sizeBytes() > $this->maxUploadBytes($connection)) {
            return 'max_upload_bytes_exceeded';
        }

        return null;
    }

    private function denialReason(ImmowareConnection $connection): ?string
    {
        if (! $this->truthy($this->config->get('hub.core.write.enabled', false))) {
            return 'write_disabled_global';
        }

        if (! $this->truthy($this->config->get('hub.core.write.webdav_create_enabled', false))) {
            return 'webdav_create_disabled';
        }

        if (! $this->capabilities->hasFor((int) $connection->getKey(), CapabilityKey::DocumentsWrite->value)) {
            return 'capability_documents_write_missing';
        }

        if (! $connection->isWritePurpose()) {
            return 'connection_purpose_not_write';
        }

        // Vier-Augen-Prinzip: write_enabled_by (admin) und write_confirmed_by (release/Owner) verschieden, Freigabedokument gesetzt.
        $approval = $connection->writeApprovalIncompleteReason();

        if ($approval !== null) {
            return $approval;
        }

        if ((string) $connection->getAttribute('status') !== 'active') {
            return 'connection_not_active';
        }

        return null;
    }

    /**
     * Plant die PROPFIND-Auflösung eines unknown-Antrags im konfigurierten Intervall ein (05 3.3).
     */
    private function scheduleUnknownResolution(WriteOperation $operation): void
    {
        try {
            $this->bus->dispatch((new ResolveUnknownWriteOperationJob((int) $operation->getKey(), $this->correlation->current()))->delay($this->unknownRetryIntervalSeconds()));
        } catch (Throwable $e) {
            Log::error('ResolveUnknownWriteOperationJob konnte nicht eingeplant werden.', ['operation_id' => $operation->getKey(), 'error' => $this->masker->maskString($e->getMessage())]);
        }
    }

    private function allowedPrefix(ImmowareConnection $connection): string
    {
        $prefix = $connection->getAttribute('allowed_write_prefix');

        if (! is_string($prefix) || trim($prefix) === '') {
            $prefix = (string) $this->config->get('hub.core.write.allowed_prefix', '/Posteingang/');
        }

        return WebDavPath::normalize($prefix, true);
    }

    private function maxUploadBytes(ImmowareConnection $connection): int
    {
        $configured = (int) $this->config->get('hub.core.write.max_upload_bytes', 26214400);
        $perConnection = (int) ($connection->getAttribute('max_upload_bytes') ?? $configured);

        return max(1, min($configured, $perConnection > 0 ? $perConnection : $configured));
    }

    private function verifyWithHash(ImmowareConnection $connection): bool
    {
        if (! $this->truthy($this->config->get('hub.core.write.verify_with_hash', true))) {
            return false;
        }

        return (bool) ($connection->getAttribute('verify_with_hash') ?? true);
    }

    private function intentKey(UploadRequest $request): string
    {
        if ($request->intentKey !== null && trim($request->intentKey) !== '') {
            return hash('sha256', trim($request->intentKey));
        }

        if ($request->sourceDocumentId !== null) {
            return hash('sha256', 'document:'.$request->sourceDocumentId);
        }

        if ($request->caseId !== null) {
            return hash('sha256', 'case:'.$request->caseId.'|'.$request->originalFilename);
        }

        return hash('sha256', (string) Str::uuid());
    }

    private function suffix(): string
    {
        return str_replace('-', '', (string) Str::orderedUuid());
    }

    private function sanitizer(): FilenameSanitizer
    {
        return new FilenameSanitizer(array_map('strval', (array) $this->config->get('hub.documents.upload.allowed_extensions', [])));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transition(WriteOperation $operation, WriteOperationStatus $status, array $attributes, string $requestedVia): void
    {
        $before = $this->snapshot($operation);

        foreach ($attributes as $key => $value) {
            $operation->setAttribute($key, $value);
        }

        $operation->setAttribute('status', $status);
        $operation->save();

        $this->audit('write.'.$status->value, $operation, $before, $this->snapshot($operation), $requestedVia);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(WriteOperation $operation): array
    {
        return [
            'status' => $this->status($operation)->value,
            'target_path' => $operation->getAttribute('target_path'),
            'content_hash' => $operation->getAttribute('content_hash'),
            'size_bytes' => $operation->getAttribute('size_bytes'),
            'http_status' => $operation->getAttribute('http_status'),
            'put_attempts' => $operation->getAttribute('put_attempts'),
            'precheck_attempts' => $operation->getAttribute('precheck_attempts'),
            'verify_attempts' => $operation->getAttribute('verify_attempts'),
            'precheck_result' => $operation->getAttribute('precheck_result'),
            'verify_result' => $operation->getAttribute('verify_result'),
            'last_error' => $operation->getAttribute('last_error'),
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function audit(string $action, WriteOperation $operation, array $before, array $after, string $requestedVia): void
    {
        $source = match ($requestedVia) {
            'api', 'api_key' => AuditSource::Api,
            'ui' => AuditSource::User,
            'n8n' => AuditSource::N8n,
            'mcp' => AuditSource::Mcp,
            default => AuditSource::System,
        };

        try {
            $this->audit->log($action, $operation, $before, $after, $source->value, $this->correlation->current());
        } catch (Throwable $e) {
            Log::error('Auditeintrag für write_operation fehlgeschlagen.', ['action' => $action, 'operation_id' => $operation->getKey(), 'error' => $this->masker->maskString($e->getMessage())]);
        }
    }

    private function status(WriteOperation $operation): WriteOperationStatus
    {
        $status = $operation->getAttribute('status');

        return $status instanceof WriteOperationStatus ? $status : (WriteOperationStatus::tryFrom((string) $status) ?? WriteOperationStatus::Pending);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return $value === 1;
    }
}
