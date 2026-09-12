<?php

declare(strict_types=1);

/*
 * Konfiguration des Moduls Ai (OpenAI-Adapter). Zugriff über config('hub.ai.*'). Alle Aussagen zur OpenAI-API
 * (Endpunkte, Feldnamen, Structured Outputs) stammen aus Snippets und sind vor Inbetriebnahme am Original zu prüfen.
 * KI-Ausgaben sind stets Vorschläge (mail_ai_suggestions, Status proposed), werden Schema- und fachlich validiert und
 * wirken nie ohne menschliche Entscheidung. Eingaben werden vor dem Aufruf maskiert (PromptMasker).
 * Ohne Modellname (MAIL_AI_MODEL leer) gilt die Integration als "Nicht eingerichtet"; es gibt keinen Standardwert.
 */
return [

    'base_url' => env('MAIL_AI_BASE_URL', 'https://api.openai.com/v1'),
    'api_key' => env('MAIL_AI_API_KEY'),
    // Kein Default: der Modellname ist eine betriebliche Entscheidung und wird nicht behauptet.
    'model' => env('MAIL_AI_MODEL'),

    // responses (Responses API mit Structured Outputs json_schema strict) oder chat (Chat Completions, response_format).
    // Aus Snippets, am Original zu prüfen.
    'api_mode' => env('MAIL_AI_API_MODE', 'responses'),
    'endpoints' => [
        'responses' => '/responses',
        'chat' => '/chat/completions',
    ],

    // Keine Speicherung beim Anbieter (store: false laut Snippets). AVV und EU-Verarbeitung durch Geschäftsführung zu klären.
    'store' => false,
    'temperature' => 0,
    'timeout_seconds' => (int) env('MAIL_AI_TIMEOUT_SECONDS', 60),
    'connect_timeout_seconds' => 10,
    'max_input_chars' => (int) env('MAIL_AI_MAX_INPUT_CHARS', 20000),
    'max_output_tokens' => (int) env('MAIL_AI_MAX_OUTPUT_TOKENS', 2000),

    // Begrenzter Retry bei 429 und 5xx (Anzahl Wiederholungen, Wartezeiten in Millisekunden).
    'retry' => [
        'times' => (int) env('MAIL_AI_RETRY_TIMES', 2),
        'sleep_ms' => [500, 2000],
    ],

    /*
     * Kostenbudget. Kosten werden nur geschätzt, wenn Preise je 1 Mio. Token konfiguriert sind (Cent, leer = keine
     * Schätzung, cost_cents bleibt null). Zusätzlich gilt ein Tokenbudget, damit die Grenze auch ohne Preis wirkt.
     * Überschreitung: Lauf mit Status budget_exceeded, kein Aufruf, Oberfläche zeigt "KI pausiert (Budget)".
     */
    'budget' => [
        'daily_cents' => (int) env('MAIL_AI_DAILY_BUDGET_CENTS', 500),
        'monthly_cents' => (int) env('MAIL_AI_MONTHLY_BUDGET_CENTS', 5000),
        'daily_tokens' => (int) env('MAIL_AI_DAILY_TOKENS', 2000000),
        'monthly_tokens' => (int) env('MAIL_AI_MONTHLY_TOKENS', 30000000),
    ],
    'pricing' => [
        // Cent je 1.000.000 Token, z. B. 40 für 0,40 EUR. Leer = kein Preis konfiguriert.
        'input_cents_per_million' => env('MAIL_AI_PRICE_INPUT_CENTS_PER_MILLION'),
        'output_cents_per_million' => env('MAIL_AI_PRICE_OUTPUT_CENTS_PER_MILLION'),
    ],

    // Maskierung vor dem Aufruf. IBAN immer, Telefon und fremde E-Mail-Adressen optional.
    'masking' => [
        'iban' => true,
        'phone' => (bool) env('MAIL_AI_MASK_PHONE', true),
        'email' => (bool) env('MAIL_AI_MASK_EMAIL', true),
        // Eigene Domains bleiben lesbar (Absender der Hausverwaltung, keine Dritten).
        'own_domains' => ['muellerhv.de', 'mueller-holding.ag'],
        // docs/mail/08 Abschnitt 4: URLs, Straße mit Hausnummer plus PLZ/Ort, Kunden-, Vertrags- und Objektnummern,
        // Personennamen aus Kopfzeilen, Anrede und Grußformel.
        'url' => (bool) env('MAIL_AI_MASK_URL', true),
        'address' => (bool) env('MAIL_AI_MASK_ADDRESS', true),
        'numbers' => (bool) env('MAIL_AI_MASK_NUMBERS', true),
        'names' => (bool) env('MAIL_AI_MASK_NAMES', true),
    ],

    'tasks' => ['classify', 'summarize', 'extract', 'split_issues', 'match_candidates', 'draft_reply', 'next_steps'],

    // Allowlist der Aktionstypen, die die KI in next_steps vorschlagen darf. Alles andere wird verworfen.
    'allowed_action_types' => [
        'reply_question', 'reply_interim', 'internal_review', 'request_documents', 'schedule_appointment',
        'forward_internal', 'create_task', 'assign_case', 'set_priority', 'link_object', 'no_action',
    ],

    // Fachliche Kategorien für classify (case_type).
    'case_types' => [
        'schaden_notfall', 'schaden', 'reparatur', 'abrechnung', 'zahlung', 'mahnung', 'vertrag', 'kuendigung',
        'versammlung', 'beschwerde', 'anfrage', 'behoerde', 'gericht', 'rechnung_lieferant', 'spam', 'sonstiges',
    ],

    // Maximale Länge eines Dokumentauszugs (Drive) im KI-Kontext in Zeichen.
    'max_context_excerpt_chars' => (int) env('MAIL_AI_MAX_CONTEXT_EXCERPT_CHARS', 1500),
    'max_context_excerpts' => (int) env('MAIL_AI_MAX_CONTEXT_EXCERPTS', 5),
];
