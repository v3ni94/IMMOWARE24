<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Enums\Priority;
use App\Modules\Cases\Models\CaseStatusLog;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Sla\Enums\ClockType;
use App\Modules\Sla\Enums\SlaColor;
use App\Modules\Sla\Jobs\EmergencyAlertJob;
use App\Modules\Sla\Models\EmergencyAlert;
use App\Modules\Sla\Models\SlaClock;
use App\Modules\Sla\Models\SlaClockLog;
use App\Modules\Sla\Services\SlaClockService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * Abnahmefälle 12 (Adresse plus Wasserschaden: zwei Teilanliegen mit getrennten Fälligkeiten), 13 (Altmail-Fristen),
 * 14 (Notfall eskaliert auf mail-high unabhängig vom Import) und 18 (Zwischenzustände protokolliert).
 */
final class CaseOpeningTest extends CasesTestCase
{
    public function test_address_change_and_water_damage_become_two_items_with_separate_due_dates(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, [
            'subject' => 'Neue Adresse und Wasser tritt aus',
            'body_text' => 'Meine neue Anschrift lautet Musterweg 3. Außerdem tritt in der Küche Wasser aus der Wand.',
            'received_at' => CarbonImmutable::parse('2026-09-09 09:55', 'Europe/Berlin'),
        ]);

        $case = $this->app->make(CaseService::class)->openFromMessage($message, [
            ['item_type' => 'adressaenderung', 'title' => 'Adressänderung', 'priority' => Priority::P2, 'assignee_user_id' => $user->getKey()],
            ['item_type' => 'schaden_notfall', 'title' => 'Wasseraustritt Küche', 'assignee_user_id' => $user->getKey()],
        ], $user);

        $this->assertMatchesRegularExpression('/^V-2026-\d{6}$/', $case->case_number);
        $this->assertSame(Priority::P0, $case->priority, 'Vorgang trägt die höchste Priorität der Teilanliegen (Regel Wasser tritt aus).');
        $this->assertSame($message->received_at->utc()->toIso8601String(), $case->opened_at->toIso8601String(), 'Empfangszeit ist die Gmail internalDate.');

        $items = $case->items()->orderBy('position')->get();
        $this->assertCount(2, $items);
        [$address, $damage] = [$items[0], $items[1]];
        $this->assertSame('p2', $address->priority->value);
        $this->assertSame('p0', $damage->priority->value);
        $this->assertNotNull($address->due_at);
        $this->assertNotNull($damage->due_at);
        $this->assertTrue($damage->due_at->lessThan($address->due_at), 'P0 in Kalenderzeit ist früher fällig als P2 in Arbeitszeit.');
        $this->assertSame($damage->due_at->toIso8601String(), $case->refresh()->due_at->toIso8601String(), 'Vorgangsfälligkeit ist die früheste Teilfälligkeit.');

        // Vier Uhren je Teilanliegen, Start mit der Empfangszeit, Quelle protokolliert.
        $this->assertSame(8, SlaClock::query()->where('case_id', $case->getKey())->count());
        $damageResolution = SlaClock::query()->where('case_item_id', $damage->getKey())->where('clock_type', ClockType::Resolution->value)->firstOrFail();
        $this->assertSame($message->received_at->utc()->addMinutes(240)->toIso8601String(), $damageResolution->target_at->toIso8601String());
        $this->assertFalse((bool) $damageResolution->uses_calendar);
        $this->assertSame('config', $damageResolution->target_source);
        $this->assertSame(1, SlaClockLog::query()->where('sla_clock_id', $damageResolution->getKey())->where('event', 'started')->count());

        // Notfall: Alarm angelegt, Zustelljob auf mail-high, nicht auf der Importqueue.
        $this->assertSame(1, EmergencyAlert::query()->where('case_id', $case->getKey())->count());
        Queue::assertPushedOn('mail-high', EmergencyAlertJob::class);

        // Zwischenzustände protokolliert: Anlage, Priorität, Teilanliegen, Öffnung durch Zuständigen.
        $log = CaseStatusLog::query()->where('case_id', $case->getKey())->pluck('to_status')->all();
        $this->assertContains('new', $log);
        $this->assertContains('p0', $log);
        $this->assertContains('open', $log);
        $this->assertSame(CaseStatus::Open, $address->status_processing);
    }

    public function test_old_mail_deadlines_start_at_receipt_and_are_already_red(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-11 10:00', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        // Empfang am Montag 31.08.2026, Import elf Tage später.
        $message = $this->inboundMessage($this->mailbox, [
            'subject' => 'Frage zur Abrechnung',
            'body_text' => 'Können Sie mir die Abrechnung erläutern?',
            'received_at' => CarbonImmutable::parse('2026-08-31 09:00', 'Europe/Berlin'),
            'imported_at' => CarbonImmutable::now(),
        ]);

        $case = $this->app->make(CaseService::class)->openFromMessage($message, [
            ['item_type' => 'anfrage_allgemein', 'title' => 'Auskunft Abrechnung', 'assignee_user_id' => $user->getKey()],
        ], $user);

        $this->assertSame(Priority::P3, $case->priority, 'Altmail ohne Dringlichkeitsmerkmale ist P3.');
        $item = $case->items()->firstOrFail();
        $ack = SlaClock::query()->where('case_item_id', $item->getKey())->where('clock_type', ClockType::Acknowledge->value)->firstOrFail();

        $this->assertSame($message->received_at->utc()->toIso8601String(), $ack->started_at->toIso8601String(), 'Import verjüngt die Frist nicht.');
        // P3 Annahme 4 Arbeitstage ab Montag 31.08. 09:00 = Freitag 04.09. 09:00 Berlin.
        $this->assertSame('2026-09-04 09:00', $ack->target_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
        $this->assertSame(SlaColor::Red->value, $ack->color);
        $this->assertStringContainsString('überschritten seit 04.09.2026 09:00', (string) $ack->cause_text);

        $light = $this->app->make(SlaClockService::class)->trafficLightForCase($case->refresh());
        $this->assertSame(SlaColor::Red, $light->color);
        $this->assertNotSame('', $light->cause);
        Queue::assertNothingPushed();
    }

    public function test_business_time_clocks_skip_weekend(): void
    {
        Queue::fake();
        // Freitag 11.09.2026 15:00 Empfang, P1: Annahme 4 Arbeitsstunden = Montag 14.09. 10:30.
        $this->travelTo(CarbonImmutable::parse('2026-09-11 15:05', 'Europe/Berlin'));
        $user = $this->actingAsMailRole('agent');
        $message = $this->inboundMessage($this->mailbox, [
            'subject' => 'Heizungsausfall',
            'body_text' => 'Die Heizung ist seit heute ausgefallen.',
            'received_at' => CarbonImmutable::parse('2026-09-11 15:00', 'Europe/Berlin'),
        ]);

        $case = $this->app->make(CaseService::class)->openFromMessage($message, [['item_type' => 'schaden', 'title' => 'Heizung', 'assignee_user_id' => $user->getKey()]], $user);
        $this->assertSame(Priority::P1, $case->priority);

        $ack = SlaClock::query()->where('case_id', $case->getKey())->where('clock_type', ClockType::Acknowledge->value)->firstOrFail();
        $this->assertSame('2026-09-14 10:30', $ack->target_at->setTimezone('Europe/Berlin')->format('Y-m-d H:i'));
        $this->assertSame(SlaColor::Green->value, $ack->color);
        Queue::assertNothingPushed();
    }
}
