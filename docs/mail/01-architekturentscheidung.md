# 01 Architekturentscheidung: Mail- und Vorgangsbearbeitung in derselben Anwendung

Stand: 12.09.2026. Status: Entwurf zur Freigabe durch die Geschäftsführung. Bezug: `docs/mail/00-bestandsaufnahme.md`, `docs/adr/0001-modularer-monolith.md`.

## 1. Entscheidung

Das Mail-Modul wird als Gruppe zusätzlicher Module in der bestehenden Laravel-Anwendung des Immoware Hub betrieben und unter `https://mail.muellerhv.de` über domaingebundene Routen (`Route::domain('mail.muellerhv.de')`) ausgeliefert. Keine zweite Anwendung, keine zweite Datenbank, kein zweites Deployment.

## 2. Begründung

| Kriterium | Gleiche Anwendung, eigene Domain (gewählt) | Zweite Anwendung |
|---|---|---|
| Wirtschaftlichkeit | Ein Deployment, ein Worker-Pool, ein Backup, eine CI | Doppelte Betriebskosten, doppelte Pflege |
| Fachliche Kopplung | Vorgänge brauchen Kontakte, Objekte, Verträge, Capabilities, Audit in derselben Transaktion; direkter Modellzugriff statt API-Umweg | Alles über REST v1 mit API-Keys, Eventual Consistency, doppelte Mandantenlogik |
| Sicherheit | Ein Nutzerstamm, eine 2FA, eine Permission-Map, eine Audit-Kette | Zwei Identitätsspeicher, zwei Audit-Ketten, doppelte Angriffsfläche |
| Risiko für den Bestand | Additive Module, eigene Tabellen mit Präfix, eigene Middleware-Gruppe, Domain-Trennung der Routen | Kein Risiko für den Bestand, aber hohe Integrationskosten |
| Umsetzbarkeit ohne neue Pakete | Alle Bausteine (Http, Queue, Encryption, Blade) vorhanden | Gleich, aber doppelt |

Das Restrisiko der gemeinsamen Anwendung (ein Fehler im Mail-Modul trifft den Prozess des Hubs) wird durch Feature-Flags, getrennte Queues und die Modulisolation begrenzt.

## 3. Domain und Routing

- Alle Mail-Routen in `routes/modules/mail.php` (und den Untermodulen) werden vom `MailServiceProvider` mit `Route::domain(config('hub.mail.domain'))` umschlossen. Standardwert `MAIL_DOMAIN=mail.muellerhv.de`; in Tests `mail.test`.
- Bestehende Routen bleiben ohne Domain-Bindung und damit auf `immoware.muellerhv.de` erreichbar wie bisher. Ein Aufruf von `/admin` über `mail.muellerhv.de` bleibt technisch möglich; der nginx-Server-Block für mail.muellerhv.de leitet Pfade außerhalb `/mail`, `/login`, `/security`, `/two-factor`, `/logout`, `/up`, `/css`, `/js` mit 404 ab (09). Zusätzlich prüft eine Middleware `mail.domain` den Host und antwortet bei Abweichung mit 404.
- Routennamen `mail.*`, Pfadpräfix `/mail` (Beispiel `https://mail.muellerhv.de/mail/cases/123`). Die Startseite `/` der Mail-Domain leitet auf `/mail`.
- Anmeldung: Die Login-Routen des Moduls Security sind nicht domaingebunden und funktionieren unter beiden Hosts. `url.intended` und Redirects bleiben hostrelativ.

## 4. Sitzungen und Cookies

