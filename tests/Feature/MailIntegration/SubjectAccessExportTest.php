<?php

declare(strict_types=1);

namespace Tests\Feature\MailIntegration;

use App\Core\Enums\Role;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\Task;
use App\Modules\Contacts\Models\Contact;
use App\Modules\MailIntegration\Jobs\SubjectAccessExportJob;
use App\Modules\MailIntegration\Services\SubjectAccessExportService;
use App\Modules\MailUi\Contracts\SubjectAccessExportInterface;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cases\CasesTestCase;
use Tests\Feature\MailUi\CreatesMailCases;

/**
 * Auskunftsexport (DSGVO Art. 15): Kontaktbezug über sender_contact_id und Adressen, Vorgänge über Hauptkontakt und
 * verknüpfte Nachrichten, Aufgaben und Pläne mit maskierten Bankdaten, IBAN-Muster im Text maskiert, nur mit Recht
 * mail.export, asynchron, auditiert.
 */
final class SubjectAccessExportTest extends CasesTestCase
{
    use CreatesMailCases;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_export_collects_related_data_and_masks_bank_data(): void
    {
        Storage::fake('local');
        $lead = $this->actingAsMailRole('lead');
        $mailbox = $this->mailbox;
        $organization = $mailbox->organization;
        $contact = Contact::factory()->for($organization)->create(['emails' => [['type' => 'work', 'value' => 'Mieter@Example.com']]]);
        $stranger = Contact::factory()->for($organization)->create(['emails' => [['type' => 'work', 'value' => 'fremd@example.com']]]);

        $byId = $this->inboundMessage($mailbox, ['from_address' => 'other@example.com', 'sender_contact_id' => $contact->getKey(), 'body_text' => 'Neue IBAN DE02 1203 0000 0000 2020 51 bitte verwenden.']);
        $byAddress = $this->inboundMessage($mailbox, ['from_address' => 'mieter@example.com', 'subject' => 'Frage']);
        $foreign = $this->inboundMessage($mailbox, ['from_address' => 'fremd@example.com', 'sender_contact_id' => $stranger->getKey(), 'subject' => 'Fremde Nachricht']);

        $linkedCase = $this->createCase($mailbox, ['title' => 'Bankdatenänderung']);
        CaseMessage::query()->create(['case_id' => $linkedCase->getKey(), 'message_id' => $byId->getKey(), 'link_type' => 'origin']);
        $primaryCase = $this->createCase($mailbox, ['title' => 'Hauptkontakt-Vorgang', 'primary_contact_id' => $contact->getKey()]);
        $foreignCase = $this->createCase($mailbox, ['title' => 'Fremder Vorgang', 'primary_contact_id' => $stranger->getKey()]);

        Task::query()->create(['organization_id' => $organization->getKey(), 'case_id' => $linkedCase->getKey(), 'task_type' => 'manual_change_lexware', 'title' => 'IBAN ändern', 'status' => 'open', 'old_value_json' => ['iban' => 'DE02120300000000202051'], 'new_value_json' => ['iban' => 'DE02500105170137075030']]);
        $author = User::factory()->role(Role::Operator)->for($organization)->create();
        $this->createBankPlan($linkedCase, $author);

        $data = $this->app->make(SubjectAccessExportService::class)->build($contact->fresh());

        $messageIds = array_column($data['messages'], 'id');
        $this->assertContains((int) $byId->getKey(), $messageIds);
        $this->assertContains((int) $byAddress->getKey(), $messageIds);
        $this->assertNotContains((int) $foreign->getKey(), $messageIds);

        $caseIds = array_column($data['cases'], 'id');
        $this->assertContains((int) $linkedCase->getKey(), $caseIds);
        $this->assertContains((int) $primaryCase->getKey(), $caseIds);
        $this->assertNotContains((int) $foreignCase->getKey(), $caseIds);

        $body = collect($data['messages'])->firstWhere('id', (int) $byId->getKey())['body_text'];
        $this->assertStringNotContainsString('2020 51', $body);
        $this->assertStringContainsString('(maskiert)', $body);

        $this->assertCount(1, $data['tasks']);
        $this->assertStringNotContainsString('DE02120300000000202051', $data['tasks'][0]['old_values']);
        $this->assertStringContainsString('(maskiert)', $data['tasks'][0]['new_values']);
        $this->assertCount(1, $data['plans']);
        $this->assertStringNotContainsString('DE02500105170137075030', $data['plans'][0]['new_values']);

        // Synchron über die Schnittstelle: Datei auf der Disk, Audit mit Anzahl und Pfad.
        $result = $this->app->make(SubjectAccessExportInterface::class)->request((int) $contact->getKey(), $lead, 'json', true);
        $this->assertTrue($result->isOk(), $result->message);

        $completed = AuditLog::query()->where('action', 'mail.export.subject_access_completed')->firstOrFail();
        $this->assertSame(2, (int) $completed->after_json['counts']['messages']);
        Storage::disk('local')->assertExists($completed->after_json['path'].'/auskunft.json');
        Storage::disk('local')->assertExists($completed->after_json['path'].'/manifest.json');
        $this->assertStringNotContainsString('DE02120300000000202051', (string) Storage::disk('local')->get($completed->after_json['path'].'/auskunft.json'));

        $csv = $this->app->make(SubjectAccessExportService::class)->write($contact->fresh(), 'csv-test', 'csv');
        Storage::disk('local')->assertExists($csv['path'].'/messages.csv');
        Storage::disk('local')->assertExists($csv['path'].'/kontakt.csv');
    }

