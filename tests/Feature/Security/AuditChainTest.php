<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditChainVerifier;
use App\Modules\Security\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditChainTest extends TestCase
{
    use RefreshDatabase;

    public function test_interface_is_bound_and_masks_secrets(): void
    {
        $logger = $this->app->make(AuditLoggerInterface::class);
        $this->assertInstanceOf(AuditLogger::class, $logger);

        $user = User::factory()->create();

        $logger->log('test.secret', $user, ['password' => 'geheim'], ['api_key' => 'hub_live_x_y', 'name' => 'ok'], AuditSource::System->value, 'corr-123');

        $entry = AuditLog::query()->where('action', 'test.secret')->firstOrFail();
        $this->assertSame('***', $entry->before_json['password']);
        $this->assertSame('***', $entry->after_json['api_key']);
        $this->assertSame('ok', $entry->after_json['name']);
        $this->assertSame('corr-123', $entry->correlation_id);
        $this->assertSame('User', $entry->entity_type);
        $this->assertSame($user->id, $entry->entity_id);
        $this->assertSame('system', $entry->actor_type);
    }

    public function test_verify_command_confirms_intact_chain_and_anchor(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        for ($i = 0; $i < 5; $i++) {
            $logger->record('test.entry', null, [], ['i' => $i], AuditSource::System);
        }

        $this->artisan('audit:verify')->assertSuccessful()->expectsOutputToContain('5 Einträge geprüft');
        $this->artisan('audit:anchor')->assertSuccessful();
        $this->assertDatabaseCount('audit_anchors', 1);
        $this->assertDatabaseHas('audit_anchors', ['last_audit_id' => 5, 'root_hash' => AuditLog::query()->find(5)->row_hash]);

        $this->artisan('audit:anchor')->assertSuccessful()->expectsOutputToContain('Kein neuer Anker');
        $this->assertDatabaseCount('audit_anchors', 1);

        $result = $this->app->make(AuditChainVerifier::class)->verify();
        $this->assertTrue($result->valid);
        $this->assertSame(5, $result->checked);
    }

    public function test_tampering_with_a_row_is_detected(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        for ($i = 0; $i < 4; $i++) {
            $logger->record('test.entry', null, [], ['i' => $i], AuditSource::System);
        }

        // Manipulation unterhalb des Models (das Model verbietet Updates).
        DB::table('audit_logs')->where('id', 2)->update(['action' => 'manipulated']);

        $result = $this->app->make(AuditChainVerifier::class)->verify();
        $this->assertFalse($result->valid);
        $this->assertSame(2, $result->firstBrokenId);

        $this->artisan('audit:verify')->assertFailed();
        $this->artisan('audit:anchor')->assertFailed();
    }

    public function test_recomputed_hash_after_tampering_breaks_the_link_to_the_next_row(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        for ($i = 0; $i < 3; $i++) {
            $logger->record('test.entry', null, [], ['i' => $i], AuditSource::System);
        }

        $second = AuditLog::query()->findOrFail(2);
        $second->setAttribute('action', 'manipulated');
        $newHash = $second->computeRowHash();

        DB::table('audit_logs')->where('id', 2)->update(['action' => 'manipulated', 'row_hash' => $newHash]);

        $result = $this->app->make(AuditChainVerifier::class)->verify();
        $this->assertFalse($result->valid);
        $this->assertSame(3, $result->firstBrokenId);
    }

    public function test_deleted_row_breaks_the_chain(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        for ($i = 0; $i < 3; $i++) {
            $logger->record('test.entry', null, [], ['i' => $i], AuditSource::System);
        }

        DB::table('audit_logs')->where('id', 2)->delete();

        $result = $this->app->make(AuditChainVerifier::class)->verify();
        $this->assertFalse($result->valid);
        $this->assertSame(3, $result->firstBrokenId);
    }

    public function test_anchor_mismatch_is_detected(): void
    {
        $logger = $this->app->make(AuditLogger::class);
        $logger->record('test.entry', null, [], [], AuditSource::System);

        $this->artisan('audit:anchor')->assertSuccessful();
        DB::table('audit_anchors')->update(['root_hash' => str_repeat('f', 64)]);

        $result = $this->app->make(AuditChainVerifier::class)->verify();
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('Anker', (string) $result->message);
    }
}
