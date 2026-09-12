<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Contracts\WebhookDispatcherInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Requests\StoreCaseRequest;
use App\Modules\Api\Http\Requests\UpdateCaseRequest;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Api\Support\ResourceRegistry;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Hub-eigene Vorgänge: POST /cases, PATCH /cases/{id}. Kein Schreibpfad nach Immoware24.
 */
final class CaseController
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ApiResponse $response,
        private readonly ApiCaller $caller,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
        private readonly Container $container,
    ) {}

    public function store(StoreCaseRequest $request): JsonResponse
    {
        $organizationId = $this->caller->organizationId($request);

        if ($organizationId === null) {
            throw ApiProblemException::forbidden('role_forbidden', 'Kein Mandantenkontext.');
        }

        $data = $request->validated();
        $this->assertReferences($data);

        $case = DB::transaction(function () use ($data, $organizationId): CaseFile {
            $case = new CaseFile;
            $case->forceFill([
                'organization_id' => $organizationId,
                'title' => $data['title'],
                'status' => $data['status'] ?? 'open',
                'property_id' => $data['property_id'] ?? null,
                'unit_id' => $data['unit_id'] ?? null,
                'contact_id' => $data['contact_id'] ?? null,
                'immoware_ticket_reference' => $data['immoware_ticket_reference'] ?? null,
                'source_system' => 'hub',
            ]);
            $case->save();

            $this->audit->log('api.case.created', $case, [], $case->only(['title', 'status', 'property_id', 'unit_id', 'contact_id']), AuditSource::Api->value, $this->correlationId->current());
            $this->emit('case.created', $case, $organizationId);

            return $case;
        });

        $definition = $this->registry->get('cases');

        return $this->response->single($case, $definition, $request, null, 201, ['Location' => $definition->href().'/'.$case->getKey()]);
    }

    public function update(UpdateCaseRequest $request, string $id): JsonResponse
    {
        /** @var CaseFile $case */
        $case = CaseFile::query()->whereKey((int) $id)->firstOrFail();
        $data = $request->validated();

        if ($data === []) {
            throw ApiProblemException::badRequest('bad_request', 'Es wurden keine änderbaren Felder übergeben.');
        }

        $this->assertReferences($data);
        $before = $case->only(array_keys($data));

        DB::transaction(function () use ($case, $data, $before): void {
            $case->forceFill($data);
            $case->save();

            $this->audit->log('api.case.updated', $case, $before, $data, AuditSource::Api->value, $this->correlationId->current());
            $this->emit('case.updated', $case, (int) $case->getAttribute('organization_id'));
        });

        return $this->response->single($case->refresh(), $this->registry->get('cases'), $request);
    }

    /**
     * Referenzen müssen im Mandanten existieren, sonst 422.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertReferences(array $data): void
    {
        $checks = [
            'property_id' => Property::class,
            'unit_id' => Unit::class,
            'contact_id' => Contact::class,
        ];

        $errors = [];

        foreach ($checks as $field => $model) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }

            if (! $model::query()->whereKey((int) $data[$field])->exists()) {
                $errors[$field] = ['Die referenzierte Ressource existiert nicht oder ist nicht sichtbar.'];
            }
        }

        if ($errors !== []) {
            throw new ApiProblemException(422, 'validation_failed', 'Referenzierte Ressourcen wurden nicht gefunden.', [
                'errors' => array_map(static fn (string $field, array $messages): array => ['field' => $field, 'code' => 'not_found', 'message' => $messages[0]], array_keys($errors), $errors),
            ]);
        }
    }

    private function emit(string $event, CaseFile $case, int $organizationId): void
    {
        if (! $this->container->bound(WebhookDispatcherInterface::class)) {
            return;
        }

        $this->container->make(WebhookDispatcherInterface::class)->dispatch($event, [
            'id' => (int) $case->getKey(),
            'type' => 'case',
            'href' => $this->registry->get('cases')->href().'/'.$case->getKey(),
            'status' => $case->getAttribute('status'),
        ], $organizationId);
    }
}
