<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Ai: KI-Läufe (Kosten, Modell, Schema-Ergebnis) und Vorschläge, die erst nach menschlicher Bestätigung wirken.
 * Es werden nur maskierte Eingaben und Hashes gespeichert, keine Prompt-Klartexte mit personenbezogenen Daten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->nullable()->constrained('mail_cases')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            // classify_case, extract_change, summarize, draft_reply
            $table->string('task', 32);
            $table->string('provider', 16)->default('openai');
            $table->string('model', 80)->nullable();
            $table->char('input_hash', 64);
            $table->char('schema_hash', 64);
            // pending, succeeded, schema_invalid, failed, budget_exceeded, not_configured
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_class', 200)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'created_at']);
            $table->index('case_id');
            $table->index('status');
        });

        Schema::create('mail_ai_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_run_id')->constrained('mail_ai_runs')->cascadeOnDelete();
            $table->foreignId('case_id')->nullable()->constrained('mail_cases')->nullOnDelete();
            // classification, priority, assignment, extraction, summary, reply_draft
            $table->string('suggestion_type', 32);
            $table->json('payload_json');
            $table->unsignedTinyInteger('confidence_percent')->nullable();
            // proposed, accepted, rejected, superseded
            $table->string('status', 16)->default('proposed');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'suggestion_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_ai_suggestions');
        Schema::dropIfExists('mail_ai_runs');
    }
};
