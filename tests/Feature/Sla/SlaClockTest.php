<?php

declare(strict_types=1);

namespace Tests\Feature\Sla;

use App\Modules\Cases\Services\CaseService;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Enums\SlaColor;
use App\Modules\Sla\Models\SlaClock;
use App\Modules\Sla\Models\SlaClockLog;
use App\Modules\Sla\Models\SlaRule;
use App\Modules\Sla\Services\SlaClockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Cases\CasesTestCase;

final class SlaClockTest extends CasesTestCase
{
    public function test_traffic_light_turns_yellow_at_fifty_percent_and_red_when_breached_with_cause(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        $cases = $this->app->make(CaseService::class);
        $clocks = $this->app->make(SlaClockService::class);
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Brand im Keller', 'body_text' => 'Es brennt!', 'received_at' => CarbonImmutable::now()]);
        $case = $cases->openFromMessage($message, [['item_type' => 'schaden_notfall', 'title' => 'Brand', 'assignee_user_id' => $user->getKey(), 'next_step' => 'Feuerwehr bestätigen']], $user, ['next_step' => 'Feuerwehr bestätigen']);

        $this->assertSame(SlaColor::Green, $clocks->trafficLightForCase($case)->color);

        // P0 Annahme 10 Minuten: nach 6 Minuten gelb (ab 50 %).
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:06', 'Europe/Berlin'));
        $light = $clocks->trafficLightForCase($case->refresh());
        $this->assertSame(SlaColor::Yellow, $light->color);
        $this->assertSame(ClockType::Acknowledge->value, $light->clockType);
        $this->assertStringContainsString('Annahme', $light->cause);
        $this->assertStringContainsString('fällig 09.09.2026 10:10', $light->cause);

        // Nach 11 Minuten rot mit Ursache, Überschreitung protokolliert.
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:11', 'Europe/Berlin'));
        $light = $clocks->trafficLightForCase($case->refresh());
        $this->assertSame(SlaColor::Red, $light->color);
        $this->assertStringContainsString('überschritten seit 09.09.2026 10:10', $light->cause);
        $ack = SlaClock::query()->where('case_id', $case->getKey())->where('clock_type', ClockType::Acknowledge->value)->firstOrFail();
        $this->assertSame(1, SlaClockLog::query()->where('sla_clock_id', $ack->getKey())->where('event', 'evaluated_breached')->count());

        // Annahme durch Menschen stoppt die Annahmeuhr als überschritten, die Ampel folgt der nächsten Verpflichtung.
        $cases->acknowledge($case, $user);
        $this->assertSame('breached', $ack->refresh()->state);
        $light = $clocks->trafficLightForCase($case->refresh());
        $this->assertSame(ClockType::FirstQualifiedReply->value, $light->clockType);
    }

    public function test_missing_mandatory_fields_make_case_red_and_team_rule_overrides_config(): void
    {
        Queue::fake();
        $user = $this->actingAsMailRole('lead');
        SlaRule::query()->create([
            'organization_id' => $this->mailbox->organization_id,
            'team_id' => $this->mailbox->team_id,
            'priority' => 'p2',
            'clock_type' => 'resolution',
            'target_minutes' => 600,
            'uses_calendar' => true,
            'warn_percent' => 50,
        ]);

        $cases = $this->app->make(CaseService::class);
        $clocks = $this->app->make(SlaClockService::class);
        $case = $cases->openFromMessage($this->inboundMessage($this->mailbox), [['item_type' => 'anfrage_allgemein', 'title' => 'Frage']], $user);

        $light = $clocks->trafficLightForCase($case);
        $this->assertSame(SlaColor::Red, $light->color);
        $this->assertStringContainsString('Verantwortlicher', $light->cause);

        $resolution = SlaClock::query()->where('case_id', $case->getKey())->where('clock_type', ClockType::Resolution->value)->firstOrFail();
        $this->assertSame('rule', $resolution->target_source);
        $this->assertSame(600, (int) $resolution->target_minutes);
        $this->assertNotNull($resolution->sla_rule_id);

        $retargeted = $clocks->retarget($resolution, CarbonImmutable::now()->addDays(10), 'Kunde hat Aufschub gewährt.', (int) $user->getKey());
        $this->assertSame('manual', $retargeted->target_source);
        $this->assertSame(1, SlaClockLog::query()->where('sla_clock_id', $resolution->getKey())->where('event', 'retargeted')->where('source', 'manual')->count());
    }
}
