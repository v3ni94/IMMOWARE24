<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Calendar (CalDAV-Spiegel). Zugriff über config('hub.calendar.*').
 */
return [

    'mapping_version' => 1,

    // Mark-and-Sweep wie hub.contacts.sweep (07-sync-strategy.md Abschnitt 4, Änderungsvermerk 12.09.2026).
    'sweep' => [
        'required_misses' => (int) env('HUB_CALENDAR_SWEEP_REQUIRED_MISSES', 2),
        'max_missing_ratio' => 0.2,
        'min_count_for_ratio' => 10,
        'max_missing_count' => 500,
    ],

];
