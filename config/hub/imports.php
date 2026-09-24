<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Imports. Zugriff über config('hub.imports.*').
 * Formate der Immoware24-Exporte sind öffentlich nicht dokumentiert. Der Import arbeitet deshalb
 * formatagnostisch über Header-Fingerprints; jedes Spaltenmapping wird von einem Administrator bestätigt.
 */
return [

    // Drop-Ordner für manuell erzeugte Exporte (absoluter Pfad, lokale Disk).
    // Leerer Wert in der .env gilt als nicht gesetzt (env() liefert sonst '' statt des Standards).
    'drop_path' => env('HUB_IMPORT_DROP_PATH') ?: storage_path('app/private/imports/drop'),

    // Ohne Metadaten-Sidecar <datei>.json landet die Datei in Quarantäne.
    'require_metadata' => (bool) env('HUB_IMPORT_REQUIRE_METADATA', true),

    // Pflichtfelder des Sidecars.
    'metadata_required_fields' => ['organization', 'export_type', 'exported_at', 'exported_by'],

    // Unterordner innerhalb des Drop-Ordners, in die erfasste Dateien verschoben werden.
    'folders' => [
        'received' => 'received',
        'quarantine' => 'quarantine',
    ],

    // Fallback-Mandant (organizations.id), wenn der Sidecar keinen Mandanten nennt. null = keine Erfassung ohne Mandant.
    'default_organization_id' => env('HUB_IMPORT_DEFAULT_ORGANIZATION_ID') !== null ? (int) env('HUB_IMPORT_DEFAULT_ORGANIZATION_ID') : null,

    // Quellsystem-Kennung für CSV-Adressbuchimporte (ergänzend zu CardDAV).
    'contacts_csv_source_system' => 'immoware24_csv',

    // Maximale Fehlereinträge, die je Datei in import_files.errors gehalten werden.
    'max_errors_stored' => 200,

    'datev' => [
        // Vorzeichenkonvention: S = Soll positiv, H = Haben negativ. DATEV-Standard, Immoware24-Spezifika unbekannt, zu verifizieren.
        'debit_positive' => true,
    ],

    'exports' => [
        // Storage-Disk und Präfix für asynchrone Hub-Exporte.
        'disk' => env('HUB_EXPORT_DISK', 'local'),
        'prefix' => 'exports',
        'chunk_size' => 500,
    ],

    // Erinnerungen: Tage nach Fälligkeit, ab denen ein Export als überfällig gilt (0 = sofort).
    'reminder_grace_days' => 0,
];
