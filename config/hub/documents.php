<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Documents. Zugriff über config('hub.documents.*').
 * WebDAV-Adapter, Dokumentenspiegel und Posteingang-Upload gemäß docs/immoware/07-sync-strategy.md
 * und docs/immoware/05-write-capabilities.md.
 */
return [

    'scan' => [
        // Wurzelordner, ab denen der WebDAV-Adapter traversiert (relativ zur Freigabe-URL der Connection).
        'roots' => array_values(array_filter(array_map('trim', explode(',', (string) env('IMMOWARE_WEBDAV_ROOTS', '/Posteingang/,/Dokumente/'))))),
        // Höchstzahl der Ordner, die ein pull()-Aufruf (ein Job-Lauf) per PROPFIND Depth 1 abarbeitet.
        'folders_per_run' => (int) env('IMMOWARE_WEBDAV_FOLDERS_PER_RUN', 25),
        // Sicherheitsgrenze gegen endlose Ordnerbäume.
        'max_depth' => (int) env('IMMOWARE_WEBDAV_MAX_DEPTH', 12),
        // Maximal ausgewertete Einträge je Depth-1-Antwort.
        'max_entries_per_folder' => 5000,
        // ETag-Vergleich nur, wenn die Probe etag_stable festgestellt hat; sonst getlastmodified plus Größe.
        'etag_stable_default' => (bool) env('IMMOWARE_WEBDAV_ETAG_STABLE', false),
    ],

    'content_hash' => [
        // SHA-256 über den Dateiinhalt (GET) für Dateien unterhalb dieser Größe.
        'enabled' => (bool) env('IMMOWARE_WEBDAV_CONTENT_HASH_ENABLED', false),
        'max_bytes' => (int) env('IMMOWARE_WEBDAV_CONTENT_HASH_MAX_BYTES', 10485760),
    ],

    'sweep' => [
        // Schutzgrenze: fehlen in einem Ordner mehr als max_missing_ratio (ab min_count_for_ratio Einträgen)
        // oder mehr als max_missing_count Dateien, wird kein Soft Delete durchgeführt.
        'max_missing_ratio' => 0.2,
        'min_count_for_ratio' => 10,
        'max_missing_count' => 500,
    ],

    /*
     * Dokumenttyp-Heuristik: erste Regel, deren Regex auf Dateiname oder Ordnerpfad passt, gewinnt.
     * Reihenfolge ist relevant. Ohne Treffer bleibt document_type null.
     */
    'type_rules' => [
        ['type' => 'invoice', 'pattern' => '/rechnung|invoice|\bre[-_ ]?\d/iu'],
        ['type' => 'contract', 'pattern' => '/mietvertrag|vertrag|contract/iu'],
        ['type' => 'protocol', 'pattern' => '/protokoll|niederschrift/iu'],
        ['type' => 'statement', 'pattern' => '/abrechnung|hausgeld|betriebskosten|nebenkosten/iu'],
        ['type' => 'bank_statement', 'pattern' => '/kontoauszug|camt|mt940/iu'],
        ['type' => 'correspondence', 'pattern' => '/schreiben|brief|anschreiben|mahnung/iu'],
        ['type' => 'scan', 'pattern' => '/^scan[_-]?\d+|posteingang/iu'],
    ],

    /*
     * Zuordnungsregeln: Zuordnung zu property, unit oder contact erfolgt NUR über diese expliziten Regeln.
     * Die Regex wird auf jedes Ordnersegment des Dokumentpfads angewendet; die benannte Gruppe liefert
     * den Schlüssel, der gegen die angegebene Spalte gesucht wird. confidence: exact, derived, uncertain.
     */
    'assignment_rules' => [
        [
            'entity' => 'property',
            // Ordnername beginnt mit der Immoware-Objektnummer, z. B. "0123 Musterstraße 1" oder "WEG-0123".
            'pattern' => '/^(?:weg|mv|obj)?[-_ ]?(?<key>\d{3,6})(?:[ _-]|$)/iu',
            'column' => 'immoware_object_number',
            'confidence' => 'derived',
        ],
    ],

    'upload' => [
        // Erlaubte Dateiendungen (nur a bis z und 0 bis 9, maximal 8 Zeichen). Andere werden zu .bin.
        'allowed_extensions' => ['pdf', 'png', 'jpg', 'jpeg', 'tif', 'tiff', 'txt', 'csv', 'xml', 'docx', 'xlsx', 'zip'],
        // Verifikation nach Upload: PROPFIND (Größe) und, wenn verify_with_hash, GET plus SHA-256.
        'verify_delay_seconds' => 0,
        'default_content_type' => 'application/octet-stream',
    ],

    'queues' => [
        'scan' => 'documents',
        'write' => 'write',
    ],
];
