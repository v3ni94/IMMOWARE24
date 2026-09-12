<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Actions: Aktionspläne, Versionen, Freigaben (Vier-Augen), Ausführungen, Verifikationen, Outbox
 * (docs/mail/02-datenmodell.md, Abschnitt 5). HTTP 2xx ist http_ok_unverified; verifiziert erst nach Nachlesen.
 * current_version_id ist absichtlich ohne FK (zirkulär zu mail_action_plan_versions, SQLite kann FKs nicht nachträglich anlegen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_action_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('case_item_id')->nullable()->constrained('mail_case_items')->nullOnDelete();
            $table->unsignedBigInteger('current_version_id')->nullable();
            // App\Modules\Actions\Enums\ActionStatus
            $table->string('status', 24)->default('proposed');
            // App\Modules\Actions\Enums\RiskClass
            $table->string('risk_class', 8)->default('medium');
            // App\Modules\Actions\Enums\TargetSystem (führendes Zielsystem)
            $table->string('target_system', 24)->default('manual');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index('case_id');
            $table->index('current_version_id');
            $table->index(['organization_id', 'status']);
        });

        Schema::create('mail_action_plan_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_plan_id')->constrained('mail_action_plans')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->json('steps_json');
            $table->char('steps_hash', 64);
            // user, rule, ai (KI-generierte Pläne bleiben proposed)
            $table->string('generated_by', 16)->default('user');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('change_note', 500)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['action_plan_id', 'version']);
        });

        Schema::create('mail_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_plan_version_id')->constrained('mail_action_plan_versions')->restrictOnDelete();
            $table->foreignId('approver_user_id')->constrained('users')->restrictOnDelete();
            // approved, rejected
            $table->string('decision', 16);
            $table->char('steps_hash', 64);
            $table->string('comment', 500)->nullable();
            $table->timestamp('reauth_confirmed_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['action_plan_version_id', 'approver_user_id']);
        });

        Schema::create('mail_executions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('action_plan_version_id')->constrained('mail_action_plan_versions')->restrictOnDelete();
            $table->unsignedSmallInteger('step_index')->default(0);
            $table->uuid('execution_uuid');
            $table->string('idempotency_key', 128);
            $table->string('action_key', 64);
            $table->string('target_system', 24);
            // pending, running, http_ok_unverified, verified, failed, blocked_flag, blocked_capability, blocked_permission
            $table->string('status', 24)->default('pending');
            $table->json('request_summary_json')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->foreignId('write_operation_id')->nullable()->constrained('write_operations')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_class', 200)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique('execution_uuid');
            $table->unique('idempotency_key');
            $table->index('status');
            $table->index(['action_plan_version_id', 'step_index']);
        });

        Schema::create('mail_verifications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('execution_id')->constrained('mail_executions')->restrictOnDelete();
            // reread_get, propfind_etag, gmail_sent_label, lexware_version_compare, manual_confirmation
            $table->string('method', 32);
            $table->json('expected_json')->nullable();
            $table->json('observed_json')->nullable();
            // App\Modules\Actions\Enums\VerificationStatus
            $table->string('result', 24)->default('unverified');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('execution_id');
        });

        Schema::create('mail_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('event', 64);
            $table->string('aggregate_type', 40);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload_json')->nullable();
            $table->string('queue', 24)->default('mail-high');
            // pending, dispatched, failed
            $table->string('status', 16)->default('pending');
            $table->timestamp('dispatched_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->index(['status', 'created_at']);
            $table->index(['aggregate_type', 'aggregate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_outbox');
        Schema::dropIfExists('mail_verifications');
        Schema::dropIfExists('mail_executions');
        Schema::dropIfExists('mail_approvals');
        Schema::dropIfExists('mail_action_plan_versions');
        Schema::dropIfExists('mail_action_plans');
    }
};
