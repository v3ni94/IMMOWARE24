<?php

declare(strict_types=1);

namespace App\Core\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Gemeinsame Spaltenblöcke für Migrationen (SQLite- und MariaDB-kompatibel).
 */
final class BlueprintMacros
{
    /**
     * Kuerzt lange Tabellennamen fuer Indexnamen (MariaDB-Grenze 64 Zeichen).
     */
    public static function shortIndexPrefix(string $table): string
    {
        return strlen($table) > 40 ? substr($table, 0, 32).'_'.substr(hash('crc32b', $table), 0, 6) : $table;
    }

    public static function register(): void
    {
        if (Blueprint::hasMacro('externalIdentity')) {
            return;
        }

        /*
         * Herkunftsblock (H) gemäß docs/architecture/02-data-model.md.
         * Unique auf (organization_id, source_system, external_id_hash), nie auf VARCHAR(512).
         */
        Blueprint::macro('externalIdentity', function (bool $withOrganization = true, bool $uniqueExternal = true): void {
            /** @var Blueprint $this */
            if ($withOrganization) {
                $this->foreignId('organization_id')->constrained('organizations');
            }
            $this->foreignId('connection_id')->nullable()->constrained('immoware_connections')->nullOnDelete();
            $this->string('source_system', 32)->default('immoware24');
            $this->string('external_id', 512);
            $this->char('external_id_hash', 64);
            $this->string('external_parent_id', 512)->nullable();
            $this->dateTime('external_updated_at', 6)->nullable();
            $this->dateTime('first_synced_at', 6)->nullable();
            $this->dateTime('last_synced_at', 6)->nullable();
            $this->char('checksum', 64)->nullable();
            $this->unsignedInteger('sync_version')->default(1);
            $this->string('identity_confidence', 16)->default('exact');
            $this->dateTime('missing_since', 6)->nullable();
            $this->dateTime('stale_since', 6)->nullable();
            $this->string('deletion_reason', 64)->nullable();
            $this->unsignedBigInteger('last_payload_id')->nullable();
            $this->timestamps(6);
            $this->softDeletes('deleted_at', 6);

            $this->index('source_system');
            $this->index('external_id_hash');
            $this->index('checksum');
            $this->index('updated_at');
            $this->index('deleted_at');

            // Explizite kurze Indexnamen: MariaDB erlaubt hoechstens 64 Zeichen, die von Laravel
            // generierten Namen ueberschreiten das bei langen Tabellennamen (Befund Deploy 20.09.2026).
            $prefix = BlueprintMacros::shortIndexPrefix($this->getTable());
            if ($uniqueExternal && $withOrganization) {
                $this->unique(['organization_id', 'source_system', 'external_id_hash'], $prefix.'_ext_uq');
            } elseif ($uniqueExternal) {
                $this->unique(['connection_id', 'source_system', 'external_id_hash'], $prefix.'_ext_conn_uq');
            }
        });

        Blueprint::macro('moneyCents', function (string $column, bool $nullable = true): void {
            /** @var Blueprint $this */
            $col = $this->bigInteger($column);
            if ($nullable) {
                $col->nullable();
            }
        });
    }
}
