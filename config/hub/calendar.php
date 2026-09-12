<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Calendar (CalDAV-Spiegel). Zugriff über config('hub.calendar.*').
 */
return [

    'mapping_version' => 1,

    'sweep' => [
        'required_misses' => (int) env('HUB_CALENDAR_SWEEP_REQUIRED_MISSES', 1),
    ],

    'http' => [
        'timeout_seconds' => (int) env('HUB_CALENDAR_HTTP_TIMEOUT', 60),
        'connect_timeout_seconds' => (int) env('HUB_CALENDAR_HTTP_CONNECT_TIMEOUT', 10),
    ],
];
