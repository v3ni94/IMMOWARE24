<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Playbooks (Prozessdatenbank für Mail-Vorgänge). Zugriff über config('hub.playbooks.*').
 * Eine Prozessvorlage (Playbook) fasst zusammen, wie Vorgänge einer Kategorie in der Vergangenheit bearbeitet
 * wurden. Ein neuer Vorgang wird zunächst regelbasiert (kostenlos, ohne KI-Aufruf) mit vorhandenen Vorlagen
 * verglichen; nur bei unklarem Ergebnis wird die KI hinzugezogen. Vorschläge wirken nie automatisch, eine Person
 * entscheidet über Annahme, Anpassung oder Ablehnung (Vier-Augen-Grundsatz wie beim übrigen KI-Einsatz).
 */
return [

    'flags' => [
        'enabled' => (bool) env('HUB_PLAYBOOKS_ENABLED', false),
        // Zusätzlich zu hub.mail.flags.ai (Hauptschalter) und hub.mail.providers.ai=live.
        'ai' => (bool) env('HUB_PLAYBOOKS_AI_ENABLED', false),
    ],

    'match' => [
        // Ab diesem Ähnlichkeitswert (0 bis 100) gilt eine Vorlage ohne KI-Rückfrage als passend (schneller,
        // kostenloser Pfad). Darunter, aber über low_threshold, wird die KI zur Einschätzung und für mögliche
        // Abweichungen hinzugezogen, sofern eingerichtet und freigegeben.
        'high_threshold' => (int) env('HUB_PLAYBOOKS_MATCH_HIGH_THRESHOLD', 80),
        'low_threshold' => (int) env('HUB_PLAYBOOKS_MATCH_LOW_THRESHOLD', 35),
        // Höchstzahl an Kandidatenvorlagen, die der KI bei einer Rückfrage vorgelegt werden.
        'max_candidates' => (int) env('HUB_PLAYBOOKS_MATCH_MAX_CANDIDATES', 5),
    ],

    // Stichwortgewinnung aus Titel und Kategorie eines Vorgangs für den regelbasierten Vergleich.
    'keywords' => [
        'min_length' => 4,
        'max_keywords' => 12,
        'stopwords' => [
            'der', 'die', 'das', 'und', 'oder', 'für', 'mit', 'von', 'bei', 'zum', 'zur', 'ist', 'sind', 'wurde',
            'wurden', 'bitte', 'sehr', 'geehrte', 'geehrter', 'damen', 'herren', 'herr', 'frau', 'freundlichen',
            'gruessen', 'grüßen', 'mfg', 'hallo', 'moin', 'bezüglich', 'betreff', 'anbei', 'ihre', 'ihrem', 'ihren',
        ],
    ],
];
