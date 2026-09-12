<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Contacts (CardDAV-Spiegel). Zugriff über config('hub.contacts.*').
 */
return [

    // Version des aktiven Feldmappings vCard nach contacts (field_mappings.version).
    'mapping_version' => 1,

    /*
     * Mark-and-Sweep (07-sync-strategy.md Abschnitt 4, Änderungsvermerk 12.09.2026): Soft Delete erst nach required_misses
     * aufeinanderfolgenden vollständigen Läufen ohne Treffer (mindestens 2) und nur bei bestätigtem Health-Check.
     * Schutzgrenze: fehlen mehr als max_missing_ratio (ab min_count_for_ratio Einträgen) oder mehr als max_missing_count
     * Ressourcen, wird nichts gelöscht, die Connection erhält degraded_reason mass_missing.
     */
    'sweep' => [
        'required_misses' => (int) env('HUB_CONTACTS_SWEEP_REQUIRED_MISSES', 2),
        'max_missing_ratio' => 0.2,
        'min_count_for_ratio' => 10,
        'max_missing_count' => 500,
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

];
