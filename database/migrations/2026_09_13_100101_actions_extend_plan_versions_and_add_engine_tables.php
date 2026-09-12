<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Actions, additiv zur Foundation (000106): Versionsfelder für die Action-Engine (Zielsystem, Referenz,
 * Alt/Neu verschlüsselt, Quelle, Risikoklasse, Wirksamkeitsdatum, Vorbedingungen, Freigabeanzahl,
 * Identitätsprüfung, diff_hash), Freigabeablauf und diff_hash je Freigabe, Identitätsprüfungen (Bankänderungen),
 * Zustand je Zielsystem (Mehrsystempläne) sowie Beleg- und Sperrfelder der Ausführung.
 * Keine destruktiven Änderungen, alle neuen Spalten nullable oder mit Default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_action_plan_versions', function (Blueprint $table): void {
            // App\Modules\Actions\Enums\TargetSystem des führenden Schritts
            $table->string('target_system', 24)->nullable()->after('steps_hash');
            // Aktionstyp aus der Allowlist (address_change, bank_change, note, manual_task)
            $table->string('action_type', 32)->nullable()->after('target_system');
            // reference_type, external_id, local_id, connection_id, contract_id, role
            $table->json('external_ref')->nullable()->after('action_type');
            $table->json('fields_json')->nullable()->after('external_ref');
            // Cast encrypted:array, deshalb text statt json
            $table->text('old_values')->nullable()->after('fields_json');
            $table->text('new_values')->nullable()->after('old_values');
            $table->foreignId('source_message_id')->nullable()->after('new_values')->constrained('mail_messages')->nullOnDelete();
            $table->char('source_hash', 64)->nullable()->after('source_message_id');
            // App\Modules\Actions\Enums\RiskClass
            $table->string('risk_class', 8)->nullable()->after('source_hash');
            $table->date('effective_date')->nullable()->after('risk_class');
            $table->json('preconditions_json')->nullable()->after('effective_date');
            $table->unsignedTinyInteger('required_approvals')->default(1)->after('preconditions_json');
            $table->boolean('requires_identity_check')->default(false)->after('required_approvals');
            // Hash über Alt/Neu je Schritt, Freigaben binden an plan_version_id plus diff_hash
            $table->char('diff_hash', 64)->nullable()->after('requires_identity_check');
            $table->timestamp('superseded_at')->nullable()->after('diff_hash');
        });

        Schema::table('mail_approvals', function (Blueprint $table): void {
            $table->char('diff_hash', 64)->nullable()->after('steps_hash');
            $table->timestamp('expires_at')->nullable()->after('reauth_confirmed_at');
        });

        Schema::create('mail_identity_checks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_plan_version_id')->constrained('mail_action_plan_versions')->restrictOnDelete();
            // phone_callback, in_person, video, letter, other
            $table->string('documented_channel', 32);
            $table->foreignId('checked_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('checked_at');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('action_plan_version_id');
        });

        Schema::create('mail_action_targets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_plan_version_id')->constrained('mail_action_plan_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('step_index')->default(0);
            $table->string('target_system', 24);
            $table->string('action_type', 32);
            // pending, running, executed, verified, failed, result_unclear, manual_task, blocked
            $table->string('status', 24)->default('pending');
            $table->foreignId('execution_id')->nullable()->constrained('mail_executions')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('mail_tasks')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['action_plan_version_id', 'step_index']);
            $table->index('status');
        });

        Schema::table('mail_executions', function (Blueprint $table): void {
            $table->foreignId('executed_by')->nullable()->after('write_operation_id')->constrained('users')->nullOnDelete();
            // Freigebende (User-IDs) zum Zeitpunkt der Ausführung
            $table->json('approved_by_json')->nullable()->after('executed_by');
            // maskiertes Ergebnis (nie Klartext-IBAN)
            $table->json('result_masked_json')->nullable()->after('approved_by_json');
            // App\Modules\Actions\Enums\VerificationStatus
            $table->string('verification_status', 24)->default('unverified')->after('result_masked_json');
            $table->string('lock_owner', 64)->nullable()->after('verification_status');
            $table->foreignId('task_id')->nullable()->after('lock_owner')->constrained('mail_tasks')->nullOnDelete();
            $table->foreignId('proposed_change_id')->nullable()->after('task_id')->constrained('proposed_changes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('mail_executions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('proposed_change_id');
            $table->dropConstrainedForeignId('task_id');
            $table->dropColumn(['executed_by', 'approved_by_json', 'result_masked_json', 'verification_status', 'lock_owner']);
        });
        Schema::dropIfExists('mail_action_targets');
        Schema::dropIfExists('mail_identity_checks');
        Schema::table('mail_approvals', function (Blueprint $table): void {
            $table->dropColumn(['diff_hash', 'expires_at']);
        });
        Schema::table('mail_action_plan_versions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('source_message_id');
            $table->dropColumn([
                'target_system', 'action_type', 'external_ref', 'fields_json', 'old_values', 'new_values', 'source_hash',
                'risk_class', 'effective_date', 'preconditions_json', 'required_approvals', 'requires_identity_check',
                'diff_hash', 'superseded_at',
            ]);
        });
    }
};
