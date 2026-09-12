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

## 11. Security-Review 13.09.2026

Interner Code- und Sicherheitsreview des Mail-Moduls (Module Mail, Gmail, Cases, Sla, Actions, Lexware, Ai, Drive, MailUi, MailIntegration, Deployment) gegen CLAUDE.md und die Dokumente 01 bis 10. Gemeldet wurden **60 Findings** in drei Fix-Bereichen: Gmail/Drive/Ai (23), Cases/Sla/Actions (15), MailUi/MailIntegration/Mail/Betrieb (22). Regel des Fix-Laufs: Jeder Fix erhält einen Test, der den Fehler zuvor reproduziert; Pint nur auf eigene Pfade, PHPStan komplett. Abschlusslauf 13.09.2026: 836 Tests, 828 bestanden, 8 übersprungen, 6.115 Assertions, Pint und PHPStan Level 5 ohne Befund, `migrate:fresh` auf SQLite vollständig (43 Migrationen).

Schwere: Die Fix-Berichte überliefern die Einstufung des Reviews nur für ein Finding ausdrücklich (Gmail Nr. 5, kritisch). Die übrigen Findings werden hier ohne erfundene Schwere nach Wirkung gruppiert: **sicherheitsrelevant** (Rechte, Vier-Augen, Authentifizierung, Datenabfluss), **datenverlust- oder konsistenzrelevant** (Import, Cursor, Statusmaschinen), **betrieblich** (Queues, Deployment, Doku).

### 11.1 Status je Finding

