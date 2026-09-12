<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->string('correlation_id', 128)->nullable();
            $table->string('run_type', 32);
            $table->string('mode', 16)->default('incremental');
            $table->string('trigger_source', 16)->default('schedule');
            $table->string('phase', 16)->default('discover');
            $table->string('status', 16)->default('pending');
            $table->timestamp('started_at', 6);
            $table->timestamp('finished_at', 6)->nullable();
            $table->boolean('health_ok_before')->nullable();
            $table->json('counters');
            $table->json('cursor_before')->nullable();
            $table->json('cursor_after')->nullable();
            $table->text('error_summary')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['connection_id', 'started_at']);
            $table->index('phase');
            $table->index('status');
            $table->index('correlation_id');
        });

        Schema::create('external_payloads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->string('payload_type', 32);
            $table->char('external_id_hash', 64)->nullable();
            $table->char('content_hash', 64);
            $table->binary('content_inline')->nullable();
            $table->string('storage_key', 512)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->smallInteger('http_status')->nullable();
            $table->string('remote_etag', 255)->nullable();
            $table->timestamp('remote_last_modified')->nullable();
            $table->json('import_metadata')->nullable();
            $table->timestamp('received_at', 6);
            $table->boolean('contains_personal_data')->default(false);
            $table->timestamp('pseudonymized_at')->nullable();
            // Duplikatsperre nur für Importdateien: wird von der Anwendung mit content_hash befüllt, sonst mit der eigenen ID.
            $table->char('dedup_hash', 64)->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'payload_type', 'dedup_hash']);
            $table->index(['external_id_hash', 'received_at']);
            $table->index('sync_run_id');
            $table->index('received_at');
            $table->index(['contains_personal_data', 'pseudonymized_at']);
        });

        Schema::create('external_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('source_system', 32);
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->string('entity_type', 40);
            $table->string('external_id', 512);
            $table->char('external_id_hash', 64);
            $table->unsignedBigInteger('entity_id');
            $table->string('identity_confidence', 16)->default('exact');
            $table->string('created_by', 16)->default('sync');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['source_system', 'connection_id', 'entity_type', 'external_id_hash'], 'external_mappings_identity_unique');
            $table->index(['entity_type', 'entity_id']);
            $table->index('created_by');
            $table->index('external_id_hash');
        });

        Schema::create('sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->string('entity_type', 40)->nullable();
            $table->string('scope', 16)->default('collection');
            $table->char('collection_path_hash', 64);
            $table->char('resource_external_id_hash', 64)->default('');
            $table->string('strategy', 32)->nullable();
            $table->string('sync_token', 1024)->nullable();
            $table->string('ctag', 255)->nullable();
            $table->string('etag', 255)->nullable();
            $table->timestamp('last_modified')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('last_seen_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->unsignedTinyInteger('consecutive_missing')->default(0);
            $table->json('cursor_json')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'scope', 'collection_path_hash', 'resource_external_id_hash'], 'sync_states_scope_unique');
            $table->index(['connection_id', 'last_seen_run_id']);
            $table->index(['connection_id', 'consecutive_missing']);
        });

        Schema::create('sync_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sync_run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id');
            $table->string('action', 32);
            $table->string('detected_by', 32);
            $table->char('old_checksum', 64)->nullable();
            $table->char('new_checksum', 64)->nullable();
            $table->foreignId('payload_id')->nullable()->constrained('external_payloads')->nullOnDelete();
            $table->timestamp('occurred_at', 6);
            $table->timestamps();
            $table->index(['entity_type', 'entity_id', 'occurred_at']);
            $table->index('sync_run_id');
            $table->index(['connection_id', 'occurred_at']);
        });

        Schema::create('conflicts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->string('entity_type', 40);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('conflict_type', 40);
            $table->string('conflict_state', 16)->default('both_changed');
            $table->foreignId('remote_payload_id')->nullable()->constrained('external_payloads')->nullOnDelete();
            $table->json('local_snapshot_json')->nullable();
            $table->json('proposed_change_json')->nullable();
            $table->string('status', 32)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('confirmed_by_sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->unsignedInteger('occurrences')->default(1);
            $table->foreignId('last_seen_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            // Höchstens ein offener Konflikt je Datensatz und Typ: Anwendung setzt open_key = 1 bei open/in_progress, sonst NULL.
            $table->boolean('open_key')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'entity_id', 'conflict_type', 'open_key'], 'conflicts_single_open_unique');
            $table->index(['status', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['conflict_type', 'status']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('write_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid')->unique();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->char('idempotency_key', 64)->unique();
            $table->char('intent_key', 64);
            $table->char('payload_hash', 64);
            $table->string('operation', 32)->default('webdav_create');
            $table->string('target_path', 2048);
            $table->char('target_path_hash', 64);
            $table->string('original_filename', 512)->nullable();
            $table->string('sanitized_filename', 160);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('source_storage_key', 512)->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->json('precheck_result')->nullable();
            $table->smallInteger('http_status')->nullable();
            $table->json('verify_result')->nullable();
            $table->unsignedTinyInteger('precheck_attempts')->default(0);
            $table->unsignedTinyInteger('verify_attempts')->default(0);
            $table->unsignedTinyInteger('put_attempts')->default(0);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('requested_via', 16)->default('ui');
            $table->string('correlation_id', 128)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['connection_id', 'status']);
            $table->index('target_path_hash');
            $table->index(['status', 'sent_at']);
            $table->index(['connection_id', 'content_hash']);
            $table->index('status');
        });

        Schema::create('dlq_items', function (Blueprint $table): void {
            $table->id();
            $table->string('job_class', 200);
            $table->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->string('queue', 32)->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->json('payload_json');
            $table->text('exception');
            $table->timestamp('failed_at', 6);
            $table->timestamp('replayed_at')->nullable();
            $table->foreignId('replayed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('replay_result', 40)->nullable();
            $table->timestamps();
            $table->index(['connection_id', 'failed_at']);
            $table->index('replayed_at');
            $table->index('job_class');
        });

        Schema::create('field_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('entity_type', 40);
            $table->string('source_format', 40);
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->json('mapping');
            $table->json('key_schema')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();
            $table->unique(['entity_type', 'source_format', 'version']);
            $table->index(['entity_type', 'status']);
        });

        Schema::create('hub_decision_backups', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('taken_at', 6);
            $table->json('tables_included');
            $table->string('storage_key', 512);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('restore_tested_at')->nullable();
            $table->foreignId('restore_tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('taken_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_decision_backups');
        Schema::dropIfExists('field_mappings');
        Schema::dropIfExists('dlq_items');
        Schema::dropIfExists('write_operations');
        Schema::dropIfExists('conflicts');
        Schema::dropIfExists('sync_events');
        Schema::dropIfExists('sync_states');
        Schema::dropIfExists('external_mappings');
        Schema::dropIfExists('external_payloads');
        Schema::dropIfExists('sync_runs');
    }
};
