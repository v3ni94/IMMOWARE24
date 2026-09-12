<?php

declare(strict_types=1);

namespace App\Modules\Api\Http\Controllers;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Modules\Api\Exceptions\ApiProblemException;
use App\Modules\Api\Http\Requests\ProposeContactChangeRequest;
use App\Modules\Api\Http\Resources\ApiResponse;
use App\Modules\Api\Support\ApiCaller;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Sync\Models\ProposedChange;
use App\Modules\Sync\Services\ProposedChangeService;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PATCH /contacts/{id}: legt je Feld einen Änderungsvorschlag (proposed_change) an und antwortet 202.
 * Es findet kein Writeback nach Immoware24 statt.
 */
final class ContactProposalController
{
    public function __construct(
        private readonly ApiResponse $response,
        private readonly ApiCaller $caller,
        private readonly AuditLoggerInterface $audit,
        private readonly CorrelationId $correlationId,
        private readonly Container $container,
    ) {}

    public function update(ProposeContactChangeRequest $request, string $id): JsonResponse
    {
        /** @var Contact $contact */
        $contact = Contact::query()->whereKey((int) $id)->firstOrFail();
        $organizationId = $this->caller->organizationId($request);

        if ($organizationId === null) {
            throw ApiProblemException::forbidden('role_forbidden', 'Kein Mandantenkontext.');
        }

        /** @var array<string, mixed> $changes */
        $changes = $request->validated('changes');
        $reason = $request->validated('reason');

        $proposals = DB::transaction(function () use ($contact, $changes, $reason, $organizationId): array {
            $result = [];

            foreach ($changes as $field => $newValue) {
                $oldValue = $contact->getAttribute((string) $field);
                $proposal = $this->createProposal($contact, (string) $field, $oldValue, $newValue, $organizationId, is_string($reason) ? $reason : null);

                $result[] = [
                    'id' => (int) $proposal->getKey(),
                    'entity_type' => 'contact',
                    'entity_id' => (int) $contact->getKey(),
                    'field' => (string) $field,
                    'old_value' => $proposal->getAttribute('old_value'),
                    'new_value' => $proposal->getAttribute('new_value'),
                    'status' => $proposal->getAttribute('status') instanceof \BackedEnum ? $proposal->getAttribute('status')->value : (string) $proposal->getAttribute('status'),
                ];
            }

            $this->audit->log('api.contact.change_proposed', $contact, [], ['fields' => array_keys($changes)], AuditSource::Api->value, $this->correlationId->current());

            return $result;
        });

        return $this->response->raw([
            'contact_id' => (int) $contact->getKey(),
            'proposals' => $proposals,
            'effect' => 'hub',
            'note' => 'Änderungsvorschlag angelegt. Die Umsetzung erfolgt manuell in Immoware24 und wird durch den nächsten Sync bestätigt.',
        ], [], 202);
    }

    private function createProposal(Contact $contact, string $field, mixed $oldValue, mixed $newValue, int $organizationId, ?string $reason): ProposedChange
    {
        if (class_exists(ProposedChangeService::class) && $this->container->bound(ProposedChangeService::class)) {
            return $this->container->make(ProposedChangeService::class)->propose(
                'contact',
                (int) $contact->getKey(),
                $field,
                $oldValue,
                $newValue,
                null,
                $contact->getAttribute('connection_id') !== null ? (int) $contact->getAttribute('connection_id') : null,
                $organizationId,
                $reason,
            );
        }

        $proposal = new ProposedChange;
        $proposal->forceFill([
            'organization_id' => $organizationId,
            'connection_id' => $contact->getAttribute('connection_id'),
            'entity_type' => 'contact',
            'entity_id' => (int) $contact->getKey(),
            'field' => $field,
            'old_value' => is_scalar($oldValue) || $oldValue === null ? $oldValue : json_encode($oldValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_value' => is_scalar($newValue) || $newValue === null ? $newValue : json_encode($newValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'reason' => $reason,
            'status' => 'open',
            'correlation_id' => $this->correlationId->current(),
        ]);
        $proposal->save();

        return $proposal;
    }
}
