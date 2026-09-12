<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nachweis der Re-Authentifizierung bei der Entwurfsfreigabe (docs/mail/08 Abschnitt 11.2, Punkt 8). Additive Spalte
 * approval_reauth_confirmed_at auf mail_drafts, analog approvals.reauth_confirmed_at bei Aktionsplänen. Der Wert
 * kommt ausschließlich aus der Sitzung der freigebenden Person; ohne Nachweis verweigert DraftService::approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_drafts', static function (Blueprint $table): void {
            $table->timestamp('approval_reauth_confirmed_at')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('mail_drafts', static function (Blueprint $table): void {
            $table->dropColumn('approval_reauth_confirmed_at');
        });
    }
};
