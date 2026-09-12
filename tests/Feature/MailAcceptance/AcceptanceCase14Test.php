<?php

declare(strict_types=1);

namespace Tests\Feature\MailAcceptance;

use App\Modules\Cases\Services\CaseService;
use App\Modules\Gmail\Jobs\HistorySyncJob;
use App\Modules\Security\Models\User;
use App\Modules\Sla\Jobs\EmergencyAlertJob;
use App\Modules\Sla\Jobs\SlaCheckJob;
use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Models\EscalationStep;
use App\Modules\Sla\Services\EmergencyQueue;
use App\Modules\Sla\Services\SlaClockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Cases\CasesTestCase;

/**
 * Abnahmefall 14: Unbestätigter Notfall eskaliert und blockiert nicht hinter einem Massenimport.
 *
 * Echter Queue-Treiber (database, Tabelle jobs) statt Queue::fake: die Importqueue mail-sync ist mit vielen Jobs
 * gefüllt, ein Worker, der nur mail-high abarbeitet, stellt den Alarm zu und eskaliert ihn, ohne einen einzigen
 * Importjob anzufassen.
 */
final class AcceptanceCase14Test extends CasesTestCase
{
    private const int IMPORT_JOBS = 250;

    public function test_emergency_alert_is_delivered_and_escalated_while_mail_sync_queue_is_full(): void
    {
        config()->set('queue.default', 'database');
        Mail::fake();
        Http::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));

        $agent = $this->actingAsMailRole('agent');
        $lead = User::factory()->for($agent->organization)->create(['email' => 'leitung@example.com']);
        $this->mailbox->team->forceFill(['lead_user_id' => $lead->getKey()])->save();

        // Massenimport: die Importqueue steht voll mit Jobs, die nie abgearbeitet werden.
        for ($i = 1; $i <= self::IMPORT_JOBS; $i++) {
            HistorySyncJob::dispatch(100000 + $i);
        }

        $this->assertSame(self::IMPORT_JOBS, $this->jobsOn('mail-sync'));
        $this->assertSame(0, $this->jobsOn('mail-high'));

        // Notfall geht ein: P0-Regel legt Alarm an und stellt den Zustelljob auf mail-high, nicht hinter den Import.
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Wasserrohrbruch', 'body_text' => 'Im Keller ist ein Rohrbruch, Wasser tritt aus.']);
        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'schaden_notfall', 'title' => 'Rohrbruch Keller', 'assignee_user_id' => $agent->getKey()]], $agent);
        $alert = EmergencyAlert::query()->where('case_id', $case->getKey())->firstOrFail();

        $this->assertSame(1, $this->jobsOn('mail-high'));
        $this->assertSame(self::IMPORT_JOBS, $this->jobsOn('mail-sync'));
        $payload = json_decode((string) DB::table('jobs')->where('queue', 'mail-high')->value('payload'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(EmergencyAlertJob::class, $payload['displayName'] ?? null);

        // Worker nur für mail-high: Alarm zugestellt, Importqueue unangetastet.
        $this->workHighQueue();

        $this->assertSame(0, $this->jobsOn('mail-high'));
        $this->assertSame(self::IMPORT_JOBS, $this->jobsOn('mail-sync'), 'Kein Importjob wurde verarbeitet oder verdrängt.');
        $stepZero = EscalationStep::query()->where('emergency_alert_id', $alert->getKey())->where('level', 0)->firstOrFail();
        $this->assertSame('notified', $stepZero->status);
        $this->assertNull($alert->refresh()->acknowledged_at, 'Zustellung ist keine Annahme.');

        // Fünf Minuten ohne Bestätigung: Eskalation an die Teamleitung, wieder auf mail-high, wieder ohne Import.
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:06', 'Europe/Berlin'));
        (new SlaCheckJob)->handle($this->app->make(SlaClockService::class), $this->app->make(EmergencyQueue::class));

        $alert->refresh();
        $this->assertSame('escalated', $alert->status);
        $this->assertSame(1, (int) $alert->escalation_level);
        $this->assertSame(1, $this->jobsOn('mail-high'));

        $this->workHighQueue();

        $stepOne = EscalationStep::query()->where('emergency_alert_id', $alert->getKey())->where('level', 1)->firstOrFail();
        $this->assertSame('notified', $stepOne->status);
        $this->assertSame('team_lead', $stepOne->escalated_to_role);
        $this->assertSame((int) $lead->getKey(), (int) $stepOne->escalated_to_user_id);
        $this->assertSame(self::IMPORT_JOBS, $this->jobsOn('mail-sync'), 'Importqueue nach beiden Läufen unverändert.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    private function jobsOn(string $queue): int
    {
        return (int) DB::table('jobs')->where('queue', $queue)->count();
    }

    private function workHighQueue(): void
    {
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'mail-high', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 1]);
    }
}
