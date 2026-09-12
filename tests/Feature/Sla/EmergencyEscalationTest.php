<?php

declare(strict_types=1);

namespace Tests\Feature\Sla;

use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Jobs\EmergencyAlertJob;
use App\Modules\Sla\Jobs\SlaCheckJob;
use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Models\EscalationStep;
use App\Modules\Sla\Services\EmergencyQueue;
use App\Modules\Sla\Services\SlaClockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Cases\CasesTestCase;

/**
 * Abnahmefall 14: Notfall eskaliert unabhängig vom Massenimport über die Queue mail-high; Zustellung und menschliche
 * Annahme sind getrennt protokolliert; sms und call sind nur Stubs ohne Scheinversand.
 */
final class EmergencyEscalationTest extends CasesTestCase
{
    public function test_emergency_alert_is_queued_on_mail_high_delivered_and_escalated_until_acknowledged(): void
    {
        Queue::fake();
        Mail::fake();
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));
        $agent = $this->actingAsMailRole('agent');
        $lead = User::factory()->for($agent->organization)->create(['email' => 'leitung@example.com']);
        $this->mailbox->team->forceFill(['lead_user_id' => $lead->getKey()])->save();

        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Wasser tritt aus', 'body_text' => 'Im Keller tritt Wasser aus der Wand!']);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'schaden_notfall', 'title' => 'Wassereintritt', 'assignee_user_id' => $agent->getKey()]], $agent);

        $alert = EmergencyAlert::query()->where('case_id', $case->getKey())->firstOrFail();
        $this->assertSame('open', $alert->status);
        $this->assertFalse((bool) $alert->on_call_configured, 'Ohne Bereitschaft sichtbar.');
        Queue::assertPushedOn('mail-high', EmergencyAlertJob::class, static fn (EmergencyAlertJob $job): bool => $job->alertId === (int) $alert->getKey() && $job->level === 0);
        Queue::assertNotPushed(EmergencyAlertJob::class, static fn (EmergencyAlertJob $job): bool => $job->queue !== 'mail-high');

        // Zustellung Stufe 0 an den Verantwortlichen: E-Mail übergeben, Webhook und sms/call nicht eingerichtet.
        $queue = $this->app->make(EmergencyQueue::class);
        $step = $queue->deliver($alert, 0);
        $this->assertSame('notified', $step->status);
        $results = collect($step->channel_results_json)->keyBy('channel');
        $this->assertSame('sent', $results['email']['status']);
        $this->assertSame('not_configured', $results['webhook']['status']);
        $this->assertSame('not_configured', $results['sms']['status']);
        $this->assertSame('not_configured', $results['call']['status']);
        $this->assertSame((int) $agent->getKey(), $results['email']['recipient_user_id']);
        $this->assertNull($alert->refresh()->acknowledged_at, 'Zustellung ist keine Annahme.');

        // Nach 5 Minuten ohne Annahme: nächste Stufe (Teamleitung), erneut mail-high.
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:06', 'Europe/Berlin'));
        (new SlaCheckJob)->handle($this->app->make(SlaClockService::class), $queue);
        $alert->refresh();
        $this->assertSame('escalated', $alert->status);
        $this->assertSame(1, (int) $alert->escalation_level);
        Queue::assertPushedOn('mail-high', EmergencyAlertJob::class, static fn (EmergencyAlertJob $job): bool => $job->level === 1);

        $stepOne = $queue->deliver($alert, 1);
        $this->assertSame('team_lead', $stepOne->escalated_to_role);
        $this->assertSame((int) $lead->getKey(), (int) $stepOne->escalated_to_user_id);

        // Annahme durch Menschen: getrennt protokolliert, weitere Eskalation gestoppt.
        $queue->acknowledge($alert, $lead);
        $alert->refresh();
        $this->assertSame('acknowledged', $alert->status);
        $this->assertSame((int) $lead->getKey(), (int) $alert->acknowledged_by);
        $this->assertNull($alert->next_escalation_at);
        $this->assertSame(2, EscalationStep::query()->where('emergency_alert_id', $alert->getKey())->where('status', 'acknowledged')->count());
        $this->assertNotNull($case->refresh()->acknowledged_at);

        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:30', 'Europe/Berlin'));
        $this->assertSame(0, $queue->escalateDue(CarbonImmutable::now()));

        $log = CaseStatusLog::query()->where('case_id', $case->getKey())->where('dimension', 'emergency')->orderBy('id')->pluck('to_status')->all();
        $this->assertSame(['open', 'delivery_notified', 'escalated_1', 'delivery_notified', 'acknowledged'], $log);
    }

    public function test_sla_check_job_runs_on_mail_high_and_webhook_failure_is_never_success(): void
    {
        Queue::fake();
        Mail::fake();
        config()->set('hub.sla.emergency.webhook_url', 'https://alarm.example.com/hook');
        Http::fake(['alarm.example.com/*' => Http::response('nope', 500)]);

        $this->assertSame('mail-high', (new SlaCheckJob)->queue);
        $this->assertSame('mail-high', (new EmergencyAlertJob(1, 0))->queue);
        SlaCheckJob::dispatch();
        Queue::assertPushedOn('mail-high', SlaCheckJob::class);

        $agent = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Gasgeruch', 'body_text' => 'Es riecht stark nach Gas im Flur.']);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'schaden_notfall', 'title' => 'Gasgeruch', 'assignee_user_id' => $agent->getKey()]], $agent);
        $alert = EmergencyAlert::query()->where('case_id', $case->getKey())->firstOrFail();

        $step = $this->app->make(EmergencyQueue::class)->deliver($alert, 0);
        $results = collect($step->channel_results_json)->keyBy('channel');
        $this->assertSame('failed', $results['webhook']['status']);
        $this->assertStringContainsString('500', $results['webhook']['detail']);
        $this->assertSame('sent', $results['email']['status']);

        $status = $this->app->make(EmergencyQueue::class)->onCallStatus();
        $this->assertFalse($status['configured']);
        $this->assertStringContainsString('Keine 24/7-Betreuung eingerichtet', (string) $status['notice']);
    }
}
