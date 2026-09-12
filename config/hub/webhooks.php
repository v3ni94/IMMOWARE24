<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Webhooks. Zugriff über config('hub.webhooks.*').
 * Grundlage: docs/immoware/09-api-documentation.md Abschnitt 5.
 */
return [

    'enabled' => (bool) env('HUB_WEBHOOKS_ENABLED', false),

    // Fenster in Sekunden, innerhalb dessen der Zeitstempel der Signatur vom Empfänger akzeptiert werden soll.
    'replay_window_seconds' => (int) env('HUB_WEBHOOK_REPLAY_WINDOW_SECONDS', 300),

    'timeout_seconds' => 10,

    // Wartezeiten zwischen den Zustellversuchen in Sekunden. Nach dem letzten Versuch landet die Zustellung in der DLQ.
    'backoff_seconds' => [30, 120, 600, 1800],
    'max_attempts' => 5,

    // Karenz in Sekunden nach next_attempt_at, bevor hub:webhooks:redeliver eine Zustellung erneut einreiht.
    // Verhindert Doppelzustellungen neben dem Queue-Retry des DeliverWebhookJob (verlorene Jobs nach Worker-Ausfall).
    'redeliver_grace_seconds' => (int) env('HUB_WEBHOOK_REDELIVER_GRACE_SECONDS', 300),

    // Maximale Länge des gespeicherten Antwortkörpers.
    'response_excerpt_bytes' => 512,

    'queue' => 'default',

    'signature' => [
        'header' => 'X-Hub-Signature',
        'event_header' => 'X-Hub-Event',
        'delivery_header' => 'X-Hub-Delivery',
        'algorithm' => 'sha256',
    ],

    // Ereigniskatalog. Schlüssel ist der Ereignisname, Wert die Beschreibung.
    'events' => [
        'contact.created' => 'Neuer Kontakt im Spiegel',
        'contact.updated' => 'Kontakt im Spiegel geändert',
        'property.updated' => 'Objekt im Spiegel geändert',
        'unit.updated' => 'Einheit im Spiegel geändert',
        'contract.created' => 'Neuer Vertrag im Spiegel',
        'contract.terminated' => 'Vertrag beendet (end_date gesetzt)',
        'document.created' => 'Neues Dokument im Spiegel',
        'invoice.created' => 'Neue Rechnung importiert',
        'open_item.created' => 'Neuer offener Posten',
        'open_item.paid' => 'Offener Posten ausgeglichen',
        'case.created' => 'Neuer Hub-Vorgang',
        'case.updated' => 'Hub-Vorgang geändert',
        'sync.failed' => 'Synchronisationslauf fehlgeschlagen',
        'sync.stale' => 'Spiegeldaten überschreiten das zulässige Datenalter',
    ],
];
