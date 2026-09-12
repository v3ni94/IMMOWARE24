<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive Spalten (Review 12.09.2026):
 * - missing_count auf Spiegeltabellen mit Mark-and-Sweep aus CSV-Vollexporten: Zähler aufeinanderfolgender
 *   Vollexporte desselben Exporttyps ohne Treffer. Soft Delete erst ab 2 (07-sync-strategy Abschnitt 4 Punkt 8).
 * - webhook_deliveries.queued_at: Zeitpunkt der letzten Einreihung eines DeliverWebhookJob, damit
 *   hub:webhooks:redeliver keine Zustellung doppelt einreiht, die bereits in der Queue liegt.
 */
return new class extends Migration
{
    /** @var array<int, string> */
    private array $sweepTables = ['properties', 'units', 'contacts', 'contracts', 'ownerships'];

    public function up(): void
    {
        foreach ($this->sweepTables as $table) {
            if (Schema::hasColumn($table, 'missing_count')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->unsignedSmallInteger('missing_count')->default(0)->after('missing_since');
            });
        }

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->after('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropColumn('queued_at');
        });

        foreach ($this->sweepTables as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('missing_count');
            });
        }
    }
};