- `SESSION_DOMAIN` bleibt leer. Damit ist das Session-Cookie an den jeweiligen Host gebunden (`config/session.php` Zeile 160: exakter Host, kein Domain-Wildcard). Eine Anmeldung auf `immoware.muellerhv.de` gilt nicht automatisch auf `mail.muellerhv.de` und umgekehrt. Das ist gewollt: getrennte Sitzungen, getrennte absolute Laufzeiten, kein Cookie-Leak zwischen den Anwendungsteilen.
- Cookie-Name bleibt `APP_NAME`-abhängig; kein Handlungsbedarf, da hostgebunden.
- `SESSION_SECURE_COOKIE=true`, `SameSite=strict` bleiben. Der Pub/Sub-Push-Endpunkt ist zustandslos (keine Session, kein CSRF, eigene Middleware-Gruppe, siehe 5).

## 5. Middleware-Gruppen

| Gruppe | Zusammensetzung | Zweck |
|---|---|---|
| `mail` | `web`, `auth`, `mail.domain`, `mail.access`, `2fa` | Alle Oberflächenrouten des Mail-Moduls. `mail.access` entspricht `EnsureAdminAccess` (Rollencheck, Kontosperre, `OrganizationContext`), erweitert um die Mail-Basisberechtigung `mail.access`. |
| `mail.push` | `mail.domain`, `throttle:mail-push`, `mail.push.auth` | Pub/Sub-Push ohne Session und CSRF. `mail.push.auth` prüft das OIDC-JWT (Signatur gegen Google-JWKS, `aud`, `email`, `email_verified`) plus ein eigenes Token im Pfad. Nicht eingerichtete Push-Konfiguration antwortet 404. |
| `mail.fresh` | zusätzlich `2fa.fresh` | Freigaben, Versand, Bankdatenansicht, Integrationsänderungen, Export (05). |

Die Gruppen werden im `MailServiceProvider` per `Router::middlewareGroup()` registriert (wie `AdminServiceProvider`). Die Gruppe `web` wird nicht verändert.

## 6. Queues

Neue Queues, additiv zu `high, default, sync, write, documents, low`:

| Queue | Inhalt | Priorität im Worker |
|---|---|---|
| `mail-high` | Notfallerkennung (P0), Eskalationen, Benachrichtigungen, SLA-Uhrenprüfung | Vor `high` |
| `mail-sync` | Gmail-History-Abgleich, Nachrichtenabruf, Anhänge, Watch-Erneuerung, Versandabgleich, Lexware- und Drive-Leseläufe | Zwischen `default` und `sync` |
| `mail-ai` | Klassifikation, Extraktion, Antwortvorschläge über OpenAI-Adapter | Nach `sync`, vor `low` |

Worker-Kommando (compose, supervisor, systemd) wird zu `--queue=mail-high,high,default,mail-sync,sync,write,documents,mail-ai,low` erweitert. Alternativ ein zweiter Worker-Prozess nur für `mail-*` (empfohlen in Produktion, damit ein hängender Gmail-Abruf keine Immoware-Syncs blockiert). Queue-Namen in `config/hub/mail.php` unter `queues`.

Jobs folgen dem Muster `SyncJobRetries`: `maxExceptions` 5, Backoff 30 s, 120 s, 600 s, 1800 s, `failed()` schreibt in `dlq_items` über `DlqService::store()`. Locks je Postfach über `Cache::lock('mail:sync:<mailbox_id>')` analog `SyncLockManager`.

## 7. Modulschnitt

Alle Module unter `app/Modules/`, Namensraum `App\Modules\<Name>`, Provider in `bootstrap/providers.php` nach `AdminServiceProvider` eingetragen.

