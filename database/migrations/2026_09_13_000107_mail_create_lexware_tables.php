<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Lexware: Verbindung (API-Key verschlüsselt) und Kontakt-Snapshots für Alt/Neu und Versionsvergleich.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_lexware_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('label', 120);
            $table->string('legal_entity_code', 32);
            $table->string('base_url', 200);
            // Cast encrypted
            $table->text('api_key')->nullable();
            $table->string('api_key_fingerprint', 16)->nullable();
            // not_configured, configured, active, degraded, revoked
            $table->string('status', 16)->default('not_configured');
            $table->string('status_reason', 200)->nullable();
            $table->boolean('write_enabled')->default(false);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_class', 200)->nullable();
            $table->foreignId('configured_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'legal_entity_code']);
            $table->index('status');
        });

        Schema::create('mail_lexware_contact_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('lexware_connection_id')->constrained('mail_lexware_connections')->restrictOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('mail_cases')->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('lexware_contact_id', 64);
            $table->unsignedInteger('lexware_version')->nullable();
            // Rohantwort ohne Bankdaten (maskiert), json
            $table->json('snapshot_json')->nullable();
            $table->char('snapshot_hash', 64)->nullable();
            // before_change, after_change, verification, lookup
            $table->string('purpose', 24)->default('lookup');
            $table->timestamp('fetched_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['lexware_contact_id', 'fetched_at']);
            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_lexware_contact_snapshots');
        Schema::dropIfExists('mail_lexware_connections');
    }
};
