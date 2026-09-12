<?php

declare(strict_types=1);

namespace Tests\Feature\MailIntegration;

use App\Modules\Ai\Models\AiRun;
use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Models\CaseMessage;
use App\Modules\Cases\Models\MailCase;
use App\Modules\Gmail\Models\MailAttachment;
use App\Modules\Gmail\Models\MailMessage;
use App\Modules\Gmail\Models\PushEvent;
use App\Modules\MailIntegration\Services\RetentionService;
use App\Modules\MailUi\Services\OrgSettings;
use App\Modules\Security\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Cases\CasesTestCase;
use Tests\Feature\MailUi\CreatesMailCases;

/**
 * mail:retention:apply: Vorschau zählt nur, Anwendung entfernt Inhalte und setzt deleted_at, Legal Hold und offene
 * Vorgänge sperren, Fristen aus Config mit Organisationsüberschreibung, jeder Lauf auditiert.
 */
final class RetentionApplyTest extends CasesTestCase
{
    use CreatesMailCases;

    public function test_dry_run_counts_only_and_apply_removes_contents_except_legal_hold(): void
    {
        Storage::fake('local');
        config()->set('hub.mail.retention.messages_days', 365);
        config()->set('hub.mail.retention.attachments_days', 365);
        config()->set('hub.mail.retention.ai_runs_days', 30);
        config()->set('hub.mail.retention.closed_cases_days', 365);
        config()->set('hub.mail.retention.push_events_days', 30);

        $this->actingAsMailRole('admin');
        $mailbox = $this->mailbox;
        $organizationId = (int) $mailbox->organization_id;
        $old = CarbonImmutable::now()->subDays(400);

        // Alte Nachricht ohne Vorgang: Text wird entfernt, Anhang gelöscht.
        $free = $this->inboundMessage($mailbox, ['received_at' => $old, 'body_text' => 'Alter Text']);
        Storage::disk('local')->put('mail/att/free.pdf', 'inhalt');
        $freeAttachment = MailAttachment::query()->create(['organization_id' => $organizationId, 'message_id' => $free->getKey(), 'filename' => 'free.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 6, 'storage_disk' => 'local', 'storage_path' => 'mail/att/free.pdf', 'scan_status' => 'clean']);

        // Alte Nachricht in geschlossenem Vorgang mit Legal Hold: alles bleibt.
        $held = $this->inboundMessage($mailbox, ['received_at' => $old, 'body_text' => 'Gesperrter Text']);
        $heldCase = $this->createCase($mailbox, ['status_processing' => CaseStatus::Closed, 'closed_at' => $old, 'legal_hold_at' => now(), 'legal_hold_reason' => 'Rechtsstreit']);
        CaseMessage::query()->create(['case_id' => $heldCase->getKey(), 'message_id' => $held->getKey(), 'link_type' => 'origin']);
        $heldAttachment = MailAttachment::query()->create(['organization_id' => $organizationId, 'message_id' => $held->getKey(), 'filename' => 'held.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 6, 'storage_disk' => 'local', 'storage_path' => 'mail/att/held.pdf', 'scan_status' => 'clean']);
        Storage::disk('local')->put('mail/att/held.pdf', 'inhalt');
        AiRun::query()->create(['organization_id' => $organizationId, 'case_id' => $heldCase->getKey(), 'task' => 'classify_case', 'input_hash' => str_repeat('a', 64), 'schema_hash' => str_repeat('b', 64), 'status' => 'succeeded', 'created_at' => $old]);

        // Alte Nachricht in offenem Vorgang: bleibt.
        $openMessage = $this->inboundMessage($mailbox, ['received_at' => $old, 'body_text' => 'Offener Text']);
        $openCase = $this->createCase($mailbox, ['status_processing' => CaseStatus::InProgress]);
        CaseMessage::query()->create(['case_id' => $openCase->getKey(), 'message_id' => $openMessage->getKey(), 'link_type' => 'origin']);

        // Alter geschlossener Vorgang ohne Sperre: Soft Delete. Junger geschlossener Vorgang bleibt.
        $closedOld = $this->createCase($mailbox, ['status_processing' => CaseStatus::Closed, 'closed_at' => $old]);
        $closedYoung = $this->createCase($mailbox, ['status_processing' => CaseStatus::Closed, 'closed_at' => now()->subDays(10)]);

        AiRun::query()->create(['organization_id' => $organizationId, 'case_id' => null, 'task' => 'summarize', 'input_hash' => str_repeat('c', 64), 'schema_hash' => str_repeat('d', 64), 'status' => 'succeeded', 'created_at' => $old]);
        PushEvent::query()->create(['mailbox_id' => $mailbox->getKey(), 'pubsub_message_id' => 'p-old', 'email_address' => $mailbox->email_address, 'history_id' => '1', 'received_at' => $old, 'auth_result' => 'ok']);
        PushEvent::query()->create(['mailbox_id' => $mailbox->getKey(), 'pubsub_message_id' => 'p-new', 'email_address' => $mailbox->email_address, 'history_id' => '2', 'received_at' => now(), 'auth_result' => 'ok']);

        $this->artisan('mail:retention:apply', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('Alter Text', $free->fresh()->body_text, 'Vorschau ändert nichts.');
        $this->assertNull(MailCase::query()->find($closedOld->getKey())?->deleted_at);
        $this->assertSame(2, PushEvent::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'mail.retention.dry_run')->count(), 'Ein Eintrag je Organisation plus Push-Ereignisse.');

        $preview = $this->app->make(RetentionService::class)->apply($organizationId, true);
        $this->assertSame(1, $preview['messages']['candidates']);
        $this->assertSame(2, $preview['messages']['held']);
        $this->assertSame(1, $preview['closed_cases']['candidates']);
        $this->assertSame(1, $preview['closed_cases']['held']);

        $this->artisan('mail:retention:apply', ['--apply' => true])->assertSuccessful();

        $this->assertNull($free->fresh()->body_text);
        $this->assertSame('Gesperrter Text', $held->fresh()->body_text);
        $this->assertSame('Offener Text', $openMessage->fresh()->body_text);
        $this->assertNotNull(MailMessage::query()->find($free->getKey()), 'Nachrichtenzeile bleibt (kein Hard Delete).');

        Storage::disk('local')->assertMissing('mail/att/free.pdf');
        Storage::disk('local')->assertExists('mail/att/held.pdf');
        $this->assertNotNull(MailAttachment::query()->withTrashed()->find($freeAttachment->getKey())?->deleted_at);
        $this->assertNull(MailAttachment::query()->find($heldAttachment->getKey())?->deleted_at);

        $this->assertNotNull(MailCase::query()->withTrashed()->find($closedOld->getKey())?->deleted_at);
        $this->assertNull(MailCase::query()->find($closedYoung->getKey())?->deleted_at);
        $this->assertNull(MailCase::query()->find($heldCase->getKey())?->deleted_at);

        $this->assertSame(1, AiRun::query()->count(), 'Nur der KI-Lauf des gesperrten Vorgangs bleibt.');
        $this->assertSame(1, PushEvent::query()->count());
        $this->assertSame(1, PushEvent::query()->where('pubsub_message_id', 'p-new')->count());

        $audit = AuditLog::query()->where('action', 'mail.retention.applied')->whereNotNull('after_json->organization_id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(1, (int) ($audit->after_json['rules']['messages']['applied'] ?? -1));
    }

    public function test_organization_setting_overrides_config_days_and_minimum_is_enforced(): void
    {
        $this->actingAsMailRole('admin');
        $organizationId = (int) $this->mailbox->organization_id;
        config()->set('hub.mail.retention.messages_days', 3650);
        config()->set('hub.mail.retention.ai_runs_days', 1);

        $this->app->make(OrgSettings::class)->put($organizationId, OrgSettings::RETENTION, ['messages_days' => 90]);
        $days = $this->app->make(RetentionService::class)->daysFor($organizationId);

        $this->assertSame(90, $days['messages']);
        $this->assertSame(7, $days['ai_runs'], 'Mindestfrist 7 Tage.');
        $this->assertSame(3650, $days['closed_cases']);
    }

    public function test_schedule_is_registered_only_when_enabled(): void
    {
        $this->assertFalse((bool) config('hub.mail.retention.enabled'));
        $this->artisan('schedule:list')->assertSuccessful()->doesntExpectOutputToContain('mail:retention:apply');
    }
}
