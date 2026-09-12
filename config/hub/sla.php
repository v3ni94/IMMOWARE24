<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Sla. Zugriff über config('hub.sla.*'). Startwerte aus docs/mail/04-status-und-sla.md,
 * durch die Geschäftsführung zu bestätigen. Berechnung in Europe/Berlin (Sommerzeit über DateTimeZone),
 * Speicherung UTC. Empfangszeit ist die Gmail internalDate der Nachricht; ein späterer Import verjüngt keine Frist.
 */
return [

    'timezone' => 'Europe/Berlin',

    // Arbeitszeit Montag bis Freitag 08:00 bis 16:30 (Startvorschlag), Intervalle je Wochentag.
    'weekly_hours' => [
        'mon' => [['08:00', '16:30']],
        'tue' => [['08:00', '16:30']],
        'wed' => [['08:00', '16:30']],
        'thu' => [['08:00', '16:30']],
        'fri' => [['08:00', '16:30']],
        'sat' => [],
        'sun' => [],
    ],

    // Länge eines Arbeitstags in Minuten für die Umrechnung "Arbeitstage" in Arbeitsminuten (8,5 h).
    'work_day_minutes' => 510,

    // Feiertage: gesetzliche Feiertage Nordrhein-Westfalen werden berechnet (HolidayProvider), zusätzliche Tage hier
    // als JJJJ-MM-TT (z. B. Betriebsferien). Betriebsfreie halbe Tage sind nicht abgebildet.
    'holiday_region' => 'NW',
    'additional_holidays' => [],

    // Gelb ab diesem Anteil der Zielzeit, rot bei Überschreitung.
    'warn_percent' => 50,

    // Vier Uhren je Teilanliegen.
    'clock_types' => ['acknowledge', 'first_qualified_reply', 'next_update', 'resolution'],

    'clock_labels' => [
        'acknowledge' => 'Annahme',
        'first_qualified_reply' => 'Erste qualifizierte Antwort',
        'next_update' => 'Nächster Zwischenstand',
        'resolution' => 'Lösung',
    ],

    /*
     * Zielwerte in Minuten. P0 in Kalenderzeit, P1 bis P3 in Arbeitszeit (Arbeitstag = work_day_minutes).
     * P0 Annahme 10 Minuten, Eskalation nach 5 Minuten; P1 4 Arbeitsstunden; P2 2 Arbeitstage; P3 4 Arbeitstage
     * (Auftrag). Übrige Werte Startvorschläge.
     */
    'defaults' => [
        'p0' => ['acknowledge' => 10, 'first_qualified_reply' => 30, 'next_update' => 60, 'resolution' => 240, 'uses_calendar' => false, 'escalate_after_minutes' => 5],
        'p1' => ['acknowledge' => 240, 'first_qualified_reply' => 240, 'next_update' => 510, 'resolution' => 1020, 'uses_calendar' => true, 'escalate_after_minutes' => null],
        'p2' => ['acknowledge' => 1020, 'first_qualified_reply' => 1020, 'next_update' => 1020, 'resolution' => 2550, 'uses_calendar' => true, 'escalate_after_minutes' => null],
        'p3' => ['acknowledge' => 2040, 'first_qualified_reply' => 2040, 'next_update' => 2040, 'resolution' => 5100, 'uses_calendar' => true, 'escalate_after_minutes' => null],
    ],

    // Pause der Uhr resolution bei waiting_external nur für diese Prioritäten und höchstens so viele Minuten
    // (keine unbegrenzte Pause). Danach läuft die Uhr automatisch weiter und das Protokoll vermerkt pause_limit_reached.
    'pause_allowed_priorities' => ['p2', 'p3'],
    'max_pause_minutes' => 14400,

    // Altmail: liegt der Empfang beim Import länger zurück, gilt P3, außer die Regelerkennung findet P0/P1-Merkmale.
    // P0-Merkmale in Altmails ergeben P1 mit Hinweis an die Teamleitung statt Notfall-Eskalation.
    'stale_after_days' => 3,

    /*
     * Regelbasierte Prioritätsvorstufe (keine KI, kein Modelltraining). Kleinschreibung, Teilstring-Treffer.
     * KI-Vorschläge dürfen P0 nie herabstufen (PriorityClassifier::merge).
     */
    'priority_rules' => [
        'p0' => ['wasser tritt aus', 'wasser läuft', 'wasserrohrbruch', 'rohrbruch', 'brand', 'feuer', 'gasgeruch', 'gas riecht', 'akute gefahr', 'lebensgefahr', 'einsturz', 'explosion', 'rauch'],
        'p1' => ['wasserschaden', 'heizungsausfall', 'heizung ausgefallen', 'stromausfall', 'aufzug steckt', 'einbruch', 'notfall', 'kein warmwasser', 'tür schließt nicht', 'schimmel'],
    ],

    // Marker für verneinte oder historische Formulierungen im Umfeld eines Notfallbegriffs: konservativ P1 mit
    // Prüfhinweis, nie automatisch P0 herabstufen, nie unter P1.
    'negation_markers' => ['kein ', 'keine ', 'keinen ', 'nicht ', 'ohne ', 'behoben', 'erledigt', 'damals', 'letztes jahr', 'letzten jahr', 'vergangenes jahr', 'im jahr 20', 'seinerzeit', 'war ', 'hatte ', 'hatten ', 'wurde bereits', 'vorfall vom'],
    'negation_window_chars' => 60,

    // Notfall: Queue, Annahmefrist, Eskalationskette (Rollen in Reihenfolge), Kanäle.
    'emergency' => [
        'queue' => env('MAIL_QUEUE_HIGH', 'mail-high'),
        'acknowledge_minutes' => 10,
        'escalate_after_minutes' => 5,
        'max_escalation_level' => 4,
        // assignee, team_lead, escalation_contact, owner
        'chain' => ['assignee', 'team_lead', 'escalation_contact', 'owner'],
        // Kanäle: email (Laravel Mailer), webhook (Http), sms und call nur Stub mit Status not_configured.
        'channels' => ['email', 'webhook', 'sms', 'call'],
        'webhook_url' => env('MAIL_EMERGENCY_WEBHOOK_URL'),
        'email_from' => env('MAIL_EMERGENCY_FROM', env('MAIL_FROM_ADDRESS', 'noreply@example.com')),
        // Bereitschaft: Nutzer-IDs, die außerhalb der Arbeitszeit alarmiert werden. Leer: Hinweis
        // "keine 24/7-Betreuung eingerichtet" im Alarm und in der Oberfläche.
        'on_call_user_ids' => array_values(array_filter(array_map('intval', explode(',', (string) env('MAIL_ON_CALL_USER_IDS', ''))))),
    ],

    'not_on_call_notice' => 'Keine 24/7-Betreuung eingerichtet. Notfälle außerhalb der Arbeitszeit werden erst zum nächsten Arbeitsbeginn bearbeitet.',
];
