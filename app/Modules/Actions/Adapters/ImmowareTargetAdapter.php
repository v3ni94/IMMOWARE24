<?php

declare(strict_types=1);

namespace App\Modules\Actions\Adapters;

use App\Modules\Actions\Contracts\StepAwareAdapterInterface;
use App\Modules\Actions\DTO\StepResult;
use App\Modules\Actions\Enums\RiskClass;
use App\Modules\Actions\Enums\TargetSystem;
use App\Modules\Actions\Enums\VerificationStatus;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Models\Execution;
use App\Modules\Actions\Services\ManualTaskFactory;
use App\Modules\Actions\Services\ManualTaskService;
use App\Modules\Actions\Support\DiffHasher;
use App\Modules\Actions\Support\IbanValidator;
use App\Modules\Connector\Services\CapabilityRegistry;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\BankAccount;
use App\Modules\Sync\Services\DataAgeService;
use Illuminate\Contracts\Config\Repository;

/**
 * Immoware24 über die vorhandenen Hub-Services: readCurrent liest Kontakt und Bankkonto aus dem Spiegel (mit
 * Datenalter), capabilities aus der CapabilityRegistry (contacts.write hard_locked, cases.write ohne Flag:
 * address_change und bank_change sind nicht verfügbar). Ausführung erzeugt deshalb immer eine manuelle Aufgabe
 * mit Alt/Neu plus proposed_change; Verifikation durch Nachlesen im Spiegel nach dem nächsten Sync, api_verified
 * nur bei tatsächlichem Wert-Match. Kein Schreibzugriff auf Immoware24 (CLAUDE.md Regel 2).
 */