| Modul | Verantwortung | Öffentliche Services (für andere Module) |
|---|---|---|
| `Mail` | Kern: Teams, Teammitglieder, Postfächer, Aliasse, Postfachrechte, Feature-Flags, Middleware-Gruppen, Domain-Bindung, gemeinsame Enums, Integrationsstatus ("Nicht eingerichtet") | `MailboxAccessService`, `IntegrationStatusService`, `MailFeatureFlags` |
| `Gmail` | OAuth2 (Authorization Code, Refresh), Token-Ablage verschlüsselt, users.watch und Erneuerung, Push-Endpunkt, history.list, messages.get (metadata, dann full), Anhänge, MIME-Parser, MIME-Builder, drafts.create/update/send, sendAs.list, Versandabgleich (Sent-Label, Message-ID) | `GmailClient` (nur Http-Facade), `GmailSyncService`, `GmailDraftService`, `SendReconciliationService` |
| `Cases` | Vorgänge, Teilanliegen (case_items), Zuordnung Nachrichten zu Vorgang, externe Referenzen (Kontakt, Objekt, Einheit, Vertrag über IDs des Spiegels), Zuweisungsentscheidungen, Aufgaben, drei Statusdimensionen, Abschlussbedingungen | `CaseService`, `CaseAssignmentService`, `TaskService` |
| `Sla` | Arbeitskalender, Feiertage, vier Uhren, zwei Ampeln, Prioritäten P0 bis P3, Eskalationen, Notfallqueue (mail-high) | `WorkCalendar`, `SlaClockService`, `EscalationService` |
| `Actions` | Action Plans (geplante Schritte je Vorgang), Versionen, Freigaben (Vier-Augen), Ausführung über Adapter, Verifikation (Nachlesen), Allowlist erlaubter Aktionen je Zielsystem, Outbox | `ActionPlanService`, `ApprovalService`, `ActionExecutor`, `VerificationService`, `ActionAllowlist` |
| `Lexware` | Adapter Lexware Office Public API: Kontakt lesen/suchen, Kontakt aktualisieren (GET, Version, PUT, GET), Fehlerabbildung, Rate Limit 1 rps | `LexwareClient`, `LexwareContactService` |
| `Ai` | OpenAI-Adapter (Responses API, `store: false`), JSON-Schema-Validierung der Antworten, Maskierung vor dem Prompt, Injection-Schutz (Mailinhalt nur als Daten, Ausgaben nur als Vorschläge), Kostenzähler | `AiClassifier`, `AiExtractor`, `PromptMasker`, `SchemaValidator` |
| `Drive` | Google Drive v3 lesend: files.list, files.get, files.export, permissions.list, changes; Dokumentreferenzen je Vorgang | `DriveClient`, `DriveDocumentService` |
| `MailUi` | Blade unter `resources/views/mail`, Controller, Requests, Navigation, Layout `mail::layouts.app` (nutzt `hub.css`, `hub.js`), Bestätigungswort für gefährliche Aktionen | keine (nur Controller) |

Abhängigkeitsregeln:

- `MailUi` darf alle Mail-Services nutzen, kein Modul darf `MailUi` nutzen.
- `Cases`, `Actions` nutzen Immoware-Daten nur über `Contact`, `Property`, `Unit`, `Contract` (lesend), `CapabilityRegistryInterface`, `ProposedChangeService`, `PosteingangUploadService`.
- `Gmail`, `Lexware`, `Ai`, `Drive` kennen keine Vorgänge; sie liefern DTOs und werfen typisierte Exceptions (`MailIntegrationNotConfiguredException`, `MailRemoteException`).
- Jeder externe Aufruf läuft durch `RemoteRequestLogger` (bestehend) oder eine mail-eigene Tabelle `mail_remote_requests`, falls die bestehende `remote_requests.connection_id` (FK auf `immoware_connections`) nicht passt. Entscheidung in 02: eigene Spalte `mail_integration` in einer additiven Migration wird verworfen, stattdessen `mail_push_events` und `mail_executions` protokollieren Request-Ergebnisse; generische Aufrufe landen in `remote_requests` mit `connection_id = null` und `path` ohne Query-Secrets.

## 8. Feature-Flags

Alle Flags in `config/hub/mail.php`, Standard `false`, Lesen über `MailFeatureFlags`:

