<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive Erweiterungen des Moduls Imports:
 * - import_files: Exporttyp, Header-Fingerprint, Vollexport-Kennzeichen, Stichtag, Fehlerliste, Quarantänegrund
 * - open_items: settled_at (Snapshot-Logik, OP gelten zum Stichtag als erledigt, werden nie gelöscht)
 * - transactions: is_duplicate (DATEV-Duplikate werden gespeichert und markiert, nicht verworfen)
 * - hub_exports: asynchrone Exporte aus dem Hub (CSV, JSON) mit Status und Ablagepfad
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_files', function (Blueprint $table): void {
            $table->string('export_type', 64)->nullable()->after('file_type');
            $table->char('header_fingerprint', 64)->nullable()->after('content_hash');
            $table->boolean('is_full_export')->default(false)->after('metadata');
            $table->date('as_of_date')->nullable()->after('is_full_export');
            $table->timestamp('exported_at')->nullable()->after('as_of_date');
            $table->string('exported_by', 200)->nullable()->after('exported_at');
            $table->unsignedInteger('rows_rejected')->nullable()->after('rows_failed');
            $table->unsignedInteger('rows_duplicate')->nullable()->after('rows_rejected');
            $table->json('errors')->nullable()->after('error_summary');
            $table->string('quarantine_reason', 64)->nullable()->after('errors');
            $table->index('header_fingerprint');
            $table->index(['organization_id', 'export_type', 'processed_at'], 'import_files_org_type_processed_idx');
        });

        Schema::table('open_items', function (Blueprint $table): void {
            $table->date('settled_at')->nullable()->after('as_of_date');
            $table->index(['organization_id', 'settled_at'], 'open_items_org_settled_idx');
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->boolean('is_duplicate')->default(false)->after('occurrence_no');
            $table->unsignedBigInteger('import_file_id')->nullable()->after('import_payload_id');
            $table->index('import_file_id');
        });

        Schema::create('hub_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('entity', 64);
            $table->json('filter')->nullable();
            $table->string('format', 8);
            $table->string('status', 16)->default('pending');
            $table->string('storage_disk', 64)->nullable();
            $table->string('storage_key', 512)->nullable();
            $table->unsignedBigInteger('row_count')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hub_exports');

        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropIndex(['import_file_id']);
            $table->dropColumn(['is_duplicate', 'import_file_id']);
        });

        Schema::table('open_items', function (Blueprint $table): void {
            $table->dropIndex('open_items_org_settled_idx');
            $table->dropColumn('settled_at');
        });

        Schema::table('import_files', function (Blueprint $table): void {
            $table->dropIndex('import_files_org_type_processed_idx');
            $table->dropIndex(['header_fingerprint']);
            $table->dropColumn([
                'export_type', 'header_fingerprint', 'is_full_export', 'as_of_date', 'exported_at', 'exported_by',
                'rows_rejected', 'rows_duplicate', 'errors', 'quarantine_reason',
            ]);
        });
    }
};
