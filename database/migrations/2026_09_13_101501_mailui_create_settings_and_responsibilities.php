<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul MailUi, additiv: Organisationseinstellungen der Mail-Bearbeitung als Schlüssel-Wert-Paare (Arbeitszeiten-Vorgabe,
 * Eskalationsempfänger, Bereitschaft, Kostenlimits KI, Aufbewahrung, Stand des Einrichtungsassistenten) sowie
 * Objektzuständigkeiten (Objekt zu Team oder Person). Keine Änderungen an bestehenden Tabellen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_org_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->string('key', 80);
            $table->json('value_json')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'key']);
        });

        Schema::create('mail_property_responsibilities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations');
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('mail_teams')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // primary, substitute
            $table->string('role', 16)->default('primary');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['property_id', 'role']);
            $table->index('team_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_property_responsibilities');
        Schema::dropIfExists('mail_org_settings');
    }
};
