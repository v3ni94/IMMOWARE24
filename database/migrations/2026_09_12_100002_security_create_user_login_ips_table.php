<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bekannte Anmelde-IPs je Nutzer, ausschließlich als HMAC-Hash (08-security.md Abschnitt 6).
        Schema::create('user_login_ips', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->char('ip_address_hash', 64);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unique(['user_id', 'ip_address_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_login_ips');
    }
};
