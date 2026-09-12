<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rechtliche Sperre (Legal Hold) auf Vorgängen: solange legal_hold_at gesetzt ist, löscht mail:retention:apply weder
 * den Vorgang noch seine Nachrichtentexte, Anhänge oder KI-Läufe. Setzen und Aufheben werden auditiert.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_cases', function (Blueprint $table): void {
            $table->timestamp('legal_hold_at')->nullable()->after('close_reason');
            $table->string('legal_hold_reason', 200)->nullable()->after('legal_hold_at');
            $table->foreignId('legal_hold_by')->nullable()->after('legal_hold_reason')->constrained('users')->nullOnDelete();
            $table->index('legal_hold_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_cases', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('legal_hold_by');
            $table->dropIndex(['legal_hold_at']);
            $table->dropColumn(['legal_hold_at', 'legal_hold_reason']);
        });
    }
};
