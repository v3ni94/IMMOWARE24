<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $table->unsignedBigInteger('api_key_id')->nullable();
            $table->string('idempotency_key', 128);
            $table->string('method', 8);
            $table->string('path', 512);
            $table->char('request_hash', 64);
            $table->smallInteger('response_status')->nullable();
            $table->json('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['api_key_id', 'idempotency_key', 'method', 'path'], 'api_idem_key_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