| Flag | Wirkung bei `false` |
|---|---|
| `MAIL_IMPORT_ENABLED` | Kein Gmail-Abruf, keine Watch-Erneuerung, Push-Endpunkt antwortet 404 |
| `MAIL_AI_ENABLED` | Kein OpenAI-Aufruf; Klassifikation nur regelbasiert, Oberfläche zeigt "KI nicht eingerichtet" |
| `MAIL_GMAIL_DRAFTS_ENABLED` | Entwürfe nur lokal in `mail_drafts`, kein `drafts.create` bei Gmail |
| `MAIL_GMAIL_SEND_ENABLED` | Kein `drafts.send`, kein `messages.send`; Schaltfläche "Senden" deaktiviert mit Hinweis |
| `MAIL_IMMOWARE_WRITE_ENABLED` | Kein `PosteingangUploadService::submit()` aus Vorgängen; bestehende Immoware-Flags (`IMMOWARE_WRITE_*`) müssen zusätzlich freigegeben sein |
| `MAIL_LEXWARE_WRITE_ENABLED` | Lexware nur lesend; Adressänderung wird als manuelle Aufgabe mit Alt/Neu geführt |

Ein Flag allein schaltet nichts frei: Zusätzlich müssen Zugangsdaten hinterlegt, die Integration im Status "eingerichtet" und das Recht des Nutzers vorhanden sein (05). Reihenfolge der Prüfung: Flag, Konfiguration, Recht, Vier-Augen-Freigabe, Ausführung, Verifikation.

## 9. Staging-Schutz

- `MailBootGuard` (analog `BootGuard`): In `APP_ENV=staging` oder wenn `MAIL_DOMAIN` nicht `mail.muellerhv.de` ist, dürfen `MAIL_GMAIL_SEND_ENABLED`, `MAIL_IMMOWARE_WRITE_ENABLED`, `MAIL_LEXWARE_WRITE_ENABLED` nicht true sein; sonst Abbruch beim Boot mit `RuntimeException`. In `testing` und `local` prüfbar über `HUB_BOOT_GUARD`.
- Staging nutzt ein eigenes Google-Cloud-Projekt, ein eigenes Testpostfach und einen Lexware-Sandbox-Key oder keinen Key. Produktionszugangsdaten dürfen in Staging nicht hinterlegt werden; `hub:doctor` gibt eine Warnung aus, wenn eine Gmail-Adresse der Produktionsdomain in Staging konfiguriert ist.
- Alle Versand- und Schreibaktionen tragen in Staging ein sichtbares Banner "Staging: Versand gesperrt".

## 10. Konsequenzen für den Bestand

- `bootstrap/providers.php`: acht neue Provider (additiv).
- `compose.yaml`, `deploy/supervisor`, `deploy/systemd`: Queue-Liste erweitert (additiv).
- `deploy/nginx/mail.muellerhv.de.conf`, `docker/nginx/mail.muellerhv.de.conf`: neue Server-Blöcke (09).
- `config/hub/security.php`: Permission-Katalog um `mail.*` erweitert (05), bestehende Rollenrechte unverändert.
- `App\Core\Enums\AuditSource`: neue Fälle `mail`, `gmail_push`, `ai` (additiv, keine Änderung bestehender Werte).
- `.env.example`: Block "Mail-Modul" mit allen Flags auf false.
- Keine Änderung an bestehenden Tests, Tabellen, Routen.

## 11. Verworfene Alternativen

- Subpfad `/mail` unter `immoware.muellerhv.de` statt eigener Domain: erfüllt die Vorgabe der Domain nicht, mischt Sitzungen und Navigation zweier Nutzergruppen.
- Zweite Laravel-Anwendung mit Zugriff über REST v1 und API-Keys: doppelte Sicherheitsinfrastruktur, kein transaktionaler Zugriff auf Kontakte und Audit (Abschnitt 2).
- Domain-wide Delegation für Gmail: nicht nötig für ein oder wenige Teampostfächer; Nutzer-OAuth mit Refresh-Token pro Postfach reicht (07).
