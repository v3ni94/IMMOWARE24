<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Additive Erweiterung der Tabelle documents um Dokumenttyp-Heuristik und Zuordnungskonfidenz.
 * Zuordnungen zu property, unit und contact entstehen ausschließlich über explizite Mapping-Regeln.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->string('document_type', 40)->nullable()->after('content_type');
            $table->string('document_type_rule', 40)->nullable()->after('document_type');
            $table->string('assignment_confidence', 16)->nullable()->after('case_id');
            $table->string('assignment_rule', 80)->nullable()->after('assignment_confidence');
            $table->string('display_name', 512)->nullable()->after('filename');
            $table->index(['connection_id', 'document_type'], 'documents_connection_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropIndex('documents_connection_type_index');
            $table->dropColumn(['document_type', 'document_type_rule', 'assignment_confidence', 'assignment_rule', 'display_name']);
        });
    }
};
