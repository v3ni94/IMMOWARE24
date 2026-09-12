<?php

declare(strict_types=1);

namespace Tests\Feature\MailUi;

use App\Modules\Gmail\Models\MailSyncState;
use App\Modules\MailUi\Services\MailOpsMetrics;
use App\Modules\Sync\Services\SyncMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Dashboard-Kennzahlen Betrieb: Push-Drosselungen (429) aus dem Zähler mail_push_429 und Watch-Ablauf je Postfach
 * aus mail_sync_states.
 */
final class DashboardOpsMetricsTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://mail.muellerhv.de';

    public function test_rate_limit_log_event_increments_counter_shown_on_dashboard(): void
    {
        $this->actingAsMailRole('admin');
        $this->app->make(SyncMetrics::class)->reset();

        Log::warning('mail.push.rate_limited', ['key' => hash('sha256', 'mailbox:x'), 'retry_after' => 30]);
        Log::warning('mail.push.rate_limited', ['key' => hash('sha256', 'mailbox:x'), 'retry_after' => 30]);
        Log::warning('irgendetwas anderes');

        $this->assertSame(2, $this->app->make(MailOpsMetrics::class)->pushRateLimited());

        $this->get(self::BASE.'/')
            ->assertOk()
            ->assertSee('Push-Drosselungen (429)')
            ->assertSee('seit Zählerstart: <strong>2</strong>', false);
    }

    public function test_watch_problems_list_expired_missing_and_expiring_watches(): void
    {
        $this->actingAsMailRole('admin');
        $organizationId = (int) $this->mailbox->organization_id;
        $expired = $this->createMailbox(null, null, null, ['organization_id' => $organizationId, 'label' => 'Postfach abgelaufen']);
        $healthy = $this->createMailbox(null, null, null, ['organization_id' => $organizationId, 'label' => 'Postfach gesund']);
        $expiring = $this->createMailbox(null, null, null, ['organization_id' => $organizationId, 'label' => 'Postfach bald ablaufend']);

        MailSyncState::query()->create(['mailbox_id' => $expired->getKey(), 'watch_status' => 'expired', 'watch_expiration' => now()->subDay()]);
        MailSyncState::query()->create(['mailbox_id' => $healthy->getKey(), 'watch_status' => 'active', 'watch_expiration' => now()->addDays(6)]);
        MailSyncState::query()->create(['mailbox_id' => $expiring->getKey(), 'watch_status' => 'active', 'watch_expiration' => now()->addHours(3)]);

        $problems = $this->app->make(MailOpsMetrics::class)->watchProblems($organizationId);
        $byMailbox = collect($problems)->keyBy('mailbox');

        $this->assertSame('fail', $byMailbox['Postfach abgelaufen']['level']);
        $this->assertSame('Läuft ab', $byMailbox['Postfach bald ablaufend']['label']);
        $this->assertSame('Kein Watch', $byMailbox['Testpostfach']['label'], 'Postfach ohne Sync-Zeile gilt als ohne Watch.');
        $this->assertFalse($byMailbox->has('Postfach gesund'));

        $this->get(self::BASE.'/')
            ->assertOk()
            ->assertSee('Watch-Ablauf')
            ->assertSee('Postfach abgelaufen')
            ->assertSee('Postfach bald ablaufend')
            ->assertSeeInOrder(['Betrieb: Push und Watch', 'Abgelaufen', 'Postfach abgelaufen', 'Läuft ab', 'Kein Watch', 'Teamlast']);
    }
}
