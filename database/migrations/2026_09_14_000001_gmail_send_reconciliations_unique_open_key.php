<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Offener Versandabgleich je Entwurf eindeutig (docs/mail/08 Abschnitt 11.2, G8). Additive Spalte open_key: enthält
 * die draft_id, solange der Abgleich pending ist, und NULL nach Abschluss (verified, not_found, mismatch). Der
 * Unique-Index auf open_key lässt beliebig viele NULL-Werte zu (MariaDB wie SQLite), verhindert aber zwei parallel
 * offene Abgleiche desselben Entwurfs, etwa bei einem Retry von drafts.send. Bestehende pending-Zeilen werden befüllt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_send_reconciliations', static function (Blueprint $table): void {
            $table->unsignedBigInteger('open_key')->nullable()->after('draft_id');
        });

        // Befüllung vor dem Index; bei mehreren offenen Zeilen je Entwurf bleibt nur die jüngste offen, die älteren
        // gelten als mismatch (Widerspruch, kein Erfolg vorgetäuscht).
        $duplicates = DB::table('mail_send_reconciliations')
            ->select('draft_id', DB::raw('MAX(id) as keep_id'))
            ->where('result', 'pending')
            ->groupBy('draft_id')
            ->get();

        foreach ($duplicates as $row) {
            DB::table('mail_send_reconciliations')
                ->where('draft_id', $row->draft_id)
                ->where('result', 'pending')
                ->where('id', '<>', $row->keep_id)
                ->update(['result' => 'mismatch', 'next_check_at' => null]);
        }

        DB::table('mail_send_reconciliations')->where('result', 'pending')->update(['open_key' => DB::raw('draft_id')]);

        Schema::table('mail_send_reconciliations', static function (Blueprint $table): void {
            $table->unique('open_key', 'mail_send_reconciliations_open_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mail_send_reconciliations', static function (Blueprint $table): void {
            $table->dropUnique('mail_send_reconciliations_open_key_unique');
            $table->dropColumn('open_key');
        });
    }
};
