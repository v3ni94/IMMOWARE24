<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Cases, additiv: Bearbeitungssperren, Abwesenheiten mit Stellvertretung, regelbasierte Zuordnungsregeln
 * (bestätigte Absender), Statusprotokoll der drei Dimensionen sowie Zusatzspalten an mail_cases und mail_case_items
 * (Folgevorgang, Archivierung, Ausnahmeabschluss, Warten auf Externe, Priorität je Teilanliegen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_cases', function (Blueprint $table): void {
            $table->foreignId('parent_case_id')->nullable()->after('team_id')->constrained('mail_cases')->nullOnDelete();
            $table->timestamp('archived_at')->nullable()->after('close_reason');
            $table->boolean('closed_by_exception')->default(false)->after('archived_at');
            $table->string('communication_waived_reason', 300)->nullable()->after('closed_by_exception');
            $table->timestamp('reopened_at')->nullable()->after('communication_waived_reason');
            $table->unsignedSmallInteger('reopen_count')->default(0)->after('reopened_at');
        });

        Schema::table('mail_case_items', function (Blueprint $table): void {
            $table->string('priority', 2)->default('p2')->after('status_business');
            $table->string('priority_reason', 200)->nullable()->after('priority');
            $table->string('next_step', 500)->nullable()->after('assignee_user_id');
            $table->string('waiting_external_party', 200)->nullable()->after('due_at');
            $table->timestamp('follow_up_at')->nullable()->after('waiting_external_party');
            $table->timestamp('next_customer_update_at')->nullable()->after('follow_up_at');
            $table->timestamp('received_at')->nullable()->after('source_message_id');
        });

        Schema::create('mail_case_locks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('acquired_at');
            $table->timestamp('heartbeat_at');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique('case_id');
            $table->index(['user_id', 'expires_at']);
        });

        Schema::create('mail_absences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('substitute_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 120)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['user_id', 'starts_at', 'ends_at']);
        });

        Schema::create('mail_assignment_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            // sender_email, sender_domain, external_id
            $table->string('rule_type', 24)->default('sender_email');
            $table->string('match_value', 254);
            // contact, property, unit, contract
            $table->string('target_type', 16)->default('contact');
            $table->unsignedBigInteger('target_local_id');
            $table->unsignedTinyInteger('confidence')->default(90);
            $table->unsignedInteger('confirmed_count')->default(1);
            $table->timestamp('last_confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'rule_type', 'match_value', 'target_type', 'target_local_id'], 'mail_assignment_rules_unique');
            $table->index(['rule_type', 'match_value']);
        });

        Schema::create('mail_case_status_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->cascadeOnDelete();
            $table->foreignId('case_item_id')->nullable()->constrained('mail_case_items')->nullOnDelete();
            // processing, communication, business, assignment, lock, priority, archive
            $table->string('dimension', 16);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->string('reason', 500)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            // user, system, gmail, ai
            $table->string('source', 16)->default('user');
            $table->json('context_json')->nullable();
            $table->timestamp('changed_at');
            $table->index(['case_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_case_status_log');
        Schema::dropIfExists('mail_assignment_rules');
        Schema::dropIfExists('mail_absences');
        Schema::dropIfExists('mail_case_locks');

        Schema::table('mail_case_items', function (Blueprint $table): void {
            $table->dropColumn(['priority', 'priority_reason', 'next_step', 'waiting_external_party', 'follow_up_at', 'next_customer_update_at', 'received_at']);
        });

        Schema::table('mail_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('parent_case_id');
            $table->dropColumn(['archived_at', 'closed_by_exception', 'communication_waived_reason', 'reopened_at', 'reopen_count']);
        });
    }
};