final class ImmowareTargetAdapter implements StepAwareAdapterInterface
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly DataAgeService $dataAge,
        private readonly ManualTaskFactory $tasks,
        private readonly DiffHasher $hasher,
        private readonly IbanValidator $iban,
        private readonly Repository $config,
    ) {}

    public function targetSystem(): string
    {
        return TargetSystem::Immoware24->value;
    }

    /**
     * @return array<string, array{status: string, reason: ?string}>
     */
    public function capabilities(): array
    {
        $all = $this->capabilities->all();
        $contactsWrite = $all['contacts.write'] ?? ['available' => false, 'hard_locked' => true];
        $casesWrite = $all['cases.write'] ?? ['available' => false, 'hard_locked' => false];

        $writeReason = (bool) ($contactsWrite['hard_locked'] ?? true)
            ? 'contacts.write ist hard_locked; keine REST-API belegt. Manuelle Aufgabe mit Alt/Neu.'
            : 'contacts.write nicht freigegeben. Manuelle Aufgabe mit Alt/Neu.';

        return [
            'address_change' => ['status' => 'unavailable', 'reason' => $writeReason],
            'bank_change' => ['status' => 'unavailable', 'reason' => $writeReason],
            'note' => ['status' => (bool) ($casesWrite['available'] ?? false) ? 'restricted' : 'unavailable', 'reason' => 'cases.write ohne Zugangsweg (rest_api_slot WAITING_FOR_VENDOR_ACCESS). Notiz als manuelle Aufgabe.'],
            'manual_task' => ['status' => 'available', 'reason' => null],
        ];
    }

    /**
     * @param  array{reference_type: string, external_id: string, local_id?: ?int}  $ref
     * @return array<string, mixed>
     */
    public function readCurrent(array $ref): array
    {
        return match ($ref['reference_type']) {
            'contact' => $this->readContact($ref),
            'bank_account' => $this->readBankAccount($ref),
            default => [],
        };
    }

    /**
     * @param  object  $plan  App\Modules\Actions\Models\ActionPlan
     * @return array{steps: array<int, array<string, mixed>>, risk_class: string, approval_required: bool, warnings: array<int, string>}
     */
    public function prepareChange(object $plan): array
    {
        $version = method_exists($plan, 'getAttribute') ? $plan->currentVersion : null;
        $steps = $version instanceof ActionPlanVersion ? $version->steps() : [];
        $riskClass = $version instanceof ActionPlanVersion ? $version->getAttribute('risk_class') : null;
        $risk = $riskClass instanceof RiskClass ? $riskClass->value : 'medium';

        return ['steps' => $steps, 'risk_class' => $risk, 'approval_required' => $risk !== 'low', 'warnings' => ['Immoware24 ist nicht schreibbar; alle Schritte werden manuelle Aufgaben.']];
    }

    /**
     * @param  object  $planVersion  App\Modules\Actions\Models\ActionPlanVersion
     * @param  object  $approval  App\Modules\Actions\Models\Approval
     * @return array<int, array{step_index: int, status: string, response_status: ?int, execution_uuid: string}>
     */
    public function executeApprovedChange(object $planVersion, object $approval): array
    {
        // Die Engine ruft executeStep je Schritt (ExecutionService). Direkte Nutzung des Core-Vertrags ist nicht vorgesehen.
        return [];
    }

    /**
     * @param  object  $execution  App\Modules\Actions\Models\Execution
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>}
     */
    public function verifyChange(object $execution): array
    {
        if (! $execution instanceof Execution) {
            return ['status' => VerificationStatus::Unverified->value, 'expected' => [], 'observed' => []];
        }

        $version = $execution->version;

        if ($version === null) {
            return ['status' => VerificationStatus::Unverified->value, 'expected' => [], 'observed' => []];
        }

        $result = $this->verifyStep($version, (int) $execution->getAttribute('step_index'), $execution);

        return ['status' => $result['status'], 'expected' => $result['expected'], 'observed' => $result['observed']];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<int, string>
     */
    public function validateStep(array $step): array
    {
        $type = (string) ($step['action_type'] ?? '');
        $capability = $this->capabilities()[$type] ?? ['status' => 'unavailable', 'reason' => null];
        $warnings = [];

        if ($capability['status'] !== 'available') {
            $warnings[] = sprintf('Immoware24 %s: %s', $type, (string) ($capability['reason'] ?? 'nicht verfügbar'));
        }

        return $warnings;
    }

    public function executeStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): StepResult
    {
        $step = $version->steps()[$stepIndex] ?? [];
        $old = (array) (($version->getAttribute('old_values') ?? [])[$stepIndex] ?? []);
        $new = (array) (($version->getAttribute('new_values') ?? [])[$stepIndex] ?? []);

        // Keine Schreibfähigkeit (capabilities()): manuelle Aufgabe mit Alt/Neu und proposed_change-Rückweg.
        $created = $this->tasks->create($version, $stepIndex, $step, $old, $new, TargetSystem::Immoware24, $execution->getAttribute('executed_by'));

        return StepResult::manualTask((int) $created['task']->getKey(), $created['proposed_change']?->getKey() !== null ? (int) $created['proposed_change']->getKey() : null, [
            'mode' => 'manual_task',
            'capability' => $this->capabilities()[(string) ($step['action_type'] ?? 'manual_task')]['status'] ?? 'unavailable',
        ]);
    }

    /**
     * @return array{status: string, expected: array<string, mixed>, observed: array<string, mixed>, method: string}
     */
    public function verifyStep(ActionPlanVersion $version, int $stepIndex, Execution $execution): array
    {
        $step = $version->steps()[$stepIndex] ?? [];
        $expected = (array) (($version->getAttribute('new_values') ?? [])[$stepIndex] ?? []);
        $ref = (array) ($step['external_ref'] ?? []);
        $task = $execution->task;
        $manuallyConfirmed = $task !== null && in_array($task->getAttribute('status'), [ManualTaskService::TASK_DONE_MANUAL, ManualTaskService::TASK_DONE_VERIFIED], true);

        if (! isset($ref['reference_type'], $ref['external_id'])) {
            return ['status' => $manuallyConfirmed ? VerificationStatus::ManuallyConfirmed->value : VerificationStatus::Unverified->value, 'expected' => $expected, 'observed' => [], 'method' => 'manual_confirmation'];
        }

        $current = $this->readCurrent(['reference_type' => (string) $ref['reference_type'], 'external_id' => (string) $ref['external_id'], 'local_id' => isset($ref['local_id']) ? (int) $ref['local_id'] : null] + $ref);
        $observed = [];

        foreach (array_keys($expected) as $field) {
            $observed[$field] = $current[$field] ?? null;
        }

        $old = (array) (($version->getAttribute('old_values') ?? [])[$stepIndex] ?? []);
        $matches = $expected !== [] && $this->hasher->hash($this->normalize($expected)) === $this->hasher->hash($this->normalize($observed));
        $observed['data_age'] = $current['data_age'] ?? null;
        // unchanged: Spiegel zeigt noch den Ausgangszustand (relevant für Wiederholungsentscheidungen der Engine).
        $observed['unchanged'] = $this->hasher->hash($this->normalize($old)) === $this->hasher->hash($this->normalize(array_intersect_key($observed, $old)));

        // api_verified nur bei tatsächlichem Wert-Match im Spiegel (nach dem nächsten Sync). Sonst bleibt es bei
        // der manuellen Bestätigung oder unverifiziert.
        $status = $matches ? VerificationStatus::ApiVerified : ($manuallyConfirmed ? VerificationStatus::ManuallyConfirmed : VerificationStatus::Unverified);

        return ['status' => $status->value, 'expected' => $expected, 'observed' => $observed, 'method' => $matches ? 'reread_get' : 'manual_confirmation'];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function readContact(array $ref): array
    {
        $contact = $this->findContact($ref);

        if ($contact === null) {
            return ['found' => false];
        }

        $addresses = (array) ($contact->getAttribute('addresses') ?? []);
        $primary = $this->primaryAddress($addresses);

        return [
            'found' => true,
            'local_id' => (int) $contact->getKey(),
            'external_id' => (string) $contact->getAttribute('external_id'),
            'street' => $primary['street'] ?? null,
            'postal_code' => $primary['postal_code'] ?? null,
            'city' => $primary['city'] ?? null,
            'country' => $primary['country'] ?? null,
            'address_count' => count($addresses),
            'version' => (int) $contact->getAttribute('sync_version'),
            'last_synced_at' => $contact->getAttribute('last_synced_at')?->toIso8601String(),
            'data_age' => $this->dataAgeFor($contact->getAttribute('connection_id'), 'contact'),
        ];
    }

    /**
     * @param  array<string, mixed>  $ref
     * @return array<string, mixed>
     */
    private function readBankAccount(array $ref): array
    {
        $query = BankAccount::query()->withoutGlobalScope('organization');
        $account = isset($ref['local_id']) && (int) $ref['local_id'] > 0
            ? $query->find((int) $ref['local_id'])
            : $query->where('external_id', (string) $ref['external_id'])->first();

        if (! $account instanceof BankAccount) {
            return ['found' => false];
        }

        $iban = $account->getAttribute('iban');

        return [
            'found' => true,
            'local_id' => (int) $account->getKey(),
            'external_id' => (string) $account->getAttribute('external_id'),
            'iban' => is_string($iban) ? $this->iban->normalize($iban) : null,
            'iban_masked' => is_string($iban) ? $this->iban->mask($iban) : null,
            'iban_hash' => (string) $account->getAttribute('iban_hash'),
            'bic' => $account->getAttribute('bic'),
            'account_holder' => $account->getAttribute('account_holder'),
            'version' => (int) $account->getAttribute('sync_version'),
            'data_age' => $this->dataAgeFor($account->getAttribute('connection_id'), 'contract'),
        ];
    }

    /**
     * @param  array<string, mixed>  $ref
     */
    private function findContact(array $ref): ?Contact
    {
        $query = Contact::query()->withoutGlobalScope('organization')->whereNull('merged_into_id');

        // Muster des Repositories (RunSyncJob): Eloquent-Generics sind für PHPStan ohne Larastan nicht auflösbar.
        /** @var Contact|null $contact */
        $contact = isset($ref['local_id']) && (int) $ref['local_id'] > 0
            ? $query->find((int) $ref['local_id'])
            : $query->where('external_id', (string) $ref['external_id'])->first();

        return $contact;
    }

    /**
     * @param  array<int, array<string, mixed>>  $addresses
     * @return array<string, mixed>
     */
    private function primaryAddress(array $addresses): array
    {
        foreach (['work', 'home'] as $type) {
            foreach ($addresses as $address) {
                if (is_array($address) && ($address['type'] ?? null) === $type) {
                    return $address;
                }
            }
        }

        $first = $addresses[0] ?? [];

        return is_array($first) ? $first : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dataAgeFor(mixed $connectionId, string $entityType): ?array
    {
        if ($connectionId === null) {
            return null;
        }

        $age = $this->dataAge->for((int) $connectionId, $entityType);
        $max = (int) $this->config->get('hub.actions.immoware_max_data_age_seconds', 86400);
        $age['stale'] = $age['stale'] || ($age['age_seconds'] !== null && $age['age_seconds'] > $max);

        return $age;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function normalize(array $values): array
    {
        foreach ($values as $key => $value) {
            if ($key === 'iban' && is_string($value)) {
                $values[$key] = $this->iban->normalize($value);
            } elseif (is_string($value)) {
                $values[$key] = trim($value);
            }
        }

        return $values;
    }
}
