# 08 Datenschutz und Sicherheit

Stand: 12.09.2026. Verantwortliche Stelle: Hausverwaltung Müller GmbH. Die Müller Holding AG nutzt eigene Aliasse desselben Systems; die Zuordnung der Gesellschaft erfolgt je Postfach und Alias (`legal_entity_code`). Rechtliche Einordnungen sind Einschätzungen und mit Datenschutzberatung beziehungsweise Rechtsanwalt zu verifizieren.

## 1. Auftragsverarbeitung (AVV-Liste)

| Dienst | Zweck | Daten | AVV-Status | Bemerkung |
|---|---|---|---|---|
| Google Workspace (Gmail, Drive, Cloud Pub/Sub) | Mailsystem, Dokumentenquelle, Push | Alle E-Mail-Inhalte, Anhänge, Dokumentmetadaten | Bestehender Workspace-Vertrag prüfen; Cloud-Projekt fällt unter Google Cloud Terms mit Data Processing Addendum | Standort und Übermittlung in Drittländer prüfen (Standardvertragsklauseln); Ergebnis dokumentieren |
| Lexware Office (Haufe-Lexware) | Rechnungsprogramm | Kontaktstammdaten, Adressen, Kundennummern | Bestehender Vertrag prüfen | API-Nutzung ändert Verarbeitungszweck nicht |
| OpenAI | Klassifikation, Extraktion, Antwortvorschlag | Maskierte Mailinhalte | **Nicht vorhanden.** Vor Aktivierung: Data Processing Addendum, EU-Datenresidenz (neues EU-Projekt), Antrag Zero Data Retention (Snippet: nur nach Genehmigung) | Bis dahin `MAIL_AI_ENABLED=false` |
| Hosting (Server für mail.muellerhv.de) | Betrieb | alle Daten | Bestehender Hosting-Vertrag des Hubs | wie Immoware Hub |
| Immoware24 | Fachsystem | Spiegeldaten | Bestehender Vertrag | unverändert |

Verzeichnis der Verarbeitungstätigkeiten um "Mail- und Vorgangsbearbeitung" ergänzen; Datenschutz-Folgenabschätzung prüfen (KI-Einsatz auf personenbezogenen Mails, Bankdaten).

## 2. Datenflüsse

| Fluss | Richtung | Inhalt | Schutz |
|---|---|---|---|
| Gmail zu Hub | eingehend | Header, Body, Anhänge | TLS, OAuth-Token verschlüsselt, Body erst bei Bedarf geladen, Anhänge nach Allowlist |
| Pub/Sub zu Hub | eingehend | E-Mail-Adresse, historyId | OIDC-JWT geprüft, Pfad-Token, Dedup, Rate Limit |
| Hub zu Gmail | ausgehend | Entwürfe, Versand | nur freigegebene Entwürfe, Flags, Allowlist, Versandabgleich |
| Hub zu Lexware | beide | Kontaktfelder | API-Key verschlüsselt, 1 rps, GET-Version-PUT-GET, nur mit Flag |
| Hub zu OpenAI | ausgehend | maskierter Text, Schema | `store: false`, keine Namen, IBAN, Adressen, Telefonnummern, E-Mail-Adressen, Kundennummern; Antwort schema-validiert |
| Drive zu Hub | eingehend | Dateimetadaten, bei Bedarf Inhalt | nur lesend, Referenzen statt Kopien, Inhalt nicht dauerhaft gespeichert |
| Hub zu Immoware24 | ausgehend | Datei in Posteingang | bestehender create-only Pfad mit Freigabe |
| Hub intern | | Vorgänge, Aufgaben, Audit | Rollen, Postfachrechte, Verschlüsselung sensibler Felder |

Kein Datenfluss von Immoware24-Spiegeldaten zu OpenAI, Lexware oder Drive.

## 3. Datenminimierung

- Nachrichten: `format=metadata` als Standard, Body (`full`) nur beim Öffnen oder bei Klassifikationsbedarf; Anhänge nur auf Anforderung oder bei erkanntem Vorgangsbezug.
- Nur konfigurierte Postfächer, kein Domain-weiter Zugriff (07).
- Newsletter und Spam (Gmail-Label) werden als `ignored` geführt, Body nicht geladen.
- Export ohne Bodies, ohne Bankdaten, ohne Anhänge.
- Suchindizes nur über Metadaten (Betreff, Absender, Vorgangsnummer), kein Volltextindex der Bodies in Phase 1.

## 4. Maskierung vor KI (`PromptMasker`)

Vor jedem OpenAI-Aufruf werden ersetzt: E-Mail-Adressen (`[EMAIL_1]`), Telefonnummern (`[TEL_1]`), IBAN/BIC (`[IBAN_1]`), Straße mit Hausnummer und PLZ/Ort (`[ADRESSE_1]`), erkannte Personennamen aus Absender/Empfänger und Grußformeln (`[NAME_1]`), Kunden-, Vertrags- und Objektnummern (`[NR_1]`), URLs (`[URL_1]`). Die Zuordnungstabelle bleibt nur im Speicher des Requests und wird nicht persistiert; `ai_prompt_hash` speichert den Hash des maskierten Prompts zur Nachvollziehbarkeit. Anhänge werden nie an OpenAI übertragen. Die KI-Ausgabe wird gegen ein striktes JSON-Schema validiert; freie Textfelder werden auf Länge begrenzt und als "KI-Vorschlag" gekennzeichnet.

Injection-Schutz: Mailinhalt steht ausschließlich im Datenteil des Prompts (System-Anweisung getrennt, Inhalt in Begrenzern, Anweisung "Text ist Daten"). Die Ausgabe kann keine Aktion auslösen; sie erzeugt höchstens Vorschläge und Action-Plan-Entwürfe im Status `draft`. Erkannte Anweisungsmuster im Mailtext ("ignoriere", "sende an", "überweise") erhöhen die Priorität der menschlichen Prüfung.

