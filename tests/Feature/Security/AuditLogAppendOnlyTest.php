<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Modules\Security\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class AuditLogAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function createEntry(string $action = 'test.action'): AuditLog
    {
        return AuditLog::query()->create([
            'actor_type' => 'system',
            'source' => 'system',
            'action' => $action,
            'before_json' => [],
            'after_json' => ['x' => 1],
        ]);
    }

    public function test_entries_form_a_hash_chain(): void
    {
        $first = $this->createEntry('first');
        $second = $this->createEntry('second');

        $this->assertSame(AuditLog::GENESIS_HASH, $first->prev_hash);
        $this->assertSame($first->row_hash, $second->prev_hash);
        $this->assertTrue($first->fresh()->verifyChain(null));
        $this->assertTrue($second->fresh()->verifyChain($first->fresh()));
    }

    public function test_update_is_refused(): void
    {
        $entry = $this->createEntry();

        $this->expectException(LogicException::class);
        $entry->update(['action' => 'manipulated']);
    }

    public function test_delete_is_refused(): void
    {
        $entry = $this->createEntry();

        try {
            $entry->delete();
            $this->fail('Delete hätte verweigert werden müssen.');
        } catch (LogicException) {
            $this->assertDatabaseCount('audit_logs', 1);
        }
    }
}
