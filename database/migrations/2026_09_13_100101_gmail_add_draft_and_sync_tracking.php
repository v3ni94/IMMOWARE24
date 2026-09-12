<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Gmail, additiv: Entwurfsnachverfolgung (Inhaltshash, Revision, entfernte Nachrichten-ID, eigene RFC-Message-ID,
 * Zustellstatus immer unknown), Versandabgleich-Ergebnis am Entwurf, Sync-Zustand um Resync-Lücken und Watch-Alarm.
 * Keine destruktiven Änderungen; down() entfernt nur die hier ergänzten Spalten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_drafts', function (Blueprint $table): void {
            // SHA-256 über den normalisierten Entwurfsinhalt; update prüft gegen den zuletzt gespeicherten Hash (Konflikt).
            $table->char('content_hash', 64)->nullable()->after('gmail_draft_id');
            $table->unsignedInteger('revision')->default(0)->after('content_hash');
            $table->string('gmail_draft_message_id', 32)->nullable()->after('revision');
            $table->string('rfc_message_id', 998)->nullable()->after('gmail_draft_message_id');
            $table->string('gmail_thread_id', 32)->nullable()->after('rfc_message_id');
            // Ergebnis des Versandabgleichs: null, sent_verified, sent_unverified, unclear.
            $table->string('send_verification', 16)->nullable()->after('gmail_thread_id');
            // Gesendet ist nie zugestellt: bleibt immer unknown (kein DSN-Auswertung).
            $table->string('delivery_status', 16)->default('unknown')->after('send_verification');
            $table->timestamp('remote_checked_at')->nullable()->after('delivery_status');
        });

        Schema::table('mail_sync_states', function (Blueprint $table): void {
            $table->timestamp('last_full_resync_at')->nullable()->after('full_sync_finished_at');
            $table->unsignedInteger('last_resync_gap_count')->default(0)->after('last_full_resync_at');
            $table->timestamp('watch_alerted_at')->nullable()->after('watch_status');
            $table->timestamp('last_reconcile_at')->nullable()->after('last_incremental_at');
            $table->unsignedInteger('last_reconcile_gap_count')->default(0)->after('last_reconcile_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_drafts', function (Blueprint $table): void {
            $table->dropColumn(['content_hash', 'revision', 'gmail_draft_message_id', 'rfc_message_id', 'gmail_thread_id', 'send_verification', 'delivery_status', 'remote_checked_at']);
        });

        Schema::table('mail_sync_states', function (Blueprint $table): void {
            $table->dropColumn(['last_full_resync_at', 'last_resync_gap_count', 'watch_alerted_at', 'last_reconcile_at', 'last_reconcile_gap_count']);
        });
    }
};
