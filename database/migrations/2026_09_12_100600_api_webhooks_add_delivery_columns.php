<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive Erweiterung der Webhook-Tabellen: Zustell-UUID, Dauer, gekürzter Antwortkörper,
 * Zeitpunkt des letzten Versuchs sowie Ereignisname im Outbox-Eintrag für die Signatur-Header.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->uuid('delivery_uuid')->nullable()->after('outbox_id');
            $table->unsignedInteger('duration_ms')->nullable()->after('last_response_code');
            $table->text('response_excerpt')->nullable()->after('duration_ms');
            $table->timestamp('last_attempt_at')->nullable()->after('response_excerpt');
            $table->timestamp('dead_at')->nullable()->after('last_attempt_at');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->unique('delivery_uuid', 'webhook_deliveries_delivery_uuid_unique');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropUnique('webhook_deliveries_delivery_uuid_unique');
            $table->dropColumn(['delivery_uuid', 'duration_ms', 'response_excerpt', 'last_attempt_at', 'dead_at']);
        });
    }
};
