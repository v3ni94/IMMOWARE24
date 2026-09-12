<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_formats', function (Blueprint $table): void {
            $table->id();
            $table->string('format_key', 64);
            $table->unsignedInteger('version');
            $table->string('status', 16)->default('draft');
            $table->char('header_fingerprint', 64);
            $table->json('header_columns');
            $table->char('delimiter', 1)->nullable();
            $table->string('charset', 20)->nullable();
            $table->json('column_mapping');
            $table->json('key_schema');
            $table->foreignId('sample_payload_id')->nullable()->constrained('external_payloads')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->unique(['format_key', 'version']);
            $table->unique('header_fingerprint');
            $table->index(['format_key', 'status']);
        });

        Schema::create('import_files', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->foreignId('import_format_id')->nullable()->constrained('import_formats')->nullOnDelete();
            $table->foreignId('payload_id')->nullable()->constrained('external_payloads')->nullOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->string('original_filename', 512);
            $table->string('file_type', 32);
            $table->char('content_hash', 64);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_key', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 16)->default('received');
            $table->unsignedInteger('rows_total')->nullable();
            $table->unsignedInteger('rows_imported')->nullable();
            $table->unsignedInteger('rows_failed')->nullable();
            $table->text('error_summary')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at', 6);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index('content_hash');
            $table->index('received_at');
        });

        Schema::create('export_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->string('export_type', 64);
            $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('interval_days');
            $table->timestamp('last_import_at')->nullable();
            $table->timestamp('next_due_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'export_type']);
            $table->index('next_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_schedules');
        Schema::dropIfExists('import_files');
        Schema::dropIfExists('import_formats');
    }
};
