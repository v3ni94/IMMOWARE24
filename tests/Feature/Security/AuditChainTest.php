<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Core\Contracts\AuditLoggerInterface;
use App\Core\Enums\AuditSource;
use App\Core\Support\CorrelationId;
use App\Core\Support\OrganizationContext;
use App\Core\Support\SecretMasker;
use App\Modules\Security\Models\AuditLog;
use App\Modules\Security\Models\User;
use App\Modules\Security\Services\AuditChainVerifier;
use App\Modules\Security\Services\AuditLogger;
use App\Modules\Security\Services\PepperedHasher;
use Illuminate\Database\QueryException;
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

    public function test_sequential_entries_from_two_writers_form_one_consistent_chain(): void
    {
        // Web-Request und Queue-Worker schreiben abwechselnd: jede Zeile verkettet auf den unmittelbaren Vorgänger,
        // kein prev_hash kommt doppelt vor (Sequenz-Lock plus lockForUpdate plus Unique-Index).
        $web = $this->app->make(AuditLogger::class);
        $worker = new AuditLogger(
            $this->app->make(SecretMasker::class),
            $this->app->make(PepperedHasher::class),
            $this->app->make(CorrelationId::class),
            $this->app->make(OrganizationContext::class),
        );

        $entries = [];

        for ($i = 0; $i < 6; $i++) {
            $entries[] = ($i % 2 === 0 ? $web : $worker)->record('test.parallel', null, [], ['i' => $i, 'writer' => $i % 2 === 0 ? 'web' : 'worker'], AuditSource::System);
        }

        $this->assertSame(AuditLog::GENESIS_HASH, $entries[0]->prev_hash);

        for ($i = 1; $i < count($entries); $i++) {
            $this->assertSame($entries[$i - 1]->row_hash, $entries[$i]->prev_hash, 'Eintrag '.$i.' verkettet nicht auf seinen Vorgänger.');
        }

        $this->assertSame(6, AuditLog::query()->distinct()->count('prev_hash'));
        $this->assertTrue($this->app->make(AuditChainVerifier::class)->verify()->valid);
    }

    public function test_duplicate_predecessor_is_rejected_by_unique_index(): void
    {
        $logger = $this->app->make(AuditLogger::class);
        $first = $logger->record('test.fork', null, [], ['n' => 1], AuditSource::System);
        $logger->record('test.fork', null, [], ['n' => 2], AuditSource::System);

        // Simulierter zweiter Schreiber, der trotz Sperre denselben Vorgänger gelesen hat: die Verzweigung scheitert.
        $this->expectException(QueryException::class);

        DB::table('audit_logs')->insert([
            'occurred_at' => now(),
            'actor_type' => 'system',
            'source' => 'system',
            'action' => 'test.fork',
            'before_json' => '[]',
            'after_json' => '{"n":3}',
            'prev_hash' => $first->prev_hash,
            'row_hash' => str_repeat('a', 64),
            'created_at' => now(),
        ]);
    }
}
