<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Contacts (CardDAV-Spiegel). Zugriff über config('hub.contacts.*').
 */
return [

    // Version des aktiven Feldmappings vCard nach contacts (field_mappings.version).
    'mapping_version' => 1,

    // Anzahl aufeinanderfolgender vollständiger Läufe, in denen eine Ressource fehlen muss, bevor deleted_at gesetzt wird.
    'sweep' => [
        'required_misses' => (int) env('HUB_CONTACTS_SWEEP_REQUIRED_MISSES', 1),
    ],

    // Duplikatkandidaten: gleiche E-Mail oder gleiche Telefonnummer bei unterschiedlicher UID.
    // Es wird ausschließlich ein Vorschlag (contact_merges.status = proposed) erzeugt, nie automatisch gemergt.
    'duplicates' => [
        'enabled' => (bool) env('HUB_CONTACTS_DUPLICATE_PROPOSALS', true),
        'by_email' => true,
        'by_phone' => true,
    ],

    // Rollen aus vCard-CATEGORIES. Nur Kategorien mit einer hier hinterlegten Regel erzeugen contact_roles;
    // ohne Regel bleiben contact_roles unverändert. Werte: App\Core\Enums\ContactRoleType.
    // Beispiel: 'Eigentümer' => 'owner', 'Mieter' => 'tenant'
    'category_roles' => [],

    'http' => [
        'timeout_seconds' => (int) env('HUB_CONTACTS_HTTP_TIMEOUT', 60),
        'connect_timeout_seconds' => (int) env('HUB_CONTACTS_HTTP_CONNECT_TIMEOUT', 10),
    ],
];
