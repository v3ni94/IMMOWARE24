<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jede Zeile der Hash-Kette darf genau einen Nachfolger haben. Der Unique-Index auf prev_hash
     * verhindert eine Verzweigung, falls zwei Schreiber trotz Lock denselben Vorgänger lesen.
     */
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->unique('prev_hash', 'audit_logs_prev_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropUnique('audit_logs_prev_hash_unique');
        });
    }
};
