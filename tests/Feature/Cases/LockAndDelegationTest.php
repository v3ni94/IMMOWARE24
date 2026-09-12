<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Modules\Cases\Enums\CaseStatus;
use App\Modules\Cases\Exceptions\CaseLockedException;
use App\Modules\Cases\Services\CaseService;
use App\Modules\Cases\Services\DelegationService;
use App\Modules\Cases\Services\LockService;
use App\Modules\Security\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

final class LockAndDelegationTest extends CasesTestCase
{
    public function test_lock_blocks_others_until_expiry_and_supports_handover(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'UTC'));
        $alice = $this->actingAsMailRole('agent');
        $bob = User::factory()->for($alice->organization)->create();
        $this->attachMailRole($bob, $this->mailbox, 'agent');
        $case = $this->app->make(CaseService::class)->openFromMessage($this->inboundMessage($this->mailbox), [['item_type' => 'sonstiges', 'title' => 'x', 'assignee_user_id' => $alice->getKey()]], $alice);
        $locks = $this->app->make(LockService::class);

        $locks->acquire($case, $alice);
        $this->assertSame((int) $alice->getKey(), (int) $locks->holder($case)?->user_id);

        try {
            $locks->acquire($case, $bob);
            $this->fail('Zweite Person darf nicht übernehmen, solange die Sperre gilt.');
        } catch (CaseLockedException $e) {
            $this->assertSame((int) $alice->getKey(), $e->holderUserId);
        }

        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:04', 'UTC'));
        $this->assertNotNull($locks->heartbeat($case, $alice));
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:08', 'UTC'));
        $this->assertNotNull($locks->holder($case), 'Heartbeat hat die Sperre verlängert.');

        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:20', 'UTC'));
        $this->assertNull($locks->holder($case), 'Ohne Heartbeat läuft die Sperre ab.');
        $lock = $locks->acquire($case, $bob);
        $this->assertSame((int) $bob->getKey(), (int) $lock->user_id);

        $handed = $locks->handover($case, $bob, $alice);
        $this->assertSame((int) $alice->getKey(), (int) $handed->user_id);
        $this->assertTrue($locks->release($case, $alice));
        $this->assertNull($locks->holder($case));
    }

    public function test_absence_substitution_reassignment_and_team_load(): void
    {
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-09 10:00', 'UTC'));
        $lead = $this->actingAsMailRole('lead');
        $absent = User::factory()->for($lead->organization)->create();
        $substitute = User::factory()->for($lead->organization)->create();
        $this->attachMailRole($absent, $this->mailbox, 'agent');
        $this->attachMailRole($substitute, $this->mailbox, 'agent');
        $delegation = $this->app->make(DelegationService::class);
        $delegation->recordAbsence($absent, CarbonImmutable::now()->subDay(), CarbonImmutable::now()->addDays(5), $substitute, 'Urlaub', $lead);

        $this->assertTrue($delegation->isAbsent((int) $absent->getKey()));
        $this->assertSame((int) $substitute->getKey(), $delegation->effectiveAssigneeId((int) $absent->getKey()));

        $cases = $this->app->make(CaseService::class);
        $case = $cases->openFromMessage($this->inboundMessage($this->mailbox), [['item_type' => 'sonstiges', 'title' => 'Vertretungsfall']], $lead);
        $this->assertSame(CaseStatus::New, $case->status_processing);

        $assigned = $delegation->assign($case, $absent, $lead, 'Zuweisung an Sachbearbeitung');
        $this->assertSame((int) $substitute->getKey(), (int) $assigned->assignee_user_id, 'Abwesende Person wird durch Stellvertreter ersetzt.');
        $this->assertSame(CaseStatus::Open, $assigned->status_processing);
        $this->assertSame((int) $substitute->getKey(), (int) $assigned->items()->firstOrFail()->assignee_user_id);

        $handed = $delegation->handover($assigned, $substitute, $lead, 'Rücksprache mit Eigentümer nötig');
        $this->assertSame((int) $lead->getKey(), (int) $handed->assignee_user_id);

        $load = $delegation->teamLoad($this->mailbox->team);
        $byUser = array_column($load, null, 'user_id');
        $this->assertSame(1, $byUser[(int) $lead->getKey()]['open_cases']);
        $this->assertTrue($byUser[(int) $absent->getKey()]['absent']);
        $this->assertSame((int) $substitute->getKey(), $byUser[(int) $absent->getKey()]['substitute_user_id']);
        $this->assertSame((int) $substitute->getKey(), $delegation->leastLoadedMemberId($this->mailbox->team));
    }
}
