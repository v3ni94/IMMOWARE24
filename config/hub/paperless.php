<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Paperless (Paperless-ngx, Dokumentenablage außerhalb von Immoware24). Zugriff über
 * config('hub.paperless.*'). Alle Aussagen zu Endpunkten und Feldnamen (/api/documents/, post_document,
 * /api/custom_fields/, Antwortfelder id, title, correspondent, document_type, created, tags, custom_fields) stammen
 * aus allgemeinem Wissen zur öffentlichen Paperless-ngx-REST-API und sind vor Inbetriebnahme am eigenen Server zu
 * prüfen (Versionsstand, Feldnamen). Kein Löschen, kein Überschreiben, kein Live-Test möglich, geprüft nur mit
 * Http::fake().
 */
return [

    'base_url' => env('MAIL_PAPERLESS_BASE_URL'),

    // Technischer API-Token eines Paperless-Nutzers (Header "Authorization: Token <token>"), ein Token für den
    // gesamten Hub, kein OAuth-Fluss wie beim Modul Drive.
    'api_token' => env('MAIL_PAPERLESS_API_TOKEN'),

    'timeout_seconds' => (int) env('MAIL_PAPERLESS_TIMEOUT_SECONDS', 20),
    'connect_timeout_seconds' => 5,
    'retry' => [
        'times' => 2,
        'sleep_ms' => [300, 1000],
    ],

    'page_size' => 25,

    /*
     * Zusatzfeld (Custom Field) in Paperless, das die Immoware-Objektnummer je Dokument trägt. Die Feld-ID ist in
     * Paperless server- und installationsspezifisch (/api/custom_fields/) und muss dort bereits gepflegt sein, der
     * Hub legt keine Zusatzfelder an. Ohne gesetzte ID liefert forProperty() eine leere Liste statt zu raten.
     */
    'object_number_field_id' => env('MAIL_PAPERLESS_OBJECT_NUMBER_FIELD_ID'),

    // Textauszug für Suche und KI-Kontext (content-Feld des Dokuments, von Paperless per OCR bereits erzeugt).
    'excerpt' => [
        'max_chars' => (int) env('MAIL_PAPERLESS_EXCERPT_MAX_CHARS', 4000),
    ],
];
