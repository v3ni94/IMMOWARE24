<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive Erweiterung für den CardDAV-Spiegel: Firmenname, Position, Notizen, Kategorien und
 * generische X-Properties der vCard sowie der zuletzt gesehene ETag der Ressource.
 * contact_merges erhält Duplikatvorschläge (status proposed) und die Aktivitätsspalte is_active,
 * die im Datenmodell als generierte Spalte vorgesehen ist und hier als Anwendungslogik gepflegt wird.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table): void {
            $table->string('company_name', 200)->nullable()->after('company_id');
            $table->string('job_title', 200)->nullable()->after('company_name');
            $table->text('notes')->nullable()->after('addresses');
            $table->json('categories')->nullable()->after('notes');
            $table->json('vcard_extra')->nullable()->after('vcard_rev');
            $table->string('remote_etag', 255)->nullable()->after('vcard_extra');
        });

        Schema::table('contact_merges', function (Blueprint $table): void {
            $table->timestamp('merged_at')->nullable()->change();
            $table->string('status', 16)->default('merged')->after('target_contact_id');
            $table->string('match_kind', 16)->nullable()->after('status');
            $table->timestamp('proposed_at')->nullable()->after('match_kind');
            $table->boolean('is_active')->nullable()->after('undone_at');
            $table->index(['status', 'proposed_at'], 'contact_merges_status_index');
            $table->unique(['source_contact_id', 'is_active'], 'contact_merges_source_active_unique');
        });

        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->string('remote_etag', 255)->nullable()->after('ical_href');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table): void {
            $table->dropColumn('remote_etag');
        });

        Schema::table('contact_merges', function (Blueprint $table): void {
            $table->dropUnique('contact_merges_source_active_unique');
            $table->dropIndex('contact_merges_status_index');
            $table->dropColumn(['status', 'match_kind', 'proposed_at', 'is_active']);
        });

        Schema::table('contacts', function (Blueprint $table): void {
            $table->dropColumn(['company_name', 'job_title', 'notes', 'categories', 'vcard_extra', 'remote_etag']);
        });
    }
};
