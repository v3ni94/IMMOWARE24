<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Modules\Sync\Enums\SyncEntity;
use App\Modules\Sync\Services\DataAgeService;
use App\Modules\Sync\Services\SyncStateService;
use Carbon\CarbonImmutable;

final class DataAgeTest extends SyncTestCase
{
    public function test_stale_since_is_set_when_last_success_is_older_than_threshold(): void
    {
        config()->set('hub.sync.stale_after_seconds.contact', 3600);
        $connection = $this->activeConnection('carddav_contacts');
        $connectionId = (int) $connection->getKey();

        $states = $this->app->make(SyncStateService::class);
        $state = $states->forEntity($connectionId, SyncEntity::Contact->value);
        $state->forceFill(['last_success_at' => CarbonImmutable::now()->subHours(3)])->save();

        $dataAge = $this->app->make(DataAgeService::class);
        $this->assertSame(1, $dataAge->refreshStaleness());

        $state->refresh();
        $this->assertNotNull($state->getAttribute('stale_since'));
        $this->assertEqualsWithDelta(CarbonImmutable::now()->subHours(2)->getTimestamp(), $state->getAttribute('stale_since')->getTimestamp(), 5, 'stale_since = letzter Erfolg plus Schwelle');

        $age = $dataAge->for($connectionId, SyncEntity::Contact->value);
        $this->assertTrue($age['stale']);
        $this->assertSame(3600, $age['threshold_seconds']);
        $this->assertGreaterThanOrEqual(3 * 3600 - 5, $age['age_seconds']);
        $this->assertNotNull($age['stale_since']);

        $this->assertSame(0, $dataAge->refreshStaleness(), 'Bereits markierte Zustände werden nicht erneut gezählt.');
    }

    public function test_fresh_state_is_not_stale_and_missing_state_is_stale(): void
    {
        $connection = $this->activeConnection();
        $connectionId = (int) $connection->getKey();
        $states = $this->app->make(SyncStateService::class);
        $state = $states->forEntity($connectionId, SyncEntity::Document->value);
        $state->forceFill(['last_success_at' => CarbonImmutable::now()->subMinutes(5)])->save();

        $dataAge = $this->app->make(DataAgeService::class);
        $this->assertSame(0, $dataAge->refreshStaleness());
        $this->assertFalse($dataAge->for($connectionId, SyncEntity::Document->value)['stale']);

        $missing = $dataAge->for($connectionId, SyncEntity::CalendarEvent->value);
        $this->assertTrue($missing['stale']);
        $this->assertNull($missing['last_success_at']);
        $this->assertCount(3, $dataAge->forConnection($connectionId));

        $this->artisan('hub:sync:stale')->assertSuccessful();
    }
}
