<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Der Datenbanknutzer der Anwendung erhält in Produktion nur INSERT und SELECT (08-security.md).
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at', 6);
            $table->foreignId('organization_id')->nullable()->constrained('organizations');
            $table->string('actor_type', 16)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source', 32)->default('system');
            $table->string('action', 80);
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->char('ip_address_hash', 64)->nullable();
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64);
            $table->timestamp('created_at', 6)->nullable();
            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_type', 'actor_id', 'occurred_at']);
            $table->index('occurred_at');
            $table->index(['connection_id', 'occurred_at']);
            $table->index('correlation_id');
            $table->index('action');
        });

        Schema::create('audit_anchors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('last_audit_id')->unique();
            $table->char('root_hash', 64);
            $table->timestamp('exported_at', 6);
            $table->string('external_location', 512);
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_anchors');
        Schema::dropIfExists('audit_logs');
    }
};
