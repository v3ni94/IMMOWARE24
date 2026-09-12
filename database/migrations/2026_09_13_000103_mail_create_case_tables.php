<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Cases: Vorgänge, Teilanliegen, Nachrichtenzuordnung, externe Referenzen, Zuweisungsentscheidungen, Aufgaben
 * (docs/mail/02-datenmodell.md, Abschnitt 3). Jeder offene Vorgang hat Verantwortlichen, nächsten Schritt und Fälligkeit
 * (Anwendungslogik CaseService). Alt/Neu-Werte bei Bankdaten verschlüsselt (Cast encrypted:array).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('case_number', 32);
            $table->foreignId('mailbox_id')->nullable()->constrained('mail_mailboxes')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('mail_teams')->nullOnDelete();
            $table->string('case_type', 32)->default('sonstiges');
            $table->string('title', 300);
            // p0, p1, p2, p3 (App\Modules\Cases\Enums\Priority)
            $table->string('priority', 2)->default('p2');
            $table->string('priority_reason', 200)->nullable();
            $table->string('status_processing', 24)->default('new');
            $table->string('status_communication', 24)->default('reply_needed');
            $table->string('status_business', 24)->default('proposed');
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('next_step', 500)->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_reason', 200)->nullable();
            $table->foreignId('primary_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('property_id')->nullable()->constrained('properties')->nullOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->foreignId('immoware_connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->string('legal_entity_code', 32)->nullable();
            $table->text('ai_summary')->nullable();
            $table->json('ai_classification_json')->nullable();
            $table->json('tags_json')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique('case_number');
            $table->index(['status_processing', 'due_at']);
            $table->index(['assignee_user_id', 'status_processing']);
            $table->index(['priority', 'opened_at']);
            $table->index('property_id');
            $table->index('unit_id');
            $table->index('primary_contact_id');
            $table->index('team_id');
            $table->index('mailbox_id');
            $table->index(['organization_id', 'status_processing']);
            $table->index('due_at');
        });

        Schema::create('mail_case_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->default(1);
            $table->string('item_type', 32)->default('sonstiges');
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('status_processing', 24)->default('new');
            $table->string('status_communication', 24)->default('reply_needed');
            $table->string('status_business', 24)->default('proposed');
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            $table->foreignId('source_message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['case_id', 'status_processing']);
            $table->index(['assignee_user_id', 'due_at']);
        });

        Schema::create('mail_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('message_id')->constrained('mail_messages')->restrictOnDelete();
            // origin, followup, reply, forwarded, manual
            $table->string('link_type', 16)->default('origin');
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['case_id', 'message_id']);
            $table->index('message_id');
        });

        Schema::create('mail_case_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            // immoware24, lexware, gmail, drive
            $table->string('target_system', 24);
            $table->string('reference_type', 32);
            $table->string('external_id', 255);
            $table->char('external_id_hash', 64);
            $table->unsignedBigInteger('local_id')->nullable();
            $table->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['case_id', 'target_system', 'reference_type', 'external_id_hash'], 'mail_case_references_unique');
        });

        Schema::create('mail_assignment_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            // contact, property, unit, contract, team, assignee, priority, case_type
            $table->string('decision_type', 24);
            $table->json('proposed_value_json')->nullable();
            $table->string('chosen_value', 255)->nullable();
            $table->unsignedBigInteger('chosen_local_id')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            // identifier, rule, manual, ai_confirmed (nie ai_unconfirmed)
            $table->string('decision_basis', 24);
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->nullable();
            $table->index(['case_id', 'decision_type']);
        });

        Schema::create('mail_tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->nullable()->constrained('mail_cases')->nullOnDelete();
            $table->foreignId('case_item_id')->nullable()->constrained('mail_case_items')->nullOnDelete();
            $table->string('task_type', 32)->default('other');
            $table->string('title', 300);
            $table->text('instructions')->nullable();
            // Verschlüsselt (Cast encrypted:array), daher text statt json.
            $table->text('old_value_json')->nullable();
            $table->text('new_value_json')->nullable();
            $table->string('target_system', 24)->nullable();
            $table->foreignId('assignee_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('due_at')->nullable();
            // open, in_progress, waiting, done_manual_confirmed, done_verified, cancelled
            $table->string('status', 24)->default('open');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('proposed_change_id')->nullable()->constrained('proposed_changes')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['assignee_user_id', 'status', 'due_at']);
            $table->index('case_id');
            $table->index('status');
            $table->index(['organization_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_tasks');
        Schema::dropIfExists('mail_assignment_decisions');
        Schema::dropIfExists('mail_case_references');
        Schema::dropIfExists('mail_case_messages');
        Schema::dropIfExists('mail_case_items');
        Schema::dropIfExists('mail_cases');
    }
};
