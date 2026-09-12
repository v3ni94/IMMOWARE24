<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Sla, additiv: Protokoll der Uhren (Fristquelle und jede Änderung), Ursache-Text je Uhr sowie Zustellprotokoll
 * der Notfallalarme getrennt von der menschlichen Annahme.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_sla_clocks', function (Blueprint $table): void {
            // config, rule, manual
            $table->string('target_source', 16)->default('config')->after('sla_rule_id');
            $table->string('cause_text', 300)->nullable()->after('color');
            $table->unsignedInteger('target_minutes')->default(0)->after('target_source');
            $table->boolean('uses_calendar')->default(true)->after('target_minutes');
        });

        Schema::create('mail_sla_clock_log', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sla_clock_id')->constrained('mail_sla_clocks')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('mail_cases')->cascadeOnDelete();
            // started, paused, resumed, retargeted, stopped_met, stopped_breached, cancelled, evaluated_breached, pause_limit_reached
            $table->string('event', 32);
            $table->timestamp('old_target_at')->nullable();
            $table->timestamp('new_target_at')->nullable();
            // config, rule, manual, system
            $table->string('source', 16)->default('system');
            $table->string('reason', 300)->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['sla_clock_id', 'created_at']);
        });

        Schema::table('mail_emergency_alerts', function (Blueprint $table): void {
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('escalated_at');
            $table->timestamp('next_escalation_at')->nullable()->after('escalation_level');
            $table->json('delivery_log_json')->nullable()->after('downgrade_reason');
            $table->boolean('on_call_configured')->default(false)->after('delivery_log_json');
        });
    }

    public function down(): void
    {
        Schema::table('mail_emergency_alerts', function (Blueprint $table): void {
            $table->dropColumn(['escalation_level', 'next_escalation_at', 'delivery_log_json', 'on_call_configured']);
        });

        Schema::dropIfExists('mail_sla_clock_log');

        Schema::table('mail_sla_clocks', function (Blueprint $table): void {
            $table->dropColumn(['target_source', 'cause_text', 'target_minutes', 'uses_calendar']);
        });
    }
};
