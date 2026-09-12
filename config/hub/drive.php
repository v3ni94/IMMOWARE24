<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Drive (Google Drive v3, ausschließlich lesend). Zugriff über config('hub.drive.*').
 * Alle Aussagen zur Drive-API (Endpunkte, Parameter files.list q/fields/supportsAllDrives/includeItemsFromAllDrives/
 * pageToken, files.get, files.export, permissions.list) stammen aus Snippets und sind vor Inbetriebnahme am Original
 * zu prüfen. Kein Schreiben, kein Anlegen von Ordnern. Kein Live-Test möglich, geprüft nur mit Http::fake().
 */
return [

    'oauth' => [
        'client_id' => env('MAIL_DRIVE_CLIENT_ID'),
        'client_secret' => env('MAIL_DRIVE_CLIENT_SECRET'),
        'redirect_path' => '/mail/integrations/drive/callback',
        'scopes' => ['https://www.googleapis.com/auth/drive.readonly'],
        // Google-Token-Endpunkt wie beim Gmail-Modul (aus Snippets).
        'token_url' => env('MAIL_GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        // Sicherheitsabstand vor Ablauf des Access-Tokens in Sekunden.
        'refresh_leeway_seconds' => 120,
    ],

    'api_base_url' => env('MAIL_DRIVE_API_BASE_URL', 'https://www.googleapis.com/drive/v3'),

    // Wurzelordner, unterhalb dessen gesucht wird; leer = gesamtes Laufwerk des verbundenen Kontos.
    'root_folder_id' => env('MAIL_DRIVE_ROOT_FOLDER_ID'),
    'page_size' => 50,
    'timeout_seconds' => (int) env('MAIL_DRIVE_TIMEOUT_SECONDS', 20),
    'connect_timeout_seconds' => 5,
    'retry' => [
        'times' => 2,
        'sleep_ms' => [300, 1000],
    ],

    /*
     * Zuordnung Objekt zu Drive-Ordner aus Konfiguration: Schlüssel ist die Immoware-Objektnummer
     * (properties.immoware_object_number), Wert die Drive-Ordner-ID. Ergänzend die Tabelle mail_drive_folder_mappings
     * (Hub-Daten, Pflege durch Administration). Es werden nie neue Ordner in Drive angelegt.
     * Beispiel per Umgebung: MAIL_DRIVE_FOLDER_MAP="OBJ-0001:1AbCdEf,OBJ-0002:1XyZ"
     */
    'folder_map_env' => env('MAIL_DRIVE_FOLDER_MAP'),

    // Auszüge für Suche und KI-Kontext. Google-Dokumente werden als text/plain exportiert (files.export).
    'excerpt' => [
        'max_chars' => (int) env('MAIL_DRIVE_EXCERPT_MAX_CHARS', 4000),
        'export_mime_types' => [
            'application/vnd.google-apps.document' => 'text/plain',
            'application/vnd.google-apps.spreadsheet' => 'text/csv',
        ],
        // Direkt lesbare Textformate; alles andere (PDF, DOCX) wird ohne OCR nicht indexiert ("nicht eingerichtet").
        'text_mime_types' => ['text/plain', 'text/csv', 'text/markdown'],
        'max_download_bytes' => 2 * 1024 * 1024,
    ],

    // Anhangsprüfung: Allowlist, Größe, blockierte Endungen und Kennzeichen für Makros und ausführbare Inhalte.
    'attachments' => [
        'max_bytes' => (int) env('MAIL_ATTACHMENT_MAX_BYTES', 25 * 1024 * 1024),
        'allowed_mime_types' => [
            'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/tiff', 'image/webp', 'image/heic',
            'text/plain', 'text/csv', 'application/rtf',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.oasis.opendocument.text', 'application/vnd.oasis.opendocument.spreadsheet',
            'message/rfc822', 'text/calendar', 'text/vcard', 'application/xml', 'text/xml',
        ],
        'allowed_extensions' => [
            'pdf', 'jpg', 'jpeg', 'png', 'gif', 'tif', 'tiff', 'webp', 'heic', 'txt', 'csv', 'rtf',
            'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'eml', 'ics', 'vcf', 'xml',
        ],
        // Makrofähige Office-Formate und ausführbare oder skriptfähige Dateien werden immer blockiert.
        'blocked_extensions' => [
            'docm', 'dotm', 'xlsm', 'xltm', 'xlam', 'pptm', 'potm', 'ppam', 'ppsm', 'sldm',
            'exe', 'dll', 'com', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'msi', 'msp',
            'scr', 'pif', 'hta', 'jar', 'reg', 'lnk', 'iso', 'img', 'apk', 'app', 'sh', 'php', 'py',
            'zip', 'rar', '7z', 'gz', 'tar', 'cab', 'ace', 'arj',
        ],
        'blocked_mime_types' => [
            'application/x-msdownload', 'application/x-dosexec', 'application/x-executable', 'application/x-sh',
            'application/x-msi', 'application/java-archive', 'application/vnd.ms-word.document.macroenabled.12',
            'application/vnd.ms-excel.sheet.macroenabled.12', 'application/vnd.ms-powerpoint.presentation.macroenabled.12',
            'application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed',
        ],
    ],

    // OCR ist nicht implementiert; die Oberfläche zeigt "Nicht eingerichtet".
    'ocr' => ['provider' => null],
];
