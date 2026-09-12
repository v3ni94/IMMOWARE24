<?php

declare(strict_types=1);

namespace Tests\Feature\Core;

use App\Modules\Contacts\Models\Contact;
use App\Modules\Documents\Models\Document;
use App\Modules\Estate\Models\Unit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_hub_tables_exist_after_migrate_fresh(): void
    {
        $tables = [
            'organizations', 'immoware_technical_users', 'immoware_connections', 'capabilities', 'export_schedules',
            'properties', 'buildings', 'units', 'contacts', 'contact_identifiers', 'contact_merges', 'companies',
            'contact_roles', 'contracts', 'contract_parties', 'ownerships', 'bank_accounts', 'document_folders',
            'documents', 'calendar_events', 'cases', 'invoices', 'transactions', 'open_items', 'external_mappings',
            'external_payloads', 'sync_states', 'sync_runs', 'sync_events', 'import_formats', 'import_files',
            'conflicts', 'write_operations', 'dlq_items', 'field_mappings', 'remote_requests', 'users', 'api_keys',
            'webhook_endpoints', 'webhook_outbox', 'webhook_deliveries', 'audit_logs', 'audit_anchors',
            'hub_decision_backups',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), sprintf('Tabelle %s fehlt.', $table));
        }
    }

    public function test_users_table_carries_two_factor_and_role_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', [
            'organization_id', 'role', 'totp_secret', 'totp_confirmed_at', 'recovery_codes', 'locked_until', 'failed_login_count',
        ]));
    }

    public function test_mirror_tables_carry_external_identity_block(): void
    {
        foreach (['properties', 'units', 'contacts', 'documents', 'calendar_events'] as $table) {
            $this->assertTrue(Schema::hasColumns($table, [
                'source_system', 'external_id', 'external_id_hash', 'external_parent_id', 'external_updated_at',
                'first_synced_at', 'last_synced_at', 'checksum', 'sync_version', 'deleted_at',
            ]), sprintf('Herkunftsblock auf %s unvollständig.', $table));
        }
    }

    public function test_factories_create_consistent_records(): void
    {
        $organization = $this->createOrganization();
        $connection = $this->createConnection($organization);

        $this->assertSame($organization->id, $connection->organization_id);
        $this->assertSame(['username' => 'hub-read', 'password' => 'test-secret'], $connection->credentials);
        $this->assertNotSame('test-secret', $connection->getRawOriginal('credentials'));

        $document = Document::factory()->for($connection, 'connection')->create();
        $unit = Unit::factory()->create();
        $contact = Contact::factory()->for($organization)->create();

        $this->assertSame($organization->id, $document->organization_id);
        $this->assertSame(hash('sha256', $document->external_id), $document->external_id_hash);
        $this->assertSame($unit->property->organization_id, $unit->organization_id);
        $this->assertSame($contact->id, Contact::findByExternalId('immoware24', $contact->external_id)?->id);
    }
}
