<?php

declare(strict_types=1);

namespace Tests\Feature\Connector;

use App\Core\Contracts\CapabilityRegistryInterface;
use App\Core\Enums\CapabilityStatus;
use App\Modules\Connector\Models\Capability;
use App\Modules\Connector\Services\CapabilityRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CapabilityRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_is_bound_to_registry(): void
    {
        $this->assertInstanceOf(CapabilityRegistry::class, $this->app->make(CapabilityRegistryInterface::class));
    }

    public function test_tested_status_with_config_flag_is_available(): void
    {
        $connection = $this->createConnection();
        Capability::factory()->for($connection, 'connection')->key('documents.read')->status(CapabilityStatus::Tested)->create();
        Capability::factory()->for($connection, 'connection')->key('contacts.read')->status(CapabilityStatus::Assumed)->create();
        Capability::factory()->for($connection, 'connection')->key('calendar.read')->status(CapabilityStatus::Documented)->create();

        $registry = $this->app->make(CapabilityRegistry::class);
        $registry->refresh((int) $connection->getKey());

        $this->assertTrue($registry->has('documents.read'));
        $this->assertFalse($registry->has('contacts.read'), 'assumed darf nie verfügbar sein');
        $this->assertFalse($registry->has('calendar.read'), 'documented ohne Test reicht nicht');
        $this->assertFalse($registry->has('units.read'), 'ohne Zeile nicht verfügbar');
    }

    public function test_config_flag_false_blocks_verified_capability(): void
    {
        config()->set('hub.core.read.enabled', false);
        $connection = $this->createConnection();
        Capability::factory()->for($connection, 'connection')->key('documents.read')->status(CapabilityStatus::Verified)->create();

        $registry = $this->app->make(CapabilityRegistry::class);

        $this->assertFalse($registry->hasFor((int) $connection->getKey(), 'documents.read'));
    }

    public function test_documents_write_requires_both_write_flags(): void
    {
        $connection = $this->createConnection();
        Capability::factory()->for($connection, 'connection')->key('documents.write')->status(CapabilityStatus::Tested)->create();
        $registry = $this->app->make(CapabilityRegistry::class);

        config()->set('hub.core.write.enabled', true);
        config()->set('hub.core.write.webdav_create_enabled', false);
        $registry->refresh((int) $connection->getKey());
        $this->assertFalse($registry->has('documents.write'));

        config()->set('hub.core.write.webdav_create_enabled', true);
        $this->assertTrue($registry->has('documents.write'));
    }

    public function test_hard_locked_capabilities_stay_false_even_with_config_true_and_verified_row(): void
    {
        config()->set('hub.core.write.webdav_delete_enabled', true);
        config()->set('hub.core.write.webdav_move_enabled', true);
        config()->set('hub.core.write.carddav_enabled', true);
        config()->set('hub.core.write.caldav_enabled', true);

        $connection = $this->createConnection();

        foreach (['documents.delete', 'documents.move', 'contacts.write', 'calendar.write'] as $key) {
            Capability::factory()->for($connection, 'connection')->key($key)->status(CapabilityStatus::Verified)->create(['enabled' => true, 'hard_locked' => false]);
        }

        $registry = $this->app->make(CapabilityRegistry::class);
        $registry->refresh((int) $connection->getKey());

        foreach (['documents.delete', 'documents.move', 'contacts.write', 'calendar.write'] as $key) {
            $this->assertFalse($registry->has($key), $key.' muss hard_locked bleiben');
            $this->assertTrue($registry->all()[$key]['hard_locked']);
        }
    }

    public function test_record_test_result_never_enables_hard_locked_and_enables_tested(): void
    {
        $connection = $this->createConnection();
        $registry = $this->app->make(CapabilityRegistry::class);
        $id = (int) $connection->getKey();

        $locked = $registry->recordTestResult($id, 'documents.delete', CapabilityStatus::Verified, 'x');
        $this->assertFalse((bool) $locked->getAttribute('enabled'));
        $this->assertTrue((bool) $locked->getAttribute('hard_locked'));
        $this->assertSame(CapabilityStatus::Unavailable, $locked->getAttribute('evidence_status'));

        $tested = $registry->recordTestResult($id, 'documents.read', CapabilityStatus::Tested, 'protokoll');
        $this->assertTrue((bool) $tested->getAttribute('enabled'));
        $this->assertNotNull($tested->getAttribute('tested_at'));

        $registry->ensureHardLocks($id);
        $this->assertSame(4, Capability::query()->where('connection_id', $id)->where('hard_locked', true)->count());
    }
}
