<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Requests\UploadDocumentRequest;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Services\WriteGuard;
use App\Modules\Api\Support\ApiCaller;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /documents: Upload-Antrag für den WebDAV-Posteingang. Einziger Schreibpfad Richtung Immoware24,
 * delegiert an den PosteingangUploadService des Documents-Moduls. Fehlt der Service: 501 WAITING_FOR_MODULE.
 * Antwort 202 mit operation_uuid; GET /documents/uploads/{uuid} liefert den Status der write_operation.
 */
final class DocumentUploadController
{
    public const string UPLOAD_SERVICE = 'App\Modules\Documents\Services\PosteingangUploadService';

    public const string UPLOAD_REQUEST = 'App\Modules\Documents\DTO\UploadRequest';

    public const string WRITE_OPERATION = 'App\Modules\Sync\Models\WriteOperation';

    public function __construct(
        private readonly WriteGuard $guard,
        private readonly ApiCaller $caller,
        private readonly ApiResponse $response,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
        private readonly Container $container,
    ) {}

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $organizationId = $this->caller->organizationId($request);

        if ($organizationId === null) {
            throw ApiProblemException::forbidden('role_forbidden', 'Kein Mandantenkontext.');
        }

        $data = $request->validated();
        $connectionId = isset($data['connection_id']) ? (int) $data['connection_id'] : null;

        $connection = $this->guard->assertUploadAllowed($connectionId, $organizationId);

        if (! class_exists(self::UPLOAD_SERVICE) || ! class_exists(self::UPLOAD_REQUEST)) {
            throw ApiProblemException::notImplemented('Der Posteingang-Upload ist noch nicht verfügbar.', 'WAITING_FOR_MODULE: Documents\PosteingangUploadService');
        }

        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw new ApiProblemException(422, 'validation_failed', 'Die Datei fehlt.');
        }

        $content = (string) file_get_contents($file->getRealPath() ?: $file->getPathname());
        $idempotencyKey = (string) $request->headers->get((string) config('hub.api.idempotency.header', 'Idempotency-Key'), '');

        $uploadRequestClass = self::UPLOAD_REQUEST;
        $uploadRequest = new $uploadRequestClass(
            connectionId: (int) $connection->getKey(),
            content: $content,
            originalFilename: (string) $data['filename'],
            intentKey: $idempotencyKey,
            contentType: $file->getClientMimeType(),
            requestedBy: null,
            requestedVia: 'api_key',
            sourceDocumentId: isset($data['source_document_id']) ? (int) $data['source_document_id'] : null,
            caseId: isset($data['case_id']) ? (int) $data['case_id'] : null,
        );

        /** @var object{operation: Model, outcome: string} $result */
        $result = $this->container->make(self::UPLOAD_SERVICE)->submit($uploadRequest);
        $operation = $result->operation;
        $status = $operation->getAttribute('status');

        $this->audit->log('api.document.upload_requested', $operation, [], [
            'filename' => $data['filename'],
            'size_bytes' => strlen($content),
            'outcome' => $result->outcome,
        ], AuditSource::Api->value, $this->correlationId->current());

        $uuid = (string) $operation->getAttribute('operation_uuid');

        return $this->response->raw(array_merge($this->present($operation), [
            'outcome' => $result->outcome,
            // Anträge aus API-Key-Kontext wirken erst nach menschlicher Freigabe im Livesystem (09 §3.5).
            'effect' => in_array($result->outcome, ['pending_approval', 'denied'], true) ? 'immoware24_after_approval' : 'immoware24',
            'queued' => $result->outcome === 'queued',
            'approval_required' => $result->outcome === 'pending_approval',
            'status_url' => '/api/'.config('hub.api.version', 'v1').'/documents/uploads/'.$uuid,
        ]), [], 202, ['Location' => '/api/'.config('hub.api.version', 'v1').'/documents/uploads/'.$uuid]);
    }

    /**
     * GET /documents/uploads/{uuid}: Status eines Upload-Antrags (nur write_operations des eigenen Mandanten,
     * Zuordnung über die Connection). Keine Fachlogik, nur Lesen des Statusdatensatzes.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $organizationId = $this->caller->organizationId($request);

        if ($organizationId === null) {
            throw ApiProblemException::forbidden('role_forbidden', 'Kein Mandantenkontext.');
        }

        if (preg_match('/^[0-9a-fA-F-]{36}$/', $uuid) !== 1 || ! class_exists(self::WRITE_OPERATION)) {
            throw new NotFoundHttpException;
        }

        $modelClass = self::WRITE_OPERATION;
        /** @var Builder<Model> $query */
        $query = $modelClass::query();
        $operation = $query
            ->where('operation_uuid', strtolower($uuid))
            ->whereHas('connection', static fn (Builder $connection) => $connection->withoutGlobalScopes()->where('organization_id', $organizationId))
            ->first();

        if (! $operation instanceof Model) {
            throw new NotFoundHttpException;
        }

        return $this->response->raw($this->present($operation));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Model $operation): array
    {
        $status = $operation->getAttribute('status');
        $date = static fn (mixed $value): ?string => $value instanceof \DateTimeInterface ? CarbonImmutable::instance($value)->utc()->toIso8601ZuluString('millisecond') : null;

        return [
            'operation_uuid' => (string) $operation->getAttribute('operation_uuid'),
            'upload_id' => (int) $operation->getKey(),
            'status' => $status instanceof \BackedEnum ? $status->value : (string) $status,
            'operation' => $operation->getAttribute('operation'),
            'target_path' => $operation->getAttribute('target_path'),
            'original_filename' => $operation->getAttribute('original_filename'),
            'size_bytes' => $operation->getAttribute('size_bytes'),
            'content_hash' => $operation->getAttribute('content_hash'),
            'requested_via' => $operation->getAttribute('requested_via'),
            'precheck_result' => $operation->getAttribute('precheck_result'),
            'verify_result' => $operation->getAttribute('verify_result'),
            'last_error' => $operation->getAttribute('last_error'),
            'document_id' => $operation->getAttribute('document_id'),
            'case_id' => $operation->getAttribute('case_id'),
            'source_document_id' => $operation->getAttribute('source_document_id'),
            'sent_at' => $date($operation->getAttribute('sent_at')),
            'verified_at' => $date($operation->getAttribute('verified_at')),
            'failed_at' => $date($operation->getAttribute('failed_at')),
            'created_at' => $date($operation->getAttribute('created_at')),
            'updated_at' => $date($operation->getAttribute('updated_at')),
        ];
    }
}
