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
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;

/**
 * POST /documents: Upload-Antrag für den WebDAV-Posteingang. Einziger Schreibpfad Richtung Immoware24,
 * delegiert an den PosteingangUploadService des Documents-Moduls. Fehlt der Service: 501 WAITING_FOR_MODULE.
 */
final class DocumentUploadController
{
    public const string UPLOAD_SERVICE = 'App\Modules\Documents\Services\PosteingangUploadService';

    public const string UPLOAD_REQUEST = 'App\Modules\Documents\DTO\UploadRequest';

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
        $result = $this->container->make(self::UPLOAD_SERVICE)->upload($uploadRequest);
        $operation = $result->operation;
        $status = $operation->getAttribute('status');

        $this->audit->log('api.document.upload_requested', $operation, [], [
            'filename' => $data['filename'],
            'size_bytes' => strlen($content),
            'outcome' => $result->outcome,
        ], AuditSource::Api->value, $this->correlationId->current());

        return $this->response->raw([
            'upload_id' => (int) $operation->getKey(),
            'status' => $status instanceof \BackedEnum ? $status->value : (string) $status,
            'outcome' => $result->outcome,
            'target_path' => $operation->getAttribute('target_path'),
            'effect' => 'immoware24',
        ], [], 202);
    }
}
