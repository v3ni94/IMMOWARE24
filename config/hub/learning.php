<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Learning (Lernphase Immoware24). Zugriff über config('hub.learning.*'). Die Lernphase
 * erkundet aktiv über die belegten Zugangswege (WebDAV, CardDAV, CalDAV, Dateiexporte), was nicht Aufgabe der
 * laufenden Synchronisation ist: neue, noch nicht konfigurierte Ordner und Felder. Sie schreibt nie nach
 * Immoware24 und ändert keine Konfigurationsdatei automatisch; sie erzeugt ausschließlich Vorschläge
 * (mail_ai_suggestions), die eine Person prüft und bei Bedarf von Hand in die Konfiguration übernimmt.
 */
return [

    'flags' => [
        'enabled' => (bool) env('HUB_LEARNING_ENABLED', false),
        // Zusätzlich zu hub.mail.flags.ai (Hauptschalter für alle KI-Nutzung) und hub.mail.providers.ai=live.
        'ai' => (bool) env('HUB_LEARNING_AI_ENABLED', false),
    ],

    'webdav' => [
        // Tiefer als der laufende Sync (hub.documents.scan.max_depth), damit die Lernphase auch Bereiche
        // außerhalb der aktuell konfigurierten Wurzeln erkundet.
        'max_depth' => (int) env('HUB_LEARNING_WEBDAV_MAX_DEPTH', 8),
        'max_folders' => (int) env('HUB_LEARNING_WEBDAV_MAX_FOLDERS', 800),
        'sample_filenames_per_folder' => (int) env('HUB_LEARNING_WEBDAV_SAMPLE_FILENAMES', 8),
    ],

    'per_page' => (int) env('HUB_LEARNING_PER_PAGE', 25),
];
