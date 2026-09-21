<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Playbooks (Prozessdatenbank für Mail-Vorgänge). Eine Prozessvorlage (mail_playbooks) fasst zusammen, wie
 * Vorgänge einer Kategorie in der Vergangenheit bearbeitet wurden, damit ähnliche neue Vorgänge schneller und mit
 * bekanntem Vorgehen bearbeitet werden können. mail_playbook_matches protokolliert jeden Abgleich eines Vorgangs
 * gegen vorhandene Vorlagen (Grundlage für die Verbesserung der Vorlagen über die Zeit).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_playbooks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('case_type', 32);
            $table->string('title', 200);
            $table->unsignedInteger('version')->default(1);
            // draft, active, retired (App\Modules\Playbooks\Enums\PlaybookStatus).
            $table->string('status', 16)->default('draft');
            // learned, ai_drafted, manual (App\Modules\Playbooks\Enums\PlaybookSource).
            $table->string('source', 16)->default('learned');
            // Stichwörter und Merkmale für den regelbasierten Vergleich (keywords, property_scope).
            $table->json('match_signature_json')->nullable();
            // Schrittfolge, Struktur wie AiSchemas::playbookDraftSteps (action_type, description, ...).
            $table->json('steps_json')->nullable();
            $table->foreignId('created_from_case_id')->nullable()->constrained('mail_cases')->nullOnDelete();
            $table->foreignId('previous_version_id')->nullable()->constrained('mail_playbooks')->nullOnDelete();
            $table->unsignedInteger('times_suggested')->default(0);
            $table->unsignedInteger('times_accepted')->default(0);
            $table->unsignedInteger('times_adjusted')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'case_type', 'status']);
            $table->index('status');
        });

        Schema::create('mail_playbook_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('case_id')->constrained('mail_cases')->restrictOnDelete();
            $table->foreignId('playbook_id')->nullable()->constrained('mail_playbooks')->nullOnDelete();
            $table->unsignedTinyInteger('similarity_score')->nullable();
            // rule, ai (App\Modules\Playbooks\Enums\MatchMethod).
            $table->string('method', 8)->default('rule');
            // suggested, accepted, adjusted, rejected, no_match (App\Modules\Playbooks\Enums\MatchOutcome).
            $table->string('outcome', 16)->default('suggested');
            $table->foreignId('ai_run_id')->nullable()->constrained('mail_ai_runs')->nullOnDelete();
            $table->json('deviations_json')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['case_id', 'created_at']);
            $table->index('playbook_id');
            $table->index(['organization_id', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_playbook_matches');
        Schema::dropIfExists('mail_playbooks');
    }
};
