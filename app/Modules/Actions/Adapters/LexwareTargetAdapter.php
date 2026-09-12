<?php

declare(strict_types=1);

namespace App\Modules\Actions\Adapters;

use App\Modules\Actions\Contracts\StepAwareAdapterInterface;
use App\Modules\Actions\DTO\StepResult;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Exceptions\ActionConflictException;
use App\Modules\Actions\Exceptions\ActionNotEditableException;
use App\Modules\Actions\Exceptions\ActionTimeoutException;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ManualTaskFactory;
use App\Modules\Actions\Support\DiffHasher;
use App\Modules\Lexware\DTO\LexwareCredentials;
use App\Modules\Lexware\Exceptions\LexwareConflictException;
use App\Modules\Lexware\Exceptions\LexwareNotConfiguredException;
use App\Modules\Lexware\Exceptions\LexwareTimeoutException;
use App\Modules\Lexware\Exceptions\LexwareUnavailableException;
use App\Modules\Lexware\Models\LexwareContactSnapshot;
use App\Modules\Lexware\Services\LexwareClient;
use App\Modules\Lexware\Services\LexwareClientFactory;
use App\Modules\Lexware\Services\LexwareConnectionResolver;
use App\Modules\Lexware\Support\LexwareContactRules;
use App\Modules\Mail\Services\MailFeatureFlags;
use Carbon\CarbonImmutable;

/**
 * Lexware Office als Zielsystem: Kontakt lesen (GET), Adresse oder Notiz ändern nur auf frisch gelesenem Datensatz
 * mit version (PUT, 409 = Konflikt), danach GET zum Versionsvergleich. Kontakte mit mehreren Adressen oder Personen
 * gehen in den manuellen Klärungsweg (nie Einträge entfernen). Kein Anlegen von Kunden, keine Rechnungsänderungen.
 * Endpunkte aus Snippets, vor Produktivbetrieb am Original prüfen.
 */
final class LexwareTargetAdapter implements StepAwareAdapterInterface
{
    public function __construct(
        private readonly LexwareClientFactory $clients,
        private readonly LexwareConnectionResolver $resolver,
        private readonly LexwareContactRules $rules,
        private readonly ManualTaskFactory $tasks,
        private readonly DiffHasher $hasher,
        private readonly MailFeatureFlags $flags,
    ) {}

    public function targetSystem(): string
    {
        return TargetSystem::Lexware->value;
    }

    /**
     * @return array<string, array{status: string, reason: ?string}>
     */
    public function capabilities(): array
    {
        if ($this->resolver->find(null) === null) {
            $notConfigured = ['status' => 'not_configured', 'reason' => 'Nicht eingerichtet (kein API-Key).'];

            return ['address_change' => $notConfigured, 'note' => $notConfigured, 'bank_change' => ['status' => 'unavailable', 'reason' => 'Bankdatenfeld in Snippets nicht belegt; nur manuelle Aufgabe.'], 'manual_task' => ['status' => 'available', 'reason' => null]];
        }

        $write = $this->flags->lexwareWriteEnabled();

        return [
            'address_change' => ['status' => $write ? 'restricted' : 'unavailable', 'reason' => $write ? 'Nur Kontakte mit höchstens einer Rechnungs- und Lieferadresse; GET, PUT mit version, GET.' : 'Flag lexware_write nicht aktiv.'],
            'note' => ['status' => $write ? 'restricted' : 'unavailable', 'reason' => $write ? 'Notizfeld laut Drittquelle, am Original prüfen.' : 'Flag lexware_write nicht aktiv.'],
            'bank_change' => ['status' => 'unavailable', 'reason' => 'Bankdatenfeld in Snippets nicht belegt; nur manuelle Aufgabe.'],
            'manual_task' => ['status' => 'available', 'reason' => null],
        ];
    }

    /**
     * @param  array{reference_type: string, external_id: string, local_id?: ?int}  $ref
     * @return array<string, mixed>
     *
     * @throws LexwareUnavailableException wenn Lexware nicht erreichbar ist (Ergebnis "Ungeklärt", nie "kein Kunde")
     */
    public function readCurrent(array $ref): array
    {
        if (($ref['reference_type'] ?? '') !== 'contact') {
            return [];
        }

        $client = $this->clientFor($ref);
        $contact = $client->getContact((string) $ref['external_id']);

        if ($contact === null) {
            return ['found' => false];
        }

        $this->snapshot($client->credentials(), $contact, 'lookup', $ref);

        return $this->rules->flatten($contact) + ['non_editable_reasons' => $this->rules->nonEditableReasons($contact)];
    }

