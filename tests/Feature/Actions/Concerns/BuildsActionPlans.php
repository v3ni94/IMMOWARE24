<?php

declare(strict_types=1);

namespace Tests\Feature\Actions\Concerns;

use App\Core\Enums\Role;
use App\Modules\Actions\Models\ActionPlanVersion;
use App\Modules\Actions\Services\ActionPlanService;
use App\Modules\Actions\Services\ApprovalService;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\CommunicationStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Connector\Models\Organization;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\BankAccount;
use App\Modules\Lexware\Models\LexwareConnection;
use App\Modules\Lexware\Testing\FakeLexwareApi;
use App\Modules\Mail\Models\Mailbox;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;

/**
 * Gemeinsame Fixtures der Action-Engine-Tests: Postfach, Vorgang, Nutzer mit Team-Rollen, Lexware-Fake, Spiegeldaten.
 */
trait BuildsActionPlans
{
    protected Mailbox $box;

    protected User $author;

    protected User $approverOne;

    protected User $approverTwo;

    protected MailCase $case;

    protected FakeLexwareApi $lexware;

    protected function setUpActionFixtures(): void
    {
        $this->author = $this->actingAsMailRole('agent');
        $this->box = $this->mailbox ?? $this->createMailbox($this->author, 'agent');
        $organization = Organization::query()->findOrFail($this->box->organization_id);

        $this->approverOne = User::factory()->role(Role::Operator)->for($organization)->create();
        $this->approverTwo = User::factory()->role(Role::Operator)->for($organization)->create();
        $this->attachMailRole($this->approverOne, $this->box, 'approver');
        $this->attachMailRole($this->approverTwo, $this->box, 'approver');

        $this->case = MailCase::query()->create([
            'organization_id' => $this->box->organization_id,
            'case_number' => 'V-2026-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'mailbox_id' => $this->box->getKey(),
            'team_id' => $this->box->team_id,
            'title' => 'Stammdatenänderung',
            'case_type' => 'stammdaten',
            'priority' => Priority::P2,
            'status_processing' => CaseStatus::Open,
            'status_communication' => CommunicationStatus::Acknowledged,
            'assignee_user_id' => $this->author->getKey(),
            'next_step' => 'Änderung prüfen',
            'due_at' => now()->addDay(),
            'opened_at' => now(),
            'legal_entity_code' => 'HVM',
        ]);

        config()->set('hub.mail.flags.lexware_write', true);
        config()->set('hub.lexware.rate_limit_rps', 1000);
        config()->set('hub.actions.jobs.verify_delay_seconds', 0);

        $this->lexware = new FakeLexwareApi;
        $this->lexware->install();

        LexwareConnection::query()->create([
            'organization_id' => $this->box->organization_id,
            'label' => 'HVM Lexware',
            'legal_entity_code' => 'HVM',
            'base_url' => 'https://api.lexoffice.io/v1',
            'api_key' => 'test-key-not-secret',
            'api_key_fingerprint' => 'abcd',
            'status' => 'configured',
            'write_enabled' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function seedLexwareContact(string $id = 'lx-1', array $overrides = [], int $version = 3): void
    {
        $this->lexware->seedContact($id, array_replace_recursive([
            'roles' => ['customer' => ['number' => 10007]],
            'person' => ['firstName' => 'Erika', 'lastName' => 'Muster'],
            'addresses' => ['billing' => [['street' => 'Altstraße 1', 'zip' => '40721', 'city' => 'Hilden', 'countryCode' => 'DE']]],
        ], $overrides), $version);
    }

    /**
     * @return array<string, mixed>
     */
    protected function lexwareAddressStep(string $id = 'lx-1', string $street = 'Neustraße 2'): array
    {
        return [
            'target_system' => 'lexware',
            'action_type' => 'address_change',
            'external_ref' => ['reference_type' => 'contact', 'external_id' => $id, 'organization_id' => $this->box->organization_id, 'legal_entity_code' => 'HVM'],
            'new_values' => ['street' => $street],
        ];
    }

    protected function seedMirrorContact(): Contact
    {
        return Contact::factory()->create([
            'organization_id' => $this->box->organization_id,
            'addresses' => [['type' => 'home', 'street' => 'Altstraße 1', 'postal_code' => '40721', 'city' => 'Hilden', 'region' => '', 'country' => 'DE']],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function immowareAddressStep(Contact $contact, string $street = 'Neustraße 2'): array
    {
        return [
            'target_system' => 'immoware24',
            'action_type' => 'address_change',
            'external_ref' => ['reference_type' => 'contact', 'external_id' => (string) $contact->external_id, 'local_id' => (int) $contact->getKey()],
            'new_values' => ['street' => $street],
        ];
    }

    protected function seedBankAccount(string $iban = 'DE89370400440532013000'): BankAccount
    {
        $account = new BankAccount;
        $account->forceFill([
            'organization_id' => $this->box->organization_id,
            'owner_type' => 'contact',
            'owner_id' => 1,
            'iban' => $iban,
            'iban_masked' => BankAccount::maskIban($iban),
            'account_holder' => 'Erika Muster',
            'source_system' => 'immoware24',
            'external_id' => 'ba-1',
            'first_synced_at' => now(),
            'last_synced_at' => now(),
        ]);
        $account->save();

        return $account;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bankChangeStep(BankAccount $account, string $newIban = 'DE02120300000000202051', bool $withAssignment = true): array
    {
        $ref = ['reference_type' => 'bank_account', 'external_id' => (string) $account->external_id, 'local_id' => (int) $account->getKey()];

        if ($withAssignment) {
            $ref += ['contract_id' => 4711, 'role' => 'tenant'];
        }

        return ['target_system' => 'immoware24', 'action_type' => 'bank_change', 'external_ref' => $ref, 'new_values' => ['iban' => $newIban]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    protected function propose(array $steps, ?CarbonImmutable $effectiveDate = null): ActionPlanVersion
    {
        return $this->app->make(ActionPlanService::class)->propose($this->case, $steps, $this->author, null, null, $effectiveDate);
    }

    protected function approvals(): ApprovalService
    {
        return $this->app->make(ApprovalService::class);
    }

    protected function approveBy(ActionPlanVersion $version, User $user): void
    {
        $this->approvals()->approve($version->fresh(['plan']) ?? $version, $user, 'geprüft', CarbonImmutable::now());
    }
}
