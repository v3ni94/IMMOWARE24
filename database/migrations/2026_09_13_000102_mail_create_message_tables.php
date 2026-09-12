<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Gmail: Threads, Nachrichten, MIME-Teile, Anhänge, Push-Events (docs/mail/02-datenmodell.md, Abschnitt 2).
 * Unique (mailbox_id, gmail_message_id): ein Retry importiert nie doppelt. is_read_in_gmail ist informativ,
 * gelesen ist nicht bearbeitet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_threads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->restrictOnDelete();
            $table->string('gmail_thread_id', 32);
            $table->string('subject_normalized', 500)->nullable();
            $table->timestamp('first_message_at')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->timestamps();
            $table->unique(['mailbox_id', 'gmail_thread_id']);
            $table->index('last_message_at');
        });

        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('mailbox_id')->constrained('mail_mailboxes')->restrictOnDelete();
            $table->foreignId('thread_id')->nullable()->constrained('mail_threads')->nullOnDelete();
            $table->string('gmail_message_id', 32);
            $table->string('gmail_history_id', 32)->nullable();
            $table->string('rfc_message_id', 998)->nullable();
            // Indexierbare Kurzform der Message-ID (SHA-256), da VARCHAR(998) auf MariaDB nicht voll indexierbar ist.
            $table->char('rfc_message_id_hash', 64)->nullable();
            $table->string('in_reply_to', 998)->nullable();
            $table->json('references_json')->nullable();
            $table->string('direction', 8)->default('inbound');
            $table->string('from_address', 254);
            $table->string('from_name', 200)->nullable();
            $table->json('to_json')->nullable();
            $table->json('cc_json')->nullable();
            $table->json('bcc_json')->nullable();
            $table->string('reply_to', 254)->nullable();
            $table->string('subject', 998)->nullable();
            $table->string('snippet', 500)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('imported_at');
            $table->json('label_ids_json')->nullable();
            $table->boolean('is_read_in_gmail')->default(false);
            $table->boolean('has_attachments')->default(false);
            $table->unsignedBigInteger('size_estimate')->nullable();
            $table->longText('body_text')->nullable();
            $table->longText('body_html_sanitized')->nullable();
            $table->timestamp('body_fetched_at')->nullable();
            $table->foreignId('raw_payload_id')->nullable()->constrained('external_payloads')->nullOnDelete();
            $table->char('checksum', 64);
            // imported, classified, assigned, ignored, failed (technisch, kein Bearbeitungsstatus)
            $table->string('processing_status', 16)->default('imported');
            $table->foreignId('sender_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            // none, identifier, manual (nie name)
            $table->string('sender_match_method', 24)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['mailbox_id', 'gmail_message_id']);
            $table->index('thread_id');
            $table->index('received_at');
            $table->index('from_address');
            $table->index('rfc_message_id_hash');
            $table->index('processing_status');
            $table->index('sender_contact_id');
            $table->index(['organization_id', 'received_at']);
        });

        Schema::create('mail_message_parts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('mail_messages')->cascadeOnDelete();
            $table->string('part_id', 64);
            $table->string('parent_part_id', 64)->nullable();
            $table->string('mime_type', 120);
            $table->string('filename', 255)->nullable();
            $table->string('content_id', 255)->nullable();
            $table->string('disposition', 20)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('gmail_attachment_id', 255)->nullable();
            $table->json('headers_json')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['message_id', 'part_id']);
        });

        Schema::create('mail_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('message_id')->constrained('mail_messages')->restrictOnDelete();
            $table->foreignId('part_id')->nullable()->constrained('mail_message_parts')->nullOnDelete();
            $table->string('filename', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->char('sha256', 64)->nullable();
            $table->string('storage_disk', 40)->nullable();
            $table->string('storage_path', 512)->nullable();
            $table->timestamp('fetched_at')->nullable();
            // pending, clean, blocked, skipped
            $table->string('scan_status', 16)->default('pending');
            $table->foreignId('immoware_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index('message_id');
            $table->index('sha256');
        });

        Schema::create('mail_push_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('mailbox_id')->nullable()->constrained('mail_mailboxes')->cascadeOnDelete();
            $table->string('pubsub_message_id', 64);
            $table->string('email_address', 254);
            $table->string('history_id', 32);
            $table->timestamp('publish_time')->nullable();
            $table->timestamp('received_at');
            // ok, invalid_jwt, missing, bad_audience, bad_email, token_mismatch
            $table->string('auth_result', 16);
            $table->timestamp('processed_at')->nullable();
            // queued, duplicate, ignored, failed
            $table->string('outcome', 16)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique('pubsub_message_id');
            $table->index(['mailbox_id', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_push_events');
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('mail_message_parts');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_threads');
    }
};