    /**
     * @return array{steps: array<int, array<string, mixed>>, risk_class: string, approval_required: bool, warnings: array<int, string>}
     */
    public function prepareChange(object $plan): array
    {
        return ['steps' => [], 'risk_class' => 'high', 'approval_required' => true, 'warnings' => []];
    }

    /**
     * @return array<int, array{step_index: int, status: string, response_status: ?int, execution_uuid: string}>
     */
    public function executeApprovedChange(object $planVersion, object $approval): array
    {
        return [];
    }

    /**
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>}
     */
    public function verifyChange(object $execution): array
    {
        if (! $execution instanceof Execution || $execution->version === null) {
            return ['status' => VerificationStatus::Unverified->value, 'expected' => [], 'observed' => []];
        }

        $result = $this->verifyStep($execution->version, (int) $execution->getAttribute('step_index'), $execution);

        return ['status' => $result['status'], 'expected' => $result['expected'], 'observed' => $result['observed']];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<int, string>
     */
    public function validateStep(array $step): array
    {
        $warnings = [];
        $type = (string) ($step['action_type'] ?? '');
        $capability = $this->capabilities()[$type] ?? ['status' => 'unavailable', 'reason' => 'unbekannt'];

        if ($capability['status'] !== 'available') {
            $warnings[] = sprintf('Lexware %s: %s', $type, (string) ($capability['reason'] ?? 'nicht verfügbar'));
        }

        return $warnings;
    }

    public function executeStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): StepResult
    {
        $step = $version->steps()[$stepIndex] ?? [];
        $ref = (array) ($step['external_ref'] ?? []);
        $old = (array) (($version->getAttribute('old_values') ?? [])[$stepIndex] ?? []);
        $new = (array) (($version->getAttribute('new_values') ?? [])[$stepIndex] ?? []);
        $mode = (string) ($step['mode'] ?? 'api');

        if ($mode !== 'api' || ! isset($ref['external_id'])) {
            $created = $this->tasks->create($version, $stepIndex, $step, $old, $new, TargetSystem::Lexware, $execution->getAttribute('executed_by'));

            return StepResult::manualTask((int) $created['task']->getKey(), null, ['mode' => 'manual_task']);
        }

        $client = $this->clientFor($ref);
        $id = (string) $ref['external_id'];

        // Schreibfreigabe je Verbindung (mail_lexware_connections.write_enabled) zusätzlich zum globalen Flag. Der
        // Fallback auf den Umgebungs-API-Key ist nie schreibfähig. Ohne Freigabe kein Lesen, kein PUT: gesperrt.
        if (! $client->credentials()->writeEnabled) {
            throw new ActionNotEditableException(sprintf('Schreiben über die Lexware-Verbindung%s ist gesperrt (write_enabled nicht gesetzt); Änderung nicht ausgeführt.', $client->credentials()->connectionId !== null ? ' '.$client->credentials()->connectionId : ''));
        }

        try {
            $fresh = $client->getContact($id);
        } catch (LexwareUnavailableException $e) {
            return StepResult::failed('Lexware vor der Änderung nicht lesbar: '.$e->getMessage(), $e->httpStatus);
        }

        if ($fresh === null) {
            return StepResult::failed('Kontakt in Lexware nicht gefunden (404).', 404);
        }

        // Nur zugelassene Felder, nur auf frisch gelesenem Datensatz, nur wenn der Ausgangszustand noch stimmt.
        $current = $this->rules->flatten($fresh);
        $observedOld = array_intersect_key($current, $old);

        if ($this->hasher->hash($old) !== $this->hasher->hash($observedOld + array_fill_keys(array_keys($old), null))) {
            throw new ActionConflictException('Kontakt hat sich seit Planerstellung geändert; Änderung nicht ausgeführt.');
        }

        $reasons = $this->rules->nonEditableReasons($fresh);

        if ($reasons !== []) {
            // Manueller Klärungsweg: nichts schreiben, nichts entfernen.
            $created = $this->tasks->create($version, $stepIndex, $step + ['clarification' => $reasons], $old, $new, TargetSystem::Lexware, $execution->getAttribute('executed_by'));

            return StepResult::manualTask((int) $created['task']->getKey(), null, ['mode' => 'manual_clarification', 'reasons' => $reasons]);
        }

        $this->snapshot($client->credentials(), $fresh, 'before_change', $ref, $version);
        $payload = $this->rules->buildUpdatePayload($fresh, $new);
        $payload['version'] = (int) ($fresh['version'] ?? 0);

        try {
            $response = $client->updateContact($id, $payload);
        } catch (LexwareConflictException $e) {
            throw new ActionConflictException($e->getMessage(), 409, $e);
        } catch (LexwareTimeoutException $e) {
            throw new ActionTimeoutException($e->getMessage(), 0, $e);
        } catch (LexwareUnavailableException $e) {
            // 5xx oder keine verwertbare Statusangabe nach einem PUT: die Änderung kann serverseitig angewandt sein
            // (Gateway-Fehler). Ergebnis unklar, die Engine liest nach, statt den Schritt blind zu wiederholen.
            if ($e->httpStatus === null || $e->httpStatus >= 500) {
                throw new ActionTimeoutException(sprintf('Lexware antwortete auf PUT ohne verwertbares Ergebnis (%s). Ergebnis unklar, Nachlesen erforderlich.', $e->httpStatus !== null ? 'HTTP '.$e->httpStatus : 'kein Status'), 0, $e);
            }

            return StepResult::failed($e->getMessage(), $e->httpStatus);
        }

        return StepResult::executed(200, [
            'lexware_contact_id' => $id,
            'version_before' => (int) ($fresh['version'] ?? 0),
            'version_after' => isset($response['version']) ? (int) $response['version'] : null,
            'fields' => array_keys($new),
        ], ['method' => 'PUT', 'path' => sprintf(LexwareClient::PATH_CONTACT, $id), 'fields' => array_keys($new)]);
    }

