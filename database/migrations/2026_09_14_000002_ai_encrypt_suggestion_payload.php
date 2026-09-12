<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Verschlüsselte Ablage der KI-Vorschläge (docs/mail/08 Abschnitt 11.2, G18): mail_ai_suggestions.payload_json wird
 * von json auf longText umgestellt und im Modell AiSuggestion mit dem Cast encrypted:array gelesen und geschrieben
 * (Chiffrat statt Klartext, Länge unbegrenzt). Bestehende Zeilen liegen im Klartext-JSON vor und wären mit dem neuen
 * Cast nicht lesbar; sie werden geleert (NULL) und als superseded markiert, die Vorschläge entstehen bei Bedarf über
 * einen neuen KI-Lauf. Spalte deshalb nullable. Läuft auf SQLite (Tests) und MariaDB; change() ohne DBAL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_ai_suggestions', static function (Blueprint $table): void {
            $table->longText('payload_json')->nullable()->change();
        });

        // Altbestand im Klartext ist mit dem verschlüsselten Cast nicht lesbar: leeren statt fehlerhaft entschlüsseln.
        DB::table('mail_ai_suggestions')->whereNotNull('payload_json')->update([
            'payload_json' => null,
            'status' => DB::raw("CASE WHEN status = 'proposed' THEN 'superseded' ELSE status END"),
        ]);
    }

    public function down(): void
    {
        // Chiffrate lassen sich nicht in json zurückführen; Spaltentyp bleibt longText, nur nullable wird zurückgenommen.
        DB::table('mail_ai_suggestions')->whereNull('payload_json')->update(['payload_json' => '[]']);

        Schema::table('mail_ai_suggestions', static function (Blueprint $table): void {
            $table->longText('payload_json')->nullable(false)->change();
        });
    }
};
