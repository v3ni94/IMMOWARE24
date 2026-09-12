<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Modules\Cases\Models\Task;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Mail\Models\Mailbox;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MailMigrationsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const array TABLES = [
        'mail_teams', 'mail_team_members', 'mail_mailboxes', 'mail_mailbox_aliases', 'mail_mailbox_permissions', 'mail_sync_states',
        'mail_threads', 'mail_messages', 'mail_message_parts', 'mail_attachments', 'mail_push_events', 'mail_drafts', 'mail_send_reconciliations',
        'mail_cases', 'mail_case_items', 'mail_case_messages', 'mail_case_references', 'mail_assignment_decisions', 'mail_tasks',
        'mail_work_calendars', 'mail_holidays', 'mail_sla_rules', 'mail_sla_clocks', 'mail_deadlines', 'mail_emergency_alerts', 'mail_escalation_steps',
        'mail_action_plans', 'mail_action_plan_versions', 'mail_approvals', 'mail_executions', 'mail_verifications', 'mail_outbox',
        'mail_lexware_connections', 'mail_lexware_contact_snapshots', 'mail_ai_runs', 'mail_ai_suggestions',
        'mail_drive_connections', 'mail_document_references',
    ];

    public function test_all_mail_tables_exist_and_legacy_cases_table_is_untouched(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Tabelle %s fehlt.', $table));
        }

        $this->assertCount(38, self::TABLES);
        $this->assertTrue(Schema::hasTable('cases'));
        $this->assertTrue(Schema::hasColumns('cases', ['immoware_ticket_reference', 'source_system']));
    }

    public function test_messages_are_unique_per_mailbox_and_gmail_id(): void
    {
        $mailbox = $this->createMailbox();
        $attributes = [
            'organization_id' => $mailbox->organization_id,
            'mailbox_id' => $mailbox->getKey(),
            'gmail_message_id' => 'abc123',
            'from_address' => 'a@example.com',
            'received_at' => now(),
            'imported_at' => now(),
            'checksum' => str_repeat('a', 64),
        ];

        MailMessage::query()->create($attributes);

        $this->expectException(QueryException::class);
        MailMessage::query()->create($attributes);
    }

    public function test_mailbox_tokens_and_task_values_are_stored_encrypted(): void
    {
        $mailbox = $this->createMailbox(attributes: ['oauth_refresh_token' => 'refresh-secret']);
        $mailbox->refresh();

        $this->assertSame('refresh-secret', $mailbox->oauth_refresh_token);
        $this->assertNotSame('refresh-secret', $mailbox->getRawOriginal('oauth_refresh_token'));
        $this->assertStringNotContainsString('refresh-secret', (string) $mailbox->getRawOriginal('oauth_refresh_token'));

        $task = Task::query()->create([
            'organization_id' => $mailbox->organization_id,
            'title' => 'Bankdaten ändern',
            'task_type' => 'manual_change_immoware',
            'old_value_json' => ['iban' => 'DE02120300000000202051'],
            'new_value_json' => ['iban' => 'DE02500105170137075030'],
        ]);
        $task->refresh();

        $this->assertSame('DE02500105170137075030', $task->new_value_json['iban']);
        $this->assertStringNotContainsString('DE02500105170137075030', (string) $task->getRawOriginal('new_value_json'));
    }

    public function test_mailbox_defaults_to_not_configured(): void
    {
        $mailbox = Mailbox::query()->create([
            'organization_id' => $this->createOrganization()->getKey(),
            'label' => 'Neu',
            'email_address' => 'neu@example.com',
            'legal_entity_code' => 'HVM',
        ]);

        $this->assertSame('not_configured', $mailbox->refresh()->status);
    }
}