    /**
     * Nachlesen per GET und Versionsvergleich (lexware_version_compare).
     *
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>, method: string}
     */
    public function verifyStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): array
    {
        $step = $version->steps()[$stepIndex] ?? [];
        $ref = (array) ($step['external_ref'] ?? []);
        $expected = (array) (($version->getAttribute('new_values') ?? [])[$stepIndex] ?? []);
        $old = (array) (($version->getAttribute('old_values') ?? [])[$stepIndex] ?? []);

        if (! isset($ref['external_id']) || ($step['mode'] ?? 'api') !== 'api') {
            return ['status' => VerificationStatus::Unverified->value, 'expected' => $expected, 'observed' => [], 'method' => 'manual_confirmation'];
        }

        $client = $this->clientFor($ref);
        $contact = $client->getContact((string) $ref['external_id']);

        if ($contact === null) {
            return ['status' => VerificationStatus::Failed->value, 'expected' => $expected, 'observed' => ['found' => false], 'method' => 'lexware_version_compare'];
        }

        $this->snapshot($client->credentials(), $contact, 'verification', $ref, $version);
        $flat = $this->rules->flatten($contact);
        $observed = array_intersect_key($flat, $expected) + array_fill_keys(array_keys($expected), null);
        $matches = $this->hasher->hash($expected) === $this->hasher->hash($observed);
        $unchanged = $this->hasher->hash($old) === $this->hasher->hash(array_intersect_key($flat, $old) + array_fill_keys(array_keys($old), null));
        $observed['version'] = $flat['version'];
        $observed['unchanged'] = $unchanged;

        $status = $matches ? VerificationStatus::ApiVerified : ($unchanged ? VerificationStatus::Unverified : VerificationStatus::Failed);

        return ['status' => $status->value, 'expected' => $expected, 'observed' => $observed, 'method' => 'lexware_version_compare'];
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function clientFor(array $ref): LexwareClient
    {
        try {
            return $this->clients->forOrganization(isset($ref['organization_id']) ? (int) $ref['organization_id'] : null, isset($ref['legal_entity_code']) ? (string) $ref['legal_entity_code'] : null);
        } catch (LexwareNotConfiguredException $e) {
            throw new LexwareUnavailableException($e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>  $ref
     */
    private function snapshot(LexwareCredentials $credentials, array $contact, string $purpose, array $ref, ?ActionPlanVersion $version = null): void
    {
        if ($credentials->connectionId === null) {
            return;
        }

        $masked = $contact;
        unset($masked['bankAccounts'], $masked['iban']);

        $snapshot = new LexwareContactSnapshot;
        $snapshot->forceFill([
            'organization_id' => $credentials->organizationId ?? $version?->plan?->getAttribute('organization_id'),
            'lexware_connection_id' => $credentials->connectionId,
            'case_id' => $version?->plan?->getAttribute('case_id'),
            'contact_id' => isset($ref['local_id']) ? (int) $ref['local_id'] : null,
            'lexware_contact_id' => (string) ($contact['id'] ?? $ref['external_id'] ?? ''),
            'lexware_version' => isset($contact['version']) ? (int) $contact['version'] : null,
            'snapshot_json' => $masked,
            'snapshot_hash' => $this->hasher->hash($masked),
            'purpose' => $purpose,
            'fetched_at' => CarbonImmutable::now(),
        ]);

        if ($snapshot->getAttribute('organization_id') !== null) {
            $snapshot->save();
        }
    }
}
