<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Mail: Teams, Teammitglieder, Postfächer, Aliasse, Postfachrechte, Sync-Zustand (docs/mail/02-datenmodell.md, Abschnitt 1).
 * Additiv, Präfix mail_ (die Tabelle cases ist vom Modul Estate belegt). SQLite- und MariaDB-kompatibel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_teams', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('name', 120);
            $table->string('slug', 60);
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('escalation_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('settings_json')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'slug']);
            $table->unique(['organization_id', 'name']);
        });

        Schema::create('mail_team_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('mail_teams')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // admin, lead, agent, approver, auditor (config hub.mail.team_roles)
            $table->string('team_role', 24)->default('agent');
            $table->date('active_from')->nullable();
            $table->date('active_until')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('mail_mailboxes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('team_id')->nullable()->constrained('mail_teams')->nullOnDelete();
            $table->string('label', 120);
            $table->string('email_address', 254);
            $table->string('provider', 16)->default('gmail');
            $table->string('legal_entity_code', 32);
            $table->string('oauth_client_id', 200)->nullable();
            $table->text('oauth_refresh_token')->nullable();
            $table->text('oauth_access_token')->nullable();
            $table->timestamp('oauth_token_expires_at')->nullable();
            $table->json('oauth_scopes_json')->nullable();
            $table->foreignId('oauth_granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('oauth_granted_at')->nullable();
            $table->boolean('import_enabled')->default(false);
            $table->timestamp('import_from')->nullable();
            // not_configured, configured, active, degraded, revoked
            $table->string('status', 16)->default('not_configured');
            $table->string('status_reason', 200)->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->string('last_error_class', 200)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'email_address']);
            $table->index('status');
            $table->index('team_id');
        });

        Schema::create('mail_mailbox_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->string('send_as_email', 254);
            $table->string('display_name', 200)->nullable();
            $table->string('reply_to', 254)->nullable();
            $table->string('legal_entity_code', 32);
            $table->string('verification_status', 16)->default('unknown');
            $table->boolean('is_default')->default(false);
            $table->boolean('is_primary')->default(false);
            $table->string('signature_key', 60)->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'send_as_email']);
        });

        Schema::create('mail_mailbox_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('can_read')->default(false);
            $table->boolean('can_draft')->default(false);
            $table->boolean('can_send')->default(false);
            $table->boolean('can_assign')->default(false);
            $table->boolean('can_view_bank_data')->default(false);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
            $table->unique(['mailbox_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('mail_sync_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->string('last_history_id', 32)->nullable();
            $table->timestamp('history_id_updated_at')->nullable();
            $table->timestamp('watch_expiration')->nullable();
            $table->timestamp('watch_requested_at')->nullable();
            $table->timestamp('watch_confirmed_at')->nullable();
            // none, requested, active, expired, failed (HTTP 200 auf watch ist nur requested)
            $table->string('watch_status', 16)->default('none');
            $table->string('full_sync_cursor', 255)->nullable();
            $table->timestamp('full_sync_started_at')->nullable();
            $table->timestamp('full_sync_finished_at')->nullable();
            $table->timestamp('last_incremental_at')->nullable();
            $table->string('lock_owner', 64)->nullable();
            $table->timestamps();
            $table->unique('mailbox_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_states');
        Schema::dropIfExists('mail_mailbox_permissions');
        Schema::dropIfExists('mail_mailbox_aliases');
        Schema::dropIfExists('mail_mailboxes');
        Schema::dropIfExists('mail_team_members');
        Schema::dropIfExists('mail_teams');
    }
};