    public function test_request_requires_permission_and_dispatches_job_asynchronously(): void
    {
        Bus::fake([SubjectAccessExportJob::class]);
        $agent = $this->actingAsMailRole('agent');
        $contact = Contact::factory()->for($this->mailbox->organization)->create();
        $exports = $this->app->make(SubjectAccessExportInterface::class);

        $denied = $exports->request((int) $contact->getKey(), $agent);
        $this->assertFalse($denied->isOk());
        $this->assertStringContainsString('mail.export', $denied->message);
        Bus::assertNotDispatched(SubjectAccessExportJob::class);

        $lead = User::factory()->role(Role::Operator)->for($this->mailbox->organization)->create();
        $this->attachMailRole($lead, $this->mailbox, 'lead');
        $ok = $exports->request((int) $contact->getKey(), $lead, 'csv');
        $this->assertTrue($ok->isOk(), $ok->message);
        Bus::assertDispatched(SubjectAccessExportJob::class, static fn (SubjectAccessExportJob $job): bool => $job->format === 'csv' && $job->queue === 'low');
        $this->assertSame(1, AuditLog::query()->where('action', 'mail.export.subject_access_requested')->count());

        $foreignContact = Contact::factory()->create();
        $this->assertFalse($exports->request((int) $foreignContact->getKey(), $lead)->isOk(), 'Kontakt fremder Organisation wird nicht exportiert.');
    }

    public function test_command_requires_user_and_admin_action_is_available(): void
    {
        Bus::fake([SubjectAccessExportJob::class]);
        $admin = $this->actingAsMailRole('admin');
        $contact = Contact::factory()->for($this->mailbox->organization)->create();

        $this->artisan('mail:subject-access-export', ['contact' => $contact->getKey()])->assertFailed();
        $this->artisan('mail:subject-access-export', ['contact' => $contact->getKey(), '--user' => $admin->getKey(), '--format' => 'csv'])->assertSuccessful();
        Bus::assertDispatchedTimes(SubjectAccessExportJob::class, 1);

        $this->get(self::BASE.'/admin/exports')->assertOk()->assertSee('Auskunftsexport (DSGVO Art. 15)');
        $this->post(self::BASE.'/admin/exports', ['contact_id' => $contact->getKey(), 'format' => 'json'])
            ->assertRedirect(self::BASE.'/admin/exports')
            ->assertSessionHas('status');
        Bus::assertDispatchedTimes(SubjectAccessExportJob::class, 2);
        $this->assertSame(2, AuditLog::query()->where('action', 'mail.export.subject_access_requested')->count());

        $this->get(self::BASE.'/admin/exports')->assertOk()->assertSee('Angefordert');
    }

    public function test_agent_cannot_reach_admin_export_page(): void
    {
        $this->actingAsMailRole('agent');
        $this->get(self::BASE.'/admin/exports')->assertForbidden();
    }
}
