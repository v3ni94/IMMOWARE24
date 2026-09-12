<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('name', 120);
            $table->string('url', 1024);
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->json('events');
            $table->boolean('active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'name']);
            $table->index('active');
        });

        Schema::create('webhook_outbox', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('event_type', 80);
            $table->string('entity_type', 40)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('payload_json');
            $table->string('correlation_id', 128)->nullable();
            $table->timestamp('occurred_at', 6);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['dispatched_at', 'occurred_at']);
            $table->index(['organization_id', 'event_type']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->foreignId('outbox_id')->constrained('webhook_outbox')->cascadeOnDelete();
            $table->char('signature', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 16)->default('pending');
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->smallInteger('last_response_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['endpoint_id', 'outbox_id']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_outbox');
        Schema::dropIfExists('webhook_endpoints');
    }
};
