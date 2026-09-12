<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Gmail: Entwürfe und Versandabgleich (docs/mail/02-datenmodell.md, Abschnitt 5). Ein Versand gilt erst nach
 * Nachlesen der Nachricht mit Label SENT und erwarteter Message-ID als verifiziert (sent_verified), nie nach HTTP 200.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->nullable()->constrained('mail_cases')->nullOnDelete();
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->restrictOnDelete();
            $table->foreignId('alias_id')->nullable()->constrained('mail_mailbox_aliases')->nullOnDelete();
            $table->foreignId('reply_to_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->json('bcc_json')->nullable();
            $table->string('subject', 998);
            $table->longText('body_text');
            $table->longText('body_html')->nullable();
            $table->json('attachments_json')->nullable();
            // user, template, ai
            $table->string('generated_by', 16)->default('user');
            $table->char('ai_prompt_hash', 64)->nullable();
            // local, pending_approval, approved, pushed_to_gmail, sent_requested, sent_verified, send_failed, discarded
            $table->string('status', 24)->default('local');
            $table->string('gmail_draft_id', 64)->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sent_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_requested_at')->nullable();
            $table->foreignId('sent_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('case_id');
            $table->index('status');
            $table->index('gmail_draft_id');
            $table->index('mailbox_id');
        });

        Schema::create('mail_send_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('draft_id')->constrained('mail_drafts')->restrictOnDelete();
            $table->timestamp('requested_at');
            $table->string('gmail_response_message_id', 32)->nullable();
            $table->string('expected_rfc_message_id', 998);
            $table->timestamp('found_in_sent_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_check_at')->nullable();
            // pending, verified, not_found, mismatch
            $table->string('result', 16)->default('pending');
            $table->timestamps();
            $table->index(['result', 'next_check_at']);
            $table->index('draft_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_send_reconciliations');
        Schema::dropIfExists('mail_drafts');
    }
};