| Nr. | Bereich | Finding | Wirkung | Status |
|---|---|---|---|---|
| G1 | Gmail | OAuth-Callback akzeptiert fremdes Google-Konto | sicherheitsrelevant | behoben (Profilabgleich, Token widerrufen, Audit `mail.gmail.oauth.mismatch`) |
| G2 | Ai | PromptMasker maskiert IBAN und PII unvollständig | sicherheitsrelevant | behoben (`[URL_n]`, `[ADRESSE_n]`, `[NR_n]`, `[NAME_n]`, IBAN normalisiert); Namenserkennung ohne Wörterbuch bleibt begrenzt |
| G3 | Gmail | JWKS-Amplifikation über unbekannte kid | sicherheitsrelevant | teilweise behoben (Negativ-Cache, ein Reload je Mindestabstand); globales Rate Limit `mail-push` offen |
| G4 | Drive | DriveAccessGuard gewährt Assignee Einsicht ohne Postfachrecht | sicherheitsrelevant | behoben (nur `canViewMailbox` bzw. Teammitgliedschaft) |
| G5 | Gmail | HistorySync verwirft Einträge beim Limit (kritisch) | datenverlustrelevant | behoben (Cursor nur bis letztem vollständig verarbeiteten Eintrag) |
| G6 | Gmail | Erstimport wird nie gestartet | datenverlustrelevant | behoben (`WatchService::renew` dispatcht `InitialImportJob`) |
| G7/G13 | Gmail | Push-Dedup verwirft Ereignis bei fehlgeschlagenem Dispatch | datenverlustrelevant | behoben (nur Unique-Verletzung gilt als Duplikat, Wiederholung plant Sync erneut) |
| G8 | Gmail | SendService Race bei parallelem Versand | konsistenzrelevant | behoben (bedingtes Update mit Revision); Unique-Index auf `mail_send_reconciliations` offen |
| G9 | Gmail | 404 beim Import still verworfen | datenverlustrelevant | behoben (verzögerte Wiederholung, dann als gelöscht protokolliert) |
| G10 | Gmail | Erstimport bleibt nach Fehler festgefahren | betrieblich | behoben (`failed()` setzt Zustand zurück) |
| G11 | Gmail | Draft-Konflikte Hub-intern nicht erkannt | konsistenzrelevant | behoben (`DraftConflictException('stale_revision')`) |
| G12 | Gmail, MailUi | Vier-Augen beim Versand nicht erzwungen | sicherheitsrelevant | behoben (`DraftService::approve`, `SendService` verweigert ohne Freigabe, Route und Oberfläche in MailUi, `DraftFourEyesTest`) |
| G14 | Gmail | DLQ und `WatchRenewJob::failed` fehlten | betrieblich | behoben |
| G15 | Gmail | Release-Schleife und Lock-TTL | betrieblich | behoben |
| G16 | Gmail | WatchRenew Priorität und unabhängiger Ablaufcheck | betrieblich | behoben (mail-high, `ReconcileJob` prüft Ablauf) |
| G17 | Gmail | PushController antwortet ohne Middleware-Ergebnis mit ok | sicherheitsrelevant | behoben (401) |
| G18 | Ai | `mail_ai_suggestions.payload_json` im Klartext | sicherheitsrelevant | offen, begründet abgelehnt (Spaltentyp `json()` verträgt keinen verschlüsselten String; Migration auf `longText` empfohlen) |
| G19 | Gmail | `sent_verified` ohne `sent_message_id` | konsistenzrelevant | behoben |
| G20 | Gmail | Mehrfacher FullResync | betrieblich | behoben |
| G21 | Gmail | Doppelte Ereignisse bei Re-Import | konsistenzrelevant | behoben |
| G22 | Drive | `corpora` ohne Beleg | Doku | behoben (nur aus Config, gekennzeichnet) |
| G23 | Gmail | `processDue`/`prune` ohne Limit | betrieblich | behoben |
| C1 | Cases | Closed ohne Abschlussprüfung | konsistenzrelevant | behoben |
| C2 | Sla | SLA-Uhren für Altmails laufen rot an | konsistenzrelevant | behoben (`LegacyImportPolicy`, Uhren `cancelled`) |
| C3 | Actions | Verifikation bleibt in `http_ok_unverified` | konsistenzrelevant | behoben (verzögerte Wiederholung, dann `result_unclear`) |
| C4 | Actions | 5xx nach PUT als failed/Konflikt | konsistenzrelevant | behoben (`result_unclear`, Nachlesen entscheidet) |
| C5 | Actions | Ablehnung nicht bindend | sicherheitsrelevant | behoben |
| C6 | Actions | Outbox ohne Verarbeiter | betrieblich | behoben (`ActionOutboxDispatcher`, Zeitplan minütlich) |
| C7 | Cases | Weiterleitung stoppt SLA-Uhren | konsistenzrelevant | behoben (nur Antwort an Vorgangsabsender zählt) |
| C8 | Actions | `write_enabled` der Verbindung ignoriert | sicherheitsrelevant | behoben |
| C9 | Sla | Notfallzustellung nicht idempotent | betrieblich | behoben |
| C10 | Actions | Scheduler: Approved ohne Ausführung | konsistenzrelevant | behoben (Transaktion, `afterCommit`, Nachfassen) |
| C11 | Actions | `required_approvals` 0 | sicherheitsrelevant | behoben (mindestens 1); Einschränkung `ApprovalController::retry` durch C5 abgedeckt |
| C12 | Actions | `recheck()` toter Pfad | betrieblich | behoben (`mail:actions:recheck-manual`, stündlich) |
| C13 | Sla | retarget und Pausenlimit in Kalenderminuten | konsistenzrelevant | behoben |
| C14 | Doku | Widerspruch Statusregel in 04 | Doku | behoben |
| C15 | Sla | Staging sendet Notfallalarme real | sicherheitsrelevant | behoben (`StagingGuard`, Umleitung oder `blocked`); Warnung in `hub:doctor` offen |
| U1 | MailUi | Vier-Augen Entwurfsversand in der Oberfläche | sicherheitsrelevant | behoben (siehe G12) |
| U2 | MailUi | Reauth-Nachweis ersetzt durch `now()` | sicherheitsrelevant | behoben (`isFresh()` im Controller, sonst 403 mit Audit) |
| U3 | MailUi | `assignee_user_id` ohne Organisationsprüfung | sicherheitsrelevant | behoben |
| U4 | MailIntegration | `status_business` nicht gespiegelt | konsistenzrelevant | behoben (`MirrorPlanStatusToCaseItem`); Abfrage im `CloseConditionChecker` offen |
| U5 | MailUi | Mailbox `configured` als Verbunden | Doku, Anzeige | behoben (`INCOMPLETE`, `REAUTH`) |
| U6 | MailUi | Integrationsstatus aus Config statt Bindung | Anzeige | behoben (`UNVERIFIED`, `FAKE`) |
| U7 | Doku | 06-testplan nennt nicht existierende Tests | Doku | behoben |
| U8 | Mail | immoware24 fest LIVE | Anzeige | behoben |
| U9 | Doku | Posteingang-Upload als umgesetzt geführt | Doku | behoben (offen gekennzeichnet) |
| U10 | Lexware | rps-Default 2 statt 1 | betrieblich | behoben |
| U11 | Betrieb | deploy.sh stoppt Mail-Worker nicht | betrieblich | behoben |
| U12 | Betrieb | Host-Trennung ohne Pfad-Allowlist | sicherheitsrelevant | behoben als App-Guard (`registerHostGuard`, `MailHostGuardTest`); nginx weist `/api` ab |
| U13 | Betrieb | compose Healthcheck ohne Aussage | betrieblich | behoben |
| U14 | Betrieb | `hub:doctor` ohne Mail-Prüfungen | betrieblich | behoben |
| U15 | Betrieb | Queue-Namen nicht geprüft | betrieblich | behoben |
| U16 | MailUi | Settings-Nutzerlisten ohne Organisationsfilter | sicherheitsrelevant | behoben |
| U17 | MailUi | `reject()` ohne Rechteprüfung | sicherheitsrelevant | behoben |
| U18 | Mail | CSP nur in nginx | sicherheitsrelevant | behoben (`MailSecurityHeaders`); sandboxed iframe bewusst nicht umgesetzt |
| U19 | MailUi | `status()`/`note()` ohne Recht | sicherheitsrelevant | behoben (`mail.task.manage`) |
| U20 | MailUi | Entwürfe/Versand als aktiv ohne Scope | Anzeige | behoben |
| U21 | Mail | Push-Rate-Limit nur je IP | sicherheitsrelevant | behoben (je Postfach, 600/min); Dashboard-Kennzahl offen |
| U22 | Datenmodell | Kaskadenlöschung auf Mail-Tabellen | datenverlustrelevant | behoben (Migration `2026_09_13_110001`, `restrictOnDelete`, No-op auf SQLite) |

### 11.2 Offene Punkte nach dem Fix-Lauf

1. Globales Rate Limit für `mail-push` (`Limit::perMinute(...)->by('global')`) zusätzlich zum Limit je Postfach (G3).
2. Unique-Index `(draft_id)` für offene Abgleiche auf `mail_send_reconciliations` (G8).
3. Spaltentyp `mail_ai_suggestions.payload_json` auf `longText` und Cast `encrypted:array` (G18); bis dahin liegen KI-Vorschläge unverschlüsselt in der Datenbank, IBAN-Felder werden maskiert gespeichert.
4. Warnung in `hub:doctor` bei Staging ohne `MAIL_EMERGENCY_TEST_RECIPIENT` (C15).
5. `CloseConditionChecker` fragt Aktionspläne direkt ab (U4, zweiter Teil).
6. Kennzahl "Push 429" im Dashboard (U21).
7. `mail:retention:apply` und Auskunftsexport (Abschnitt 6 und 7) weiterhin offen.
8. Durchreichen von `2fa.fresh` an `DraftService::approve` ist über die Route erledigt, ein Test der Freigabe ohne frische Zwei-Faktor-Sitzung fehlt.
