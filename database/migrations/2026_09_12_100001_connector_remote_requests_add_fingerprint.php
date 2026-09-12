<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive Erweiterung von remote_requests: Adaptername, Request-Größe und struktureller
 * Fingerprint der Antwort (Erkennung von Formatänderungen des DAV-Servers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remote_requests', function (Blueprint $table): void {
            $table->string('connector_name', 32)->nullable()->after('connection_id');
            $table->unsignedBigInteger('request_bytes')->nullable()->after('response_headers');
            $table->char('response_schema_fingerprint', 64)->nullable()->after('response_bytes');
            $table->index('response_schema_fingerprint', 'remote_requests_schema_fp_index');
        });
    }

    public function down(): void
    {
        Schema::table('remote_requests', function (Blueprint $table): void {
            $table->dropIndex('remote_requests_schema_fp_index');
            $table->dropColumn(['connector_name', 'request_bytes', 'response_schema_fingerprint']);
        });
    }
};
