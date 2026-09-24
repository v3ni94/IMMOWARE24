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
     * Zusatzfeld (Custom Field) in Paperless, das die Objektzuordnung je Dokument trägt. Die Feld-ID ist in Paperless
     * server- und installationsspezifisch (/api/custom_fields/) und muss dort bereits gepflegt sein, der Hub legt
     * keine Zusatzfelder an. Ohne gesetzte ID liefert forProperty() eine leere Liste statt zu raten.
     * Am eigenen Server (dms.muellerhv.de, Stand 24.09.2026) ist dies das Feld "MHV Objekt" (Feld-ID 7, data_type
     * string, bereits über 21.000 Dokumente zugeordnet). Das gleichnamig wirkende Feld "Objekt-Nr" (Feld-ID 1,
     * data_type integer) ist ungenutzt (0 Dokumente) und ist NICHT das richtige Feld.
     */
    'object_number_field_id' => env('MAIL_PAPERLESS_OBJECT_NUMBER_FIELD_ID'),

    /*
     * Zusatzfeld "Gesellschaft" (data_type select), trennt Dokumente nach der handelnden Gesellschaft der Gruppe
     * (Hausverwaltung Müller GmbH, Müller Holding AG, TMREV GmbH, Sonstige). Paperless speichert je Dokument nur die
     * Options-ID, nicht das Label, daher die Zuordnung Options-ID zu Label in company_options. Am eigenen Server
     * (dms.muellerhv.de, Stand 24.09.2026) per /api/custom_fields/ ermittelt, Feld-ID 5. Ändert sich die Auswahl in
     * Paperless, sind auch diese Options-IDs neu zu ermitteln.
     */
    'company_field_id' => env('MAIL_PAPERLESS_COMPANY_FIELD_ID'),

    'company_options' => [
        '4WSfEGQWkgqHgXaO' => 'HVM',
        'Xu9WRpfjgvf9ULDe' => 'MHAG',
        'XSTnxr7YfHlfzXYM' => 'TMREV',
        'o74Z9KVLPYshG5J1' => 'Sonstige',
    ],

    // Textauszug für Suche und KI-Kontext (content-Feld des Dokuments, von Paperless per OCR bereits erzeugt).
    'excerpt' => [
        'max_chars' => (int) env('MAIL_PAPERLESS_EXCERPT_MAX_CHARS', 4000),
    ],
];
