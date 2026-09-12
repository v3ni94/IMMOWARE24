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

    public function test_legacy_import_creates_cancelled_clocks_instead_of_breached_ones(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        $cases = $this->app->make(CaseService::class);
        $clocks = $this->app->make(SlaClockService::class);

        // Lückenimport: 90 Tage alte Nachricht wird heute importiert.
        $old = $this->inboundMessage($this->mailbox, ['subject' => 'Alte Anfrage', 'received_at' => CarbonImmutable::now()->subDays(90), 'imported_at' => CarbonImmutable::now()]);
        $case = $cases->openFromMessage($old, [['item_type' => 'anfrage_allgemein', 'title' => 'Alte Anfrage', 'assignee_user_id' => $user->getKey(), 'next_step' => 'Prüfen']], $user);
        $item = $case->items()->firstOrFail();

        $states = SlaClock::query()->where('case_item_id', $item->getKey())->pluck('state')->unique()->all();
        $this->assertSame(['cancelled'], $states, 'Altbestand: alle Uhren storniert.');
        $this->assertStringContainsString('Altbestand', (string) SlaClock::query()->where('case_item_id', $item->getKey())->value('cause_text'));
        $this->assertSame(0, SlaClockLog::query()->where('case_id', $case->getKey())->where('event', 'evaluated_breached')->count(), 'Kein künstlicher SLA-Verstoß.');
        $this->assertSame(4, SlaClockLog::query()->where('case_id', $case->getKey())->where('event', 'cancelled')->where('source', 'legacy_import')->count());
        $this->assertNull($item->due_at, 'Fälligkeit setzt ein Mensch, nicht die verletzte Uhr.');
        $light = $clocks->trafficLightForCase($case->refresh());
        $this->assertNull($light->clockType, 'Ampel folgt keiner Uhr des Altbestands.');

        // Empfang vor import_from des Postfachs zählt ebenfalls als Altbestand, auch wenn die Mail jung ist.
        $this->mailbox->forceFill(['import_from' => CarbonImmutable::now()->subDay()])->save();
        $beforeImport = $this->inboundMessage($this->mailbox, ['subject' => 'Kurz vor Importbeginn', 'received_at' => CarbonImmutable::now()->subDays(2)]);
        $caseTwo = $cases->openFromMessage($beforeImport, [['item_type' => 'anfrage_allgemein', 'title' => 'Vor Importbeginn', 'assignee_user_id' => $user->getKey()]], $user);
        $this->assertSame(['cancelled'], SlaClock::query()->where('case_id', $caseTwo->getKey())->pluck('state')->unique()->all());

        // Frische Nachricht nach import_from: Uhren laufen normal.
        $fresh = $this->inboundMessage($this->mailbox, ['subject' => 'Neue Anfrage', 'received_at' => CarbonImmutable::now()->subMinutes(5)]);
        $caseThree = $cases->openFromMessage($fresh, [['item_type' => 'anfrage_allgemein', 'title' => 'Neu', 'assignee_user_id' => $user->getKey()]], $user);
        $this->assertSame(['running'], SlaClock::query()->where('case_id', $caseThree->getKey())->pluck('state')->unique()->all());
        $this->assertNotNull($caseThree->items()->firstOrFail()->due_at);
    }

    public function test_manual_retarget_computes_warn_at_in_business_time_for_business_clocks(): void
    {
        Queue::fake();
        // Freitag 10:00 Berlin.
        $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('lead');
        $cases = $this->app->make(CaseService::class);
        $clocks = $this->app->make(SlaClockService::class);
        $message = $this->inboundMessage($this->mailbox, ['subject' => 'Rechnungsfrage', 'received_at' => CarbonImmutable::now()]);
        $case = $cases->openFromMessage($message, [['item_type' => 'anfrage_allgemein', 'title' => 'Rechnung', 'assignee_user_id' => $user->getKey()]], $user);
        $item = $case->items()->firstOrFail();
        $clock = SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::Resolution->value)->firstOrFail();
        $this->assertTrue((bool) $clock->uses_calendar, 'P2 läuft in Arbeitszeit.');

        // Neues Ziel Montag 10:00: Arbeitszeitspanne 390 + 120 = 510 Minuten, 50 % = 255 Minuten ab Freitag 10:00 = Freitag 14:15.
        $newTarget = CarbonImmutable::parse('2026-09-14 10:00', 'Europe/Berlin');
        $clocks->retarget($clock, $newTarget, 'Absprache mit Kunde.', (int) $user->getKey());
        $clock->refresh();

        $this->assertSame('2026-09-11 14:15', $clock->warn_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'), 'warn_at in Arbeitsminuten, nicht Kalenderminuten (sonst Samstag 22:00).');
        $this->assertSame('manual', $clock->target_source);
        $this->assertSame(SlaColor::Green->value, $clock->color);

        $this->travelTo(CarbonImmutable::parse('2026-09-11 14:20', 'Europe/Berlin'));
        $this->assertSame(SlaColor::Yellow->value, $clocks->evaluate($clock->refresh(), CarbonImmutable::now())->color);
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
