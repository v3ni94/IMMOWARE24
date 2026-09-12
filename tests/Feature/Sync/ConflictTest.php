<?php

declare(strict_types=1);

namespace Tests\Feature\Sync;

use App\Core\Enums\ConflictState;
use App\Core\Enums\Role;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Sync\Models\Conflict;
use App\Modules\Sync\Services\ConflictDetector;
use App\Modules\Sync\Services\ConflictService;

final class ConflictTest extends SyncTestCase
{
    public function test_states_are_derived_from_checksums(): void
    {
        $detector = new ConflictDetector;

        $this->assertSame(ConflictState::NoConflict, $detector->state('a', 'a', 'a'));
        $this->assertSame(ConflictState::RemoteNewer, $detector->state('a', 'a', 'b'));
        $this->assertSame(ConflictState::LocalNewer, $detector->state('a', 'b', 'a'));
        $this->assertSame(ConflictState::BothChanged, $detector->state('a', 'b', 'c'));
        $this->assertSame(ConflictState::RemoteNewer, $detector->state(null, null, 'x'), 'Unbekannter Datensatz gilt als entfernt neuer.');
    }

    public function test_both_changed_creates_queue_entry_and_blocks_remote_overwrite(): void
    {
        $connection = $this->activeConnection();
        $detector = $this->app->make(ConflictDetector::class);

        $result = $detector->evaluate((int) $connection->getKey(), 'contact', 7, 'base', 'local', 'remote', null, ['last_name' => 'Muster']);

        $this->assertSame(ConflictState::BothChanged, $result['state']);
        $this->assertFalse($result['apply_remote'], 'Der entfernte Wert darf bei BOTH_CHANGED nicht automatisch übernommen werden.');
        $conflict = Conflict::query()->firstOrFail();
        $this->assertSame('open', $conflict->getAttribute('status'));
        $this->assertSame(ConflictDetector::TYPE_LOCAL_VS_REMOTE, $conflict->getAttribute('conflict_type'));
        $this->assertSame(['last_name' => 'Muster'], $conflict->getAttribute('local_snapshot_json'));

        // Folgelauf stellt denselben Konflikt erneut fest: kein zweiter Eintrag, occurrences steigt.
        $detector->evaluate((int) $connection->getKey(), 'contact', 7, 'base', 'local', 'remote2');
        $this->assertSame(1, Conflict::query()->count());
        $this->assertSame(2, Conflict::query()->firstOrFail()->getAttribute('occurrences'));
    }

    public function test_resolution_closes_conflict_with_audit(): void
    {
        $connection = $this->activeConnection();
        $user = $this->actingAsRole(Role::Operator, $connection->organization);
        $detector = $this->app->make(ConflictDetector::class);
        $conflict = $detector->evaluate((int) $connection->getKey(), 'contact', 7, 'base', 'local', 'remote')['conflict'];
        $this->assertInstanceOf(Conflict::class, $conflict);

        $resolved = $this->app->make(ConflictService::class)->resolve((int) $conflict->getKey(), 'remote', $user, 'Immoware24 ist Master');

        $this->assertSame('resolved_keep_remote', $resolved->getAttribute('status'));
        $this->assertSame((int) $user->getKey(), (int) $resolved->getAttribute('resolved_by'));
        $this->assertNull($resolved->getAttribute('open_key'));
        $this->assertTrue(AuditLog::query()->where('action', 'conflict.resolved')->exists());

        // Danach kann ein neuer offener Konflikt für denselben Datensatz entstehen.
        $detector->evaluate((int) $connection->getKey(), 'contact', 7, 'base', 'local2', 'remote3');
        $this->assertSame(2, Conflict::query()->count());

        $this->expectException(\InvalidArgumentException::class);
        $this->app->make(ConflictService::class)->resolve((int) $conflict->getKey(), 'local', $user);
    }
}
