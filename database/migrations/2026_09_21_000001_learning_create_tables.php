<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Learning (Lernphase Immoware24). Ein Lauf erkundet über die belegten Zugangswege (WebDAV, CardDAV,
 * CalDAV, Dateiexporte) die aktuelle Struktur, vergleicht sie mit dem vorigen erfolgreichen Lauf gleicher Art
 * und kann eine KI-Auswertung (mail_ai_suggestions) anstoßen. Kein Bezug zu einem Vorgang oder einer Nachricht.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            // webdav, carddav, caldav, imports (App\Modules\Learning\Enums\LearningKind).
            $table->string('kind', 16);
            // running, succeeded, failed (App\Modules\Learning\Enums\LearningRunStatus).
            $table->string('status', 16)->default('running');
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            // Zeiger auf den vorigen erfolgreichen Lauf gleicher Art und Verbindung, kein Fremdschlüssel (Selbstbezug).
            $table->unsignedBigInteger('previous_run_id')->nullable();
            $table->json('facts_json')->nullable();
            $table->json('diff_json')->nullable();
            $table->char('facts_fingerprint', 64)->nullable();
            $table->foreignId('ai_suggestion_id')->nullable()->constrained('mail_ai_suggestions')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'kind', 'created_at']);
            $table->index('status');
            $table->index('previous_run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_runs');
    }
};