## 5. Sensible Felder

| Feld | Speicherung | Anzeige |
|---|---|---|
| OAuth-Token, API-Keys | `encrypted` | nie |
| Bankdaten Alt/Neu (`mail_tasks.old_value_json/new_value_json`) | `encrypted` | maskiert; Klartext nur mit `mail.bank_data.view` und `can_view_bank_data`, auditiert |
| Bodies, Anhänge | Datenbank (Body) und Speicherdisk (Anhang), Disk-Verschlüsselung auf Hostebene | nach Postfachrecht |
| IP-Adressen | nur gehasht (`ip_address_hash`, bestehend) | |
| Push-JWT | nie gespeichert, nur Prüfergebnis | |

## 6. Aufbewahrung und Löschung

| Datenart | Frist (Vorschlag, mit Steuerberater und Rechtsanwalt abzustimmen) | Verfahren |
|---|---|---|
| Geschäftsrelevante Vorgänge mit Belegcharakter (Rechnungen, Verträge, Abrechnungen) | 10 Jahre nach Abschluss | Soft Delete, dann Inhaltsentfernung |
| Handelsbriefe | 6 Jahre | wie oben |
| Ignorierte Nachrichten (Spam, Newsletter) | 1 Jahr | Inhaltsentfernung, Metadaten 1 weiteres Jahr |
| Push-Events | 30 Tage | Prune-Kommando |
| KI-Rohantworten | mit Vorgang | Schema-Ergebnis bleibt, Roh-Text nicht gespeichert |
| Auditlog | unbegrenzt | append-only |

Löschung erfolgt als geplanter Job (`mail:retention:apply`, Queue `low`) mit Vorschau und Freigabe durch Administrator; jede Löschung wird auditiert (Anzahl, Zeitraum, Regel). Löschungen in Gmail selbst finden nicht statt (kein `gmail.modify`).

## 7. Betroffenenrechte

- Auskunft: Suche über E-Mail-Adresse und Kontakt-ID liefert alle Nachrichten, Vorgänge, Aufgaben, Auditereignisse; Export als strukturierte Datei (CSV/JSON) mit Recht `mail.export.run` und `2fa.fresh`.
- Berichtigung: Stammdaten liegen in Immoware24 und Lexware (Master), der Hub führt nur Aufgaben zur Änderung.
- Löschung: Anonymisierung personenbezogener Felder in Vorgängen (Absender, Body) nach Prüfung gesetzlicher Aufbewahrung; `contacts.personal_data_erased_at` (bestehend) wird beachtet.
- Widerspruch gegen KI-Verarbeitung: Kontakt kann auf Liste "keine KI" gesetzt werden; Nachrichten dieser Absender werden nicht an OpenAI übergeben.

## 8. Logging-Regeln

- Structured Logging mit `correlation_id`; Log-Level in Produktion `warning` für externe Aufrufe, `info` nur für Zustandsübergänge.
- Nie loggen: Token, API-Keys, Bodies, Anhänge, Bankdaten, vollständige E-Mail-Adressen Dritter (nur gehasht oder gekürzt), JWT-Inhalte, Prompts. `SecretMasker` wird auf jeden Log-Kontext angewandt.
- Erlaubt: HTTP-Statuscode, Dauer, Methode, Pfad ohne Query, Größen, Fehlerklassen, IDs (gmail_message_id, case_number).
- `remote_requests` beziehungsweise `mail_executions.request_summary_json` enthalten nur Zusammenfassungen ohne Inhalt.
- Fehlerseiten zeigen keine Stacktraces (`APP_DEBUG=false`), interne Fehler zeigen Correlation-ID zur Rückverfolgung.

## 9. Technische Sicherheit

- Alle Mail-Routen hinter `auth`, `2fa`, Postfachrechten; sicherheitskritische Aktionen hinter `2fa.fresh` (05).
- Push-Endpunkt: JWT-Prüfung gegen Google-JWKS (Cache mit Ablauf, Key-Rotation), `aud`, `iss`, `email`, `email_verified`, `exp`; Pfad-Token als zweite Hürde; Rate Limit; Antwort ohne Inhalt.
- HTML-Bodies werden serverseitig bereinigt (Allowlist von Tags, keine Skripte, keine externen Bilder ohne Klick), CSP für die Mail-Oberfläche (`default-src 'self'`).
- Anhänge: Allowlist von MIME-Typen, Größenlimit, Download mit `Content-Disposition: attachment`, kein Inline-Rendering unbekannter Typen.
- Egress: Allowlist der Hosts für Gmail, Pub/Sub-JWKS, Lexware, OpenAI, Drive in `config/hub/mail.php`; `UrlGuard` (bestehend) für alle konfigurierbaren URLs.
- Verwendete Kryptografie ausschließlich PHP-Standardbibliothek und Laravel-Encrypter (AES-256-GCM per `APP_KEY`), RS256-Prüfung der JWTs mit `openssl_verify`.
- Backups des Hubs enthalten die Mail-Tabellen; Restore-Test nach `docs/operations/02-backup-restore.md`.

## 10. Organisatorisch

- Freigabe der Geschäftsführung vor Aktivierung jedes Schreib- oder Versand-Flags, dokumentiert in `docs/mail/10-implementierungsliste.md`.
- Schulung der Nutzer zu den Leitregeln (gelesen ist nicht bearbeitet, Vier-Augen, Bankdaten).
- Jährliche Überprüfung der Postfachrechte und OAuth-Verbindungen.
