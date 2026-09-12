<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('connection_id')->constrained('immoware_connections');
            $table->string('path', 2048);
            $table->char('path_hash', 64);
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->unsignedSmallInteger('depth')->default(0);
            $table->unsignedTinyInteger('scan_priority')->default(3);
            $table->unsignedInteger('scan_interval_seconds')->default(86400);
            $table->string('content_policy', 16)->default('metadata_only');
            $table->char('children_fingerprint', 64)->nullable();
            $table->timestamp('last_scanned_at')->nullable();
            $table->timestamp('last_change_seen_at')->nullable();
            $table->boolean('writable_by_hub')->default(false);
            $table->timestamp('missing_since')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['connection_id', 'path_hash']);
            $table->index(['connection_id', 'scan_priority', 'last_scanned_at']);
            $table->index('parent_id');
            $table->index('deleted_at');
        });

        Schema::create('documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->string('path', 2048);
            $table->char('path_hash', 64);
            $table->string('filename', 512);
            $table->string('content_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('remote_etag', 255)->nullable();
            $table->timestamp('remote_last_modified')->nullable();
            $table->char('content_hash', 64)->nullable();
            $table->boolean('content_stored')->default(false);
            $table->string('storage_key', 512)->nullable();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('cases')->nullOnDelete();
            $table->string('origin', 16)->default('remote');
            $table->unsignedBigInteger('write_operation_id')->nullable();
            $table->externalIdentity(uniqueExternal: false);
            $table->unique(['connection_id', 'path_hash']);
            $table->index('content_hash');
            $table->index(['connection_id', 'folder_id']);
            $table->index('remote_last_modified');
            $table->index('missing_since');
            $table->index('write_operation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_folders');
    }
};
