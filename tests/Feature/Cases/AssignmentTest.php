<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Exceptions\AssignmentOpenException;
use App\Modules\Cases\Models\AssignmentDecision;
use App\Modules\Cases\Models\AssignmentRule;
use App\Modules\Cases\Services\AssignmentService;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\TaskService;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Contacts\Models\ContactIdentifier;
use App\Modules\Estate\Models\Property;
use Illuminate\Support\Facades\Queue;

/**
 * Abnahmefall 5: gleichnamige Kontakte führen zu assignment_open; Zuordnung nie über Namen, nur über Kennungen,
 * bestätigte Regeln, externe IDs und Objektbezug innerhalb der Organisation.
 */
final class AssignmentTest extends CasesTestCase
{
    public function test_two_contacts_sharing_the_sender_address_lead_to_assignment_open_and_block_sensitive_tasks(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $organizationId = (int) $this->mailbox->organization_id;

        $first = Contact::factory()->create(['organization_id' => $organizationId, 'first_name' => 'Anna', 'last_name' => 'Schmidt', 'emails' => [['type' => 'home', 'value' => 'schmidt@example.com']]]);
        $second = Contact::factory()->create(['organization_id' => $organizationId, 'first_name' => 'Anna', 'last_name' => 'Schmidt', 'emails' => [['type' => 'home', 'value' => 'schmidt@example.com']]]);
        ContactIdentifier::query()->create(['contact_id' => $first->getKey(), 'kind' => 'email', 'value_normalized' => 'schmidt@example.com']);
        ContactIdentifier::query()->create(['contact_id' => $second->getKey(), 'kind' => 'email', 'value_normalized' => 'schmidt@example.com']);
        // Gleiche Adresse in fremder Organisation darf nie erscheinen.
        $foreign = Contact::factory()->create(['first_name' => 'Anna', 'last_name' => 'Schmidt']);
        ContactIdentifier::query()->create(['contact_id' => $foreign->getKey(), 'kind' => 'email', 'value_normalized' => 'schmidt@example.com']);

        $message = $this->inboundMessage($this->mailbox, ['from_address' => 'Schmidt@Example.com', 'subject' => 'Bankverbindung', 'body_text' => 'Neue IBAN anbei.']);
        $candidates = $this->app->make(AssignmentService::class)->candidates($message);
        $contactIds = array_map(static fn ($c) => $c->localId, array_filter($candidates, static fn ($c) => $c->type === 'contact'));
        $this->assertEqualsCanonicalizing([$first->getKey(), $second->getKey()], $contactIds);
        $this->assertNotContains($foreign->getKey(), $contactIds);

        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($message, [['item_type' => 'bankdaten', 'title' => 'Bankdaten', 'assignee_user_id' => $user->getKey()]], $user);

        $this->assertSame(CaseStatus::AssignmentOpen, $case->status_processing);
        $this->assertNull($case->primary_contact_id);

        try {
            $this->app->make(TaskService::class)->create($case, $case->items()->firstOrFail(), ['task_type' => 'manual_change_immoware', 'title' => 'IBAN ändern'], $user);
            $this->fail('Sensible Änderung bei offener Zuordnung muss gesperrt sein.');
        } catch (AssignmentOpenException) {
            $this->addToAssertionCount(1);
        }

        // Manuelle Bestätigung: Entscheidung, Regel, Status open.
        $decision = $this->app->make(AssignmentService::class)->confirm($case, 'contact', (int) $second->getKey(), $user, $message);
        $this->assertSame('manual', $decision->decision_basis);
        $this->assertSame(CaseStatus::Open, $case->refresh()->status_processing);
        $this->assertSame((int) $second->getKey(), (int) $case->primary_contact_id);
        $rule = AssignmentRule::query()->where('match_value', 'schmidt@example.com')->where('active', true)->firstOrFail();
        $this->assertSame((int) $second->getKey(), (int) $rule->target_local_id);

        // Korrektur: neue Entscheidung, alte Regel deaktiviert.
        $this->app->make(AssignmentService::class)->confirm($case, 'contact', (int) $first->getKey(), $user, $message);
        $this->assertFalse((bool) $rule->refresh()->active);
        $this->assertSame(2, AssignmentDecision::query()->where('case_id', $case->getKey())->where('decision_basis', 'manual')->count());

        // Nächste Nachricht desselben Absenders: Regel greift, automatische Zuordnung ohne Mehrdeutigkeit.
        $next = $this->inboundMessage($this->mailbox, ['from_address' => 'schmidt@example.com', 'subject' => 'Nachfrage']);
        $nextCase = $cases->openFromMessage($next, [['item_type' => 'anfrage_allgemein', 'title' => 'Nachfrage', 'assignee_user_id' => $user->getKey()]], $user);
        $this->assertSame(CaseStatus::Open, $nextCase->status_processing);
        $this->assertSame((int) $first->getKey(), (int) $nextCase->primary_contact_id);
        $this->assertSame('rule', AssignmentDecision::query()->where('case_id', $nextCase->getKey())->value('decision_basis'));
    }

    public function test_unique_identifier_assigns_contact_and_object_number_or_address_assigns_property(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $organizationId = (int) $this->mailbox->organization_id;
        $contact = Contact::factory()->create(['organization_id' => $organizationId]);
        ContactIdentifier::query()->create(['contact_id' => $contact->getKey(), 'kind' => 'email', 'value_normalized' => 'eindeutig@example.com']);
        $property = Property::factory()->create(['organization_id' => $organizationId, 'immoware_object_number' => 'OBJ-4711', 'street' => 'Musterstraße', 'house_number' => '12']);
        Property::factory()->create(['organization_id' => $organizationId, 'immoware_object_number' => 'OBJ-9999', 'street' => 'Musterstraße', 'house_number' => '14']);

        $message = $this->inboundMessage($this->mailbox, ['from_address' => 'eindeutig@example.com', 'body_text' => 'Betrifft Objekt OBJ-4711, Musterstraße 12, Treppenhauslicht defekt.']);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'schaden', 'title' => 'Licht', 'assignee_user_id' => $user->getKey()]], $user);

        $this->assertSame(CaseStatus::Open, $case->status_processing);
        $this->assertSame((int) $contact->getKey(), (int) $case->primary_contact_id);
        $this->assertSame((int) $property->getKey(), (int) $case->property_id);
        $this->assertSame('identifier', AssignmentDecision::query()->where('case_id', $case->getKey())->where('decision_type', 'contact')->value('decision_basis'));

        $candidates = $this->app->make(AssignmentService::class)->candidates($message);
        $propertyCandidate = array_values(array_filter($candidates, static fn ($c) => $c->type === 'property' && $c->localId === (int) $property->getKey()))[0];
        $this->assertSame('external_id_in_text', $propertyCandidate->source);
        $this->assertSame(85, $propertyCandidate->confidence);
        $this->assertStringContainsString('OBJ-4711', $propertyCandidate->reason);
    }

    public function test_confirm_rejects_contacts_of_other_organizations(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'sonstiges', 'title' => 'x', 'assignee_user_id' => $user->getKey()]], $user);
        $foreign = Contact::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(AssignmentService::class)->confirm($case, 'contact', (int) $foreign->getKey(), $user, $message);
    }
}
