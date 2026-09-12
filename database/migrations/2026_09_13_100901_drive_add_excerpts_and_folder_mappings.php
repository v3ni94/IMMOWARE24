<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Drive, additiv: Textauszug und Hashes je Dokumentreferenz (Suchindex, wird bei Rechteentzug entfernt) sowie
 * Zuordnung Objekt zu Drive-Ordner aus Hub-Daten (mail_drive_folder_mappings). Es werden nie Ordner angelegt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_document_references', function (Blueprint $table): void {
            $table->text('text_excerpt')->nullable();
            $table->char('excerpt_hash', 64)->nullable();
            $table->char('content_sha256', 64)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->timestamp('excerpt_indexed_at')->nullable();
            $table->timestamp('access_checked_at')->nullable();
            // ok, revoked, unknown
            $table->string('access_status', 16)->nullable();
        });

        Schema::create('mail_drive_folder_mappings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('drive_connection_id')->nullable()->constrained('mail_drive_connections')->nullOnDelete();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('folder_id', 128);
            $table->string('folder_name', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['property_id', 'folder_id']);
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_drive_folder_mappings');

        Schema::table('mail_document_references', function (Blueprint $table): void {
            $table->dropColumn(['text_excerpt', 'excerpt_hash', 'content_sha256', 'size_bytes', 'excerpt_indexed_at', 'access_checked_at', 'access_status']);
        });
    }
};
