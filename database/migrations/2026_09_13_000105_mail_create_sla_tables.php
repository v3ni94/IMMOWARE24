<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Sla: Arbeitskalender, Feiertage, SLA-Regeln, Uhren, Fristen, Notfallalarme, Eskalationsstufen
 * (docs/mail/02-datenmodell.md Abschnitt 4, docs/mail/04-status-und-sla.md). Zeiten UTC, Darstellung Europe/Berlin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_work_calendars', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('name', 120);
            $table->string('timezone', 64)->default('Europe/Berlin');
            $table->json('weekly_hours_json')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['organization_id', 'is_default']);
        });

        Schema::create('mail_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_calendar_id')->constrained('mail_work_calendars')->cascadeOnDelete();
            $table->date('holiday_date');
            $table->string('label', 120);
            $table->string('region', 8)->default('NW');
            $table->timestamp('created_at')->nullable();
            $table->unique(['work_calendar_id', 'holiday_date']);
        });

        Schema::create('mail_sla_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('team_id')->nullable()->constrained('mail_teams')->nullOnDelete();
            $table->string('priority', 2);
            $table->string('case_type', 32)->nullable();
            // acknowledge, first_response, resolve, task_due
            $table->string('clock_type', 24);
            $table->unsignedInteger('target_minutes');
            $table->boolean('uses_calendar')->default(true);
            $table->unsignedTinyInteger('warn_percent')->default(50);
            $table->unsignedInteger('escalate_after_minutes')->nullable();
            $table->string('escalate_to_role', 24)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['organization_id', 'team_id', 'priority', 'case_type', 'clock_type'], 'mail_sla_rules_unique');
        });

        Schema::create('mail_sla_clocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('case_item_id')->nullable()->constrained('mail_case_items')->nullOnDelete();
            $table->string('clock_type', 24);
            $table->foreignId('sla_rule_id')->nullable()->constrained('mail_sla_rules')->nullOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('paused_at')->nullable();
            $table->unsignedInteger('paused_minutes')->default(0);
            $table->timestamp('target_at');
            $table->timestamp('warn_at');
            $table->timestamp('stopped_at')->nullable();
            // running, paused, met, breached, cancelled
            $table->string('state', 16)->default('running');
            // green, yellow, red
            $table->string('color', 8)->default('green');
            $table->timestamp('last_evaluated_at')->nullable();
            $table->timestamps();
            $table->index(['state', 'target_at']);
            $table->unique(['case_id', 'case_item_id', 'clock_type']);
        });

        Schema::create('mail_deadlines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            // internal, external, statutory_hint (Fristen nur als Orientierung, zu verifizieren)
            $table->string('kind', 24)->default('internal');
            $table->string('label', 200);
            $table->timestamp('due_at');
            $table->timestamp('pre_alert_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            // open, met, missed, cancelled
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['due_at', 'status']);
            $table->index('case_id');
        });

        Schema::create('mail_emergency_alerts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('mail_messages')->nullOnDelete();
            // rule, manual, ai_confirmed
            $table->string('detected_by', 16)->default('rule');
            $table->string('matched_rule', 120)->nullable();
            $table->timestamp('detected_at');
            $table->timestamp('acknowledge_due_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
            // open, acknowledged, escalated, downgraded, closed
            $table->string('status', 16)->default('open');
            $table->string('downgrade_reason', 200)->nullable();
            $table->timestamps();
            $table->index(['status', 'acknowledge_due_at']);
            $table->index('case_id');
        });

        Schema::create('mail_escalation_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('sla_clock_id')->nullable()->constrained('mail_sla_clocks')->nullOnDelete();
            $table->foreignId('emergency_alert_id')->nullable()->constrained('mail_emergency_alerts')->nullOnDelete();
            $table->unsignedTinyInteger('level')->default(1);
            $table->string('reason', 200);
            $table->string('escalated_to_role', 24)->nullable();
            $table->foreignId('escalated_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            // pending, notified, acknowledged, failed
            $table->string('status', 16)->default('pending');
            $table->timestamp('triggered_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->json('channel_results_json')->nullable();
            $table->timestamps();
            $table->index(['case_id', 'level']);
            $table->index(['status', 'triggered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_escalation_steps');
        Schema::dropIfExists('mail_emergency_alerts');
        Schema::dropIfExists('mail_deadlines');
        Schema::dropIfExists('mail_sla_clocks');
        Schema::dropIfExists('mail_sla_rules');
        Schema::dropIfExists('mail_holidays');
        Schema::dropIfExists('mail_work_calendars');
    }
};
