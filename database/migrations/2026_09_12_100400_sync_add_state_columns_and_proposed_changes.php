<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive Ergänzungen des Moduls Sync. Bestehende Migrationen bleiben unverändert.
        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->string('entity_type', 40)->nullable()->after('connection_id');
            $table->unsignedBigInteger('duration_ms')->nullable()->after('finished_at');
            $table->unsignedInteger('chunks')->default(0)->after('duration_ms');
            $table->index(['connection_id', 'entity_type', 'status'], 'sync_runs_conn_entity_status_idx');
        });

        Schema::table('sync_states', function (Blueprint $table): void {
            $table->timestamp('last_success_at')->nullable()->after('last_synced_at');
            $table->timestamp('last_failure_at')->nullable()->after('last_success_at');
            $table->text('last_error')->nullable()->after('last_failure_at');
            $table->timestamp('stale_since')->nullable()->after('last_error');
            $table->foreignId('last_run_id')->nullable()->after('stale_since')->constrained('sync_runs')->nullOnDelete();
            $table->index(['connection_id', 'entity_type', 'scope'], 'sync_states_conn_entity_scope_idx');
        });

        Schema::table('field_mappings', function (Blueprint $table): void {
            $table->string('source_system', 32)->default('immoware24')->after('id');
            $table->foreignId('previous_version_id')->nullable()->after('retired_at')->constrained('field_mappings')->nullOnDelete();
        });

        Schema::table('dlq_items', function (Blueprint $table): void {
            $table->string('entity_type', 40)->nullable()->after('connection_id');
            $table->string('status', 16)->default('open')->after('replay_result');
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->index(['status', 'failed_at']);
        });

        // Änderungswünsche an Immoware24-Daten, die manuell im Mastersystem umgesetzt werden (kein Writeback).
        Schema::create('proposed_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('field', 120);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('reason')->nullable();
            $table->string('status', 24)->default('open');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('transferred_at')->nullable();
            $table->foreignId('transferred_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by_sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestamps();
            $table->index(['entity_type', 'entity_id']);
            $table->index(['status', 'created_at']);
            $table->index(['connection_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposed_changes');

        Schema::table('dlq_items', function (Blueprint $table): void {
            $table->dropIndex(['status', 'failed_at']);
            $table->dropColumn(['entity_type', 'status', 'attempts']);
        });

        Schema::table('field_mappings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('previous_version_id');
            $table->dropColumn('source_system');
        });

        Schema::table('sync_states', function (Blueprint $table): void {
            $table->dropIndex('sync_states_conn_entity_scope_idx');
            $table->dropConstrainedForeignId('last_run_id');
            $table->dropColumn(['last_success_at', 'last_failure_at', 'last_error', 'stale_since']);
        });

        Schema::table('sync_runs', function (Blueprint $table): void {
            $table->dropIndex('sync_runs_conn_entity_status_idx');
            $table->dropColumn(['entity_type', 'duration_ms', 'chunks']);
        });
    }
};
