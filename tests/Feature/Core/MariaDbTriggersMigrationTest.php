<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Migration 2026_09_12_130000_add_mariadb_integrity_triggers: auf SQLite ohne Wirkung, auf MariaDB
 * (CI-Job tests-mariadb mit HUB_DB_TRIGGERS=true bei migrate:fresh) schuetzt sie audit_logs und write_operations.
 */
final class MariaDbTriggersMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION = __DIR__.'/../../../database/migrations/2026_09_12_130000_add_mariadb_integrity_triggers.php';

    private const string REFERENCE_SQL = __DIR__.'/../../../docs/architecture/03-mariadb-triggers.sql';

    private function migration(): object
    {
        return require self::MIGRATION;
    }

    public function test_migration_skips_sqlite_and_leaves_no_triggers(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Nur fuer SQLite relevant.');
        }

        $migration = $this->migration();

        $this->assertFalse($migration::triggersEnabled());
        $this->assertSame(0, (int) DB::table('sqlite_master')->where('type', 'trigger')->count());

        // Ohne Trigger gilt auf SQLite nur die Anwendungslogik: ein direktes UPDATE bleibt technisch moeglich.
        DB::table('audit_logs')->insert([
            'occurred_at' => now(), 'action' => 'test', 'prev_hash' => str_repeat('0', 64), 'row_hash' => str_repeat('1', 64),
        ]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'test')->update(['action' => 'changed']));
    }

    public function test_reference_sql_matches_migration_statements(): void
    {
        $migration = $this->migration();
        $reference = (string) file_get_contents(self::REFERENCE_SQL);

        foreach (array_keys($migration::TRIGGERS) as $name) {
            $this->assertStringContainsString(sprintf('CREATE TRIGGER %s ', $name), $reference, sprintf('Trigger %s fehlt in 03-mariadb-triggers.sql.', $name));
        }

        foreach ($migration::statements() as $name => $sql) {
            preg_match_all("/MESSAGE_TEXT = '([^']+)'/", $sql, $matches);
            foreach ($matches[1] as $message) {
                $this->assertStringContainsString($message, $reference, sprintf('Meldung "%s" (%s) fehlt in der Referenz-SQL.', $message, $name));
            }
            $this->assertStringContainsString(sprintf('ON %s ', $migration::TRIGGERS[$name]), $sql);
        }
    }

    public function test_mariadb_rejects_audit_update_delete_and_second_put_attempt(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
            $this->markTestSkipped('Trigger existieren nur auf MariaDB oder MySQL.');
        }

        // CI setzt HUB_DB_TRIGGERS=false fuer die Testsuite; dieser Test legt die Trigger gezielt an und entfernt sie wieder.
        putenv('HUB_DB_TRIGGERS=true');
        $migration = $this->migration();
        $migration->up();

        try {
            $this->assertTriggersProtectTables($migration);
        } finally {
            $migration->down();
            putenv('HUB_DB_TRIGGERS');
            // DDL hat die RefreshDatabase-Transaktion implizit committet: Testdaten ausdruecklich entfernen.
            DB::table('write_operations')->where('idempotency_key', str_repeat('a', 64))->delete();
            DB::table('audit_logs')->where('action', 'trigger-test')->delete();
        }
    }

    private function assertTriggersProtectTables(object $migration): void
    {
        $triggers = DB::table('information_schema.TRIGGERS')
            ->where('TRIGGER_SCHEMA', DB::connection()->getDatabaseName())
            ->pluck('TRIGGER_NAME')->all();

        foreach (array_keys($migration::TRIGGERS) as $name) {
            $this->assertContains($name, $triggers);
        }

        DB::table('audit_logs')->insert([
            'occurred_at' => now(), 'action' => 'trigger-test', 'prev_hash' => str_repeat('f', 64), 'row_hash' => str_repeat('e', 64),
        ]);

        $this->assertQueryFails(static fn () => DB::table('audit_logs')->where('action', 'trigger-test')->update(['action' => 'changed']), 'append-only');
        $this->assertQueryFails(static fn () => DB::table('audit_logs')->where('action', 'trigger-test')->delete(), 'append-only');

        $organization = $this->createOrganization();
        $connection = $this->createConnection($organization);
        $id = DB::table('write_operations')->insertGetId([
            'operation_uuid' => '00000000-0000-7000-8000-000000000001',
            'connection_id' => $connection->getKey(),
            'idempotency_key' => str_repeat('a', 64),
            'intent_key' => str_repeat('b', 64),
            'payload_hash' => str_repeat('c', 64),
            'target_path' => '/Posteingang/test.pdf',
            'target_path_hash' => str_repeat('d', 64),
            'sanitized_filename' => 'test.pdf',
            'content_hash' => str_repeat('e', 64),
            'status' => 'sent',
            'put_attempts' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertQueryFails(static fn () => DB::table('write_operations')->where('id', $id)->update(['put_attempts' => 2]), 'put_attempts');
        $this->assertQueryFails(static fn () => DB::table('write_operations')->where('id', $id)->update(['status' => 'pending']), 'zurueckfallen');
        $this->assertSame(1, DB::table('write_operations')->where('id', $id)->update(['status' => 'verified']));
    }

    private function assertQueryFails(callable $query, string $messagePart): void
    {
        try {
            $query();
        } catch (QueryException $exception) {
            $this->assertStringContainsString($messagePart, $exception->getMessage());

            return;
        }

        $this->fail('Trigger hat die Aenderung nicht abgewiesen.');
    }
}
