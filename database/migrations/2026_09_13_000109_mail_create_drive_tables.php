<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Drive: Verbindung (OAuth-Tokens verschlüsselt, nur lesend) und Dokumentreferenzen je Vorgang
 * (docs/mail/02-datenmodell.md, Abschnitt 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_drive_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('label', 120);
            $table->string('account_email', 254)->nullable();
            $table->string('root_folder_id', 128)->nullable();
            // Casts encrypted
            $table->text('oauth_refresh_token')->nullable();
            $table->text('oauth_access_token')->nullable();
            $table->timestamp('oauth_token_expires_at')->nullable();
            $table->json('oauth_scopes_json')->nullable();
            $table->foreignId('oauth_granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('oauth_granted_at')->nullable();
            // not_configured, configured, active, degraded, revoked
            $table->string('status', 16)->default('not_configured');
            $table->string('status_reason', 200)->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_class', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('mail_document_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            // drive, immoware, attachment
            $table->string('source', 16);
            $table->foreignId('drive_connection_id')->nullable()->constrained('mail_drive_connections')->nullOnDelete();
            $table->string('drive_file_id', 128)->nullable();
            $table->foreignId('immoware_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained('mail_attachments')->nullOnDelete();
            $table->string('name', 255);
            $table->string('mime_type', 120)->nullable();
            $table->string('web_view_link', 1024)->nullable();
            $table->json('permissions_summary_json')->nullable();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('case_id');
            $table->index('drive_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_document_references');
        Schema::dropIfExists('mail_drive_connections');
    }
};
