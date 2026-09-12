<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zuletzt akzeptiertes TOTP-Zeitfenster je Nutzer. Codes mit gleichem oder kleinerem Zähler
     * werden abgelehnt (RFC 6238 Abschnitt 5.2, kein Replay innerhalb des Toleranzfensters).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('totp_last_counter')->nullable()->after('totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('totp_last_counter');
        });
    }
};
