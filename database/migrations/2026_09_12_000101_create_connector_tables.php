<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 200);
            $table->string('legal_entity_code', 32)->unique();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('immoware_technical_users', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('label', 120);
            $table->string('username', 200);
            $table->text('secret')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->string('purpose', 8)->default('read');
            $table->string('breaker_state', 16)->default('closed');
            $table->string('status', 16)->default('paused');
            $table->timestamps();
            $table->unique(['organization_id', 'username']);
            $table->index(['purpose', 'status']);
        });

        Schema::create('immoware_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('technical_user_id')->nullable()->constrained('immoware_technical_users')->nullOnDelete();
            $table->string('name', 120);
            $table->string('connector_type', 32);
            $table->text('base_url')->nullable();
            $table->char('base_url_hash', 64)->nullable();
            $table->text('credentials')->nullable();
            $table->string('auth_scheme', 16)->default('unknown');
            $table->char('server_fingerprint', 64)->nullable();
            $table->json('probe_result')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->string('purpose', 8)->default('read');
            $table->foreignId('paired_read_connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->boolean('write_enabled')->default(false);
            $table->foreignId('write_enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('write_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('write_approval_document_id')->nullable();
            $table->timestamp('write_enabled_at')->nullable();
            $table->string('allowed_write_prefix', 512)->nullable();
            $table->unsignedBigInteger('max_upload_bytes')->default(26214400);
            $table->boolean('verify_with_hash')->default(true);
            $table->unsignedInteger('poll_interval_seconds')->default(1800);
            $table->decimal('rate_limit_rps', 5, 2)->default(2.00);
            $table->unsignedTinyInteger('max_concurrency_read')->default(2);
            $table->unsignedTinyInteger('max_concurrency_write')->default(1);
            $table->string('status', 16)->default('paused');
            $table->string('degraded_reason', 200)->nullable();
            $table->foreignId('degraded_cleared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_health_at')->nullable();
            $table->boolean('last_health_ok')->nullable();
            $table->json('last_health_result')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->unique(['base_url_hash', 'technical_user_id']);
            $table->index(['connector_type', 'status']);
            $table->index('status');
        });

        Schema::create('capabilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('immoware_connections')->cascadeOnDelete();
            $table->string('capability_key', 64);
            $table->string('evidence_status', 32)->default('assumed');
            $table->string('source_url', 1024)->nullable();
            $table->boolean('enabled')->default(false);
            $table->boolean('hard_locked')->default(false);
            $table->timestamp('tested_at')->nullable();
            $table->foreignId('tested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('test_protocol')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'capability_key']);
            $table->index('capability_key');
        });

        Schema::create('remote_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->string('correlation_id', 128)->nullable();
            $table->string('method', 16);
            $table->string('path', 2048);
            $table->char('path_hash', 64);
            $table->json('request_headers_masked')->nullable();
            $table->smallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->unsignedBigInteger('response_bytes')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('outcome', 32)->default('unknown');
            $table->string('error_class', 200)->nullable();
            $table->text('error_message_masked')->nullable();
            $table->unsignedBigInteger('payload_id')->nullable();
            $table->timestamp('requested_at', 6);
            $table->timestamps();
            $table->index(['connection_id', 'requested_at']);
            $table->index('correlation_id');
            $table->index(['method', 'response_status']);
            $table->index('path_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remote_requests');
        Schema::dropIfExists('capabilities');
        Schema::dropIfExists('immoware_connections');
        Schema::dropIfExists('immoware_technical_users');
        Schema::dropIfExists('organizations');
    }
};
