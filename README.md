## Mail-Modul

Stand 12.09.2026, Phase 1 umgesetzt und verdrahtet. Interne Mail- und Vorgangsbearbeitung der Hausverwaltung Müller GmbH unter `https://mail.muellerhv.de`, additiv in derselben Anwendung (domaingebundene Routen, eigene Middleware-Gruppe `mail`, eigene Queues `mail-high`, `mail-sync`, `mail-ai`, alle Tabellen mit Präfix `mail_`). Bestehende Funktionen, Routen, Tabellen und Tests bleiben unverändert; der Immoware-Connector wird nur über seine öffentlichen Services genutzt. Gmail ist Mailsystem, Immoware24 Fachsystem, Lexware Office Rechnungsprogramm, Google Drive Dokumentenquelle. Nicht konfigurierte Integrationen erscheinen sichtbar als "Nicht eingerichtet" (Oberfläche und `hub:doctor`).

Leitregeln: Gelesen ist nicht bearbeitet. Beantwortet ist nicht erledigt. Ein erfolgreicher HTTP-Aufruf ist kein verifiziertes Geschäftsergebnis. Jeder offene Vorgang hat Verantwortlichen, nächsten Schritt und Fälligkeit. Technische Fehler erscheinen nie als Erfolg.

Feature-Flags, alle Standard `false`: `MAIL_IMPORT_ENABLED`, `MAIL_AI_ENABLED`, `MAIL_GMAIL_DRAFTS_ENABLED`, `MAIL_GMAIL_SEND_ENABLED`, `MAIL_IMMOWARE_WRITE_ENABLED`, `MAIL_LEXWARE_WRITE_ENABLED`. Aktivierung einzeln nach Freigabe der Geschäftsführung; Schreib- und Versand-Flags sind in Staging gesperrt (`MailBootGuard`).

Module: `Mail` (Kern, Flags, Rechte, Middleware), `Gmail` (OAuth2, Push mit JWT-Prüfung, History-Sync, MIME, Entwürfe, Versand mit Abgleich), `Cases` (Vorgänge, Teilanliegen, drei Statusdimensionen, Zuordnung nur über Kennungen, Aufgaben, Sperren, Vertretung), `Sla` (Prioritäten, vier Uhren, Arbeitskalender, Notfallqueue), `Actions` (Aktionspläne, Vier-Augen, Identitätsprüfung, Ausführung mit Idempotenz, Verifikation), `Lexware`, `Ai` (nur Vorschläge, Schema-Validierung, Maskierung, Budget), `Drive` (lesend), `MailUi` (Blade-Oberfläche, Administration, Einrichtungsassistent), `MailIntegration` (Verdrahtung der Module untereinander und mit der Oberfläche, Ereignisketten, regelbasierte Vorgangsanlage). Zeitpläne zentral in `routes/console.php`; Betrieb in `compose.yaml` (Mail-Worker), `deploy/`, `docker/nginx/mail.muellerhv.de.conf`.

Stand der Tests (13.09.2026, Abschlusslauf 2 nach Abarbeitung der offenen Punkte aus `docs/mail/08-datenschutz-sicherheit.md` Abschnitt 11.2, siehe `docs/immoware/10-test-report.md` Abschnitt 6.3): `php artisan test` 896 Tests, 888 bestanden, 8 übersprungen, 6.781 Assertions; davon 321 Tests der Mail-Module einschließlich zwei End-to-End-Abläufen (`tests/Feature/MailEndToEnd/`). Pint und PHPStan Level 5 ohne Befund, `migrate:fresh` auf SQLite vollständig (46 Migrationen), `route:list` ohne Duplikate (205 Routen, davon 62 auf der Mail-Domain). Alle Fremdsysteme laufen in Tests als Fakes (`FakeGmailProvider`, `FakeLexwareApi`, `FakeAiProvider`, `Http::fake`); es gab keinen Live-Test gegen Gmail, Lexware, Drive oder OpenAI, und alle Aussagen zu deren APIs stammen aus WebSearch-Snippets (Doku-Hosts gesperrt) und sind vor Inbetriebnahme am Original zu prüfen. Ein Mock-Erfolg gilt nie als Live-Test.

Offen: Freigaben der Geschäftsführung je Flag, Google-Cloud-Projekt und Pub/Sub, Lexware-Key, OpenAI-AVV, Branding, Drive-OAuth-Anmeldefluss, Anhangsablage, Retention-Befehl, Übernahme modul-lokaler Verträge nach `app/Core/Contracts/Mail` (Details in `docs/mail/10-implementierungsliste.md`).

| Dokument | Inhalt |
|---|---|
| `docs/mail/00-bestandsaufnahme.md` | Befunde am Repository, Wiederverwendung, Schutzregeln |
| `docs/mail/01-architekturentscheidung.md` | Domain-Routing, Sitzungen, Middleware, Queues, Modulschnitt, Flags, Staging-Schutz |
| `docs/mail/02-datenmodell.md` | Alle `mail_`-Tabellen mit Spalten, Indizes, Fremdschlüsseln |
| `docs/mail/03-capability-matrix.md` | Fähigkeiten je Zielsystem, für Immoware24 aus der Capability-Registry abgeleitet |
| `docs/mail/04-status-und-sla.md` | Drei Statusdimensionen, Abschlussbedingungen, P0 bis P3, vier Uhren, zwei Ampeln, Arbeitskalender |
| `docs/mail/05-rollen-und-rechte.md` | Permission-Katalog `mail.*`, Mail-Rollen, Vier-Augen, Re-Authentifizierung |
| `docs/mail/06-testplan.md` | 20 Abnahmefälle mit Testklasse und Status |
| `docs/mail/07-gmail-berechtigungen.md` | Minimale OAuth-Scope-Matrix |
| `docs/mail/08-datenschutz-sicherheit.md` | AVV, Datenflüsse, Maskierung, Retention, Logging |
| `docs/mail/09-deployment.md` | DNS, TLS, nginx-Blöcke, Worker, Zeitpläne, Staging und Produktion |
| `docs/mail/10-implementierungsliste.md` | Anforderungen je Abschnitt des Auftrags mit Umsetzungs- und Teststatus |
| `docs/mail/research/` | Rechercheergebnisse zu Gmail, Lexware, OpenAI, Drive (Snippets) |

# Immoware Hub

Integrationsschicht der Hausverwaltung Müller GmbH um den Immoware24-Mandanten. Laravel 13, PHP 8.4, MariaDB 10.11+ (Produktion), SQLite in-memory (Tests), Redis 7 (Queue, Cache, Locks), Scheduler.

Stand: 12.09.2026. Projektphase: Anwendungscode der Phasen 1 bis 8 sowie 10 bis 14 des Implementierungsplans vorhanden und automatisiert getestet, Admin-Oberfläche (17 Bereiche), MCP-Tool-Schicht, Mock-Immoware-Server und Betriebsunterlagen vorhanden. Phase 0 (Zugang und Probe am eigenen Mandanten) nicht begonnen, Phase 9 (Pilotbetrieb) offen. Siehe Abschnitt Status und die Statusspalte in `docs/implementation-plan.md`.

## Status

| Phase | Inhalt | Stand im Code |
|---|---|---|
| 0 | Voraussetzungen, Zugang, Probe am Mandanten | nicht begonnen, WAITING_FOR_VENDOR_ACCESS (DAV-Modul, Supportbestätigung) |
| 1 | Projektgerüst, Core, Auth (2FA TOTP), Rollen, API-Keys, Audit-Hash-Kette | vorhanden, Modul Security |
| 2 | Capability Registry, Probe-Kommando `hub:probe`, Connection-Verwaltung, Rate Limit, Circuit Breaker | vorhanden, Modul Connector |
| 3 | WebDAV-Dokumentenspiegel (lesend), Chunking, Mark-and-Sweep | vorhanden, Modul Documents |
| 4 | CardDAV-Kontaktspiegel (lesend), Duplikatvorschläge | vorhanden, Modul Contacts |
| 5 | CSV-Import Stammdaten, Drop-Ordner, Formatbestätigung | vorhanden, Modul Imports |
| 6 | Konfliktqueue, proposed_change, Datenalter, Export-Erinnerungen | vorhanden, Modul Sync und Imports |
| 7 | Resilienz, DLQ (Admin-UI und `hub:dlq:*`), Locks, Payload-Archiv (PROPFIND-Antworten des Dokumentenspiegels; vCard und iCalendar noch nicht archiviert), Replay `hub:replay`, Metriken, Zeitpläne | vorhanden, Modul Sync |
| 8 | Schreibpfad Posteingang (create-only PUT, Idempotenz, Verify), Flags, BootGuard | vorhanden, Modul Documents und Api, Standard deaktiviert |
| 10, 11, 12 | DATEV-CSV, CAMT.053 (lesend), CalDAV-Terminspiegel | vorhanden (Importer, Parser, Modul Calendar), MT940 nur Stub |
| 13, 14 | Ausgehende HMAC-Webhooks (Outbox), REST-API v1 mit Scopes, OpenAPI, Directory, Health | vorhanden, Module Webhooks und Api, Webhooks standardmäßig deaktiviert |
| 9 | Pilotbetrieb Schreibpfad und Abnahme | offen, setzt Phase 0 und Freigabe der Geschäftsführung voraus |
| Querschnitt | Admin-Oberfläche (Dashboard, Verbindungen, Capabilities, Sync, Mapping, Konflikte, DLQ, Vorschläge, Importe, Webhooks, API-Keys, Benutzer, Rollen, Auditlog, Discovery, Export, System) | vorhanden, Modul Admin, Blade ohne Frontend-Build |
| Querschnitt | MCP-Tool-Katalog (`/api/v1/mcp/tools`, `/api/v1/mcp/call`), n8n-Beispiel-Workflows | vorhanden, Modul Mcp, `docs/mcp/`, `docs/n8n/workflows/` |
| Querschnitt | Mock-Immoware-Server (WebDAV, CardDAV, CalDAV, elf Fehlerszenarien), Contract-Tests | vorhanden, `tests/mock-immoware/`, `tests/Contract/`, `hub:mock-immoware:serve` |
| Querschnitt | Betrieb: Dockerfile, compose.yaml, nginx, supervisord, systemd, Deploy-, Backup- und Restore-Skripte, CI | vorhanden, `deploy/`, `docker/`, `docs/operations/`, nicht gegen einen echten Host geprüft |

**Ungetestet am echten Mandanten (Stand 12.09.2026):** Alle Immoware24-Zugänge (WebDAV, CardDAV, CalDAV, Dateiexporte) sind ausschließlich gegen simulierte DAV-Server (`Http::fake()`, Mock-Server) und Testdateien geprüft. Kein Baustein wurde bisher am echten Immoware24-Mandanten getestet; Phase 0 (Probe) hat nicht begonnen. Der Schreibpfad (create-only PUT in den Posteingang) bleibt gesperrt: `IMMOWARE_WRITE_ENABLED=false`, `IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED=false`, `write_enabled = 0` je Connection, keine Freigabe der Geschäftsführung. Eine Aktivierung vor Abschluss von Phase 0 und der Freigabe nach `docs/immoware/05-write-capabilities.md` Abschnitt 5 ist nicht vorgesehen. Verhalten des DAV-Servers (Auth-Schema, ETag-Stabilität, sync-token, 412 bei If-None-Match) bleibt VERMUTET bis zur Probe in Phase 0. Der Mock-Server unter `tests/mock-immoware/` ist eine Simulation auf Basis belegter Aussagen und ersetzt die Probe nicht. Review 12.09.2026: 85 Findings, 57 bestätigt (5 kritisch, 25 hoch, 27 mittel), Behebung im Fix-Lauf 12.09.2026 (`docs/immoware/10-test-report.md` Abschnitt 7). Stand nach Abschluss des Fix-Laufs 12.09.2026: 554 Tests, 4.223 Assertions, 546 bestanden, 8 übersprungen (Contract-Tests ohne echten DAV-Server, MariaDB-Zweig), Pint und PHPStan (Level 5) ohne Befund. Details in `docs/immoware/10-test-report.md`, Abschnitt 6.

## Entwicklung

Voraussetzungen: PHP 8.4 mit den Erweiterungen pdo_sqlite, pdo_mysql, redis, mbstring, openssl, Composer. Kein Node, kein Frontend-Build. Neue Composer- oder npm-Pakete nur nach Rücksprache (die Entwicklungsumgebung hat keinen Zugriff auf GitHub-Downloads).

Setup:

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan hub:doctor                 Konfiguration, Flags, Queue, DB, Redis, BootGuard, Adapter prüfen
php artisan hub:user:create            Ersten Nutzer anlegen
php artisan hub:api-key:create         API-Key mit Scopes anlegen
```

Wichtige Befehle:

```
php artisan hub:probe {connection}                  Probe einer Connection (OPTIONS, PROPFIND, ETag-Stabilität)
php artisan hub:sync:dispatch {entity} --mode=...   Sync-Jobs einplanen (document, contact, calendar_event, all)
php artisan hub:sync:run {connection} {entity}      Einzelnen Lauf ausführen
php artisan hub:sync:bootstrap {connection} {entity} --stages=1,10,100,1000,alle
php artisan hub:replay {entity} {external_id|--all} --from=payload   Spiegel aus external_payloads neu aufbauen (--dry-run, --latest)
php artisan hub:dlq:list | hub:dlq:retry {id|--all-failed} | hub:dlq:ignore {id} --reason=   Dead Letter Queue
php artisan hub:sync:queue-depth                    Queue-Tiefe je Queue messen, Gauge queue_depth setzen (minütlich im Scheduler)
php artisan mail:push:prune --chunk=1000            Gmail-Push-Ereignisse älter als die Aufbewahrungsfrist entfernen (täglich 04:05)
php artisan mail:retention:apply [--dry-run|--apply] [--organization=]   Aufbewahrungsregeln des Mail-Moduls (Standard Vorschau, Legal Hold schützt)
php artisan mail:subject-access-export {contact} --user= [--format=json|csv] [--sync]   Auskunftsexport DSGVO Art. 15 je Kontakt
php artisan hub:imports:scan --process              Drop-Ordner erfassen und verarbeiten
php artisan hub:imports:remind                      Überfällige manuelle Exporte auflisten
php artisan documents:scan {connection}             WebDAV-Ordner scannen
php artisan hub:openapi:export                      OpenAPI nach docs/api/openapi.json schreiben
php artisan hub:mcp:export                          MCP-Tool-Katalog nach docs/mcp/tools.json schreiben
php artisan hub:mock-immoware:serve                 Mock-Immoware-Server starten (nur local/testing)
php artisan audit:verify, audit:anchor              Audit-Hash-Kette prüfen und verankern
php artisan hub:webhooks:redeliver                  Fehlgeschlagene Zustellungen erneut versuchen
php artisan schedule:list                           Alle Zeitpläne (routes/console.php, Modul-Provider)
```

Testlauf und Qualitätssicherung (vor jedem Commit):

```
php artisan test                                            Gesamte Suite, SQLite in-memory
php artisan test --filter=Documents                         Teilmenge je Modul
cp .env.testing.example .env.testing                        Einmalig, Werte wie phpunit.xml (SQLite in-memory, Cache array, Queue sync)
php artisan migrate:fresh --env=testing                     Migrationen gegen SQLite prüfen
vendor/bin/pint --test <pfad>                               Codestil, nur eigene Pfade
phpstan analyse --no-progress                               Statische Analyse, Level 5
```

Hinweis: `migrate:fresh --env=testing` liest `.env.testing`. Ohne diese Datei greift das Kommando auf die MariaDB-Verbindung der lokalen `.env` zu. `.env.testing` gehört nicht in das Repository (nur `.env.testing.example`). Die Testsuite selbst setzt SQLite in-memory über `phpunit.xml`. Die MariaDB-Integritätstrigger (`docs/architecture/03-mariadb-triggers.sql`) entstehen nur auf MariaDB oder MySQL; auf SQLite gilt allein die Anwendungslogik.

Alle Schreib-Flags (`IMMOWARE_WRITE_*`) stehen standardmäßig auf false. Die hart gesperrten Flags (Overwrite, Delete, Move, CardDAV, CalDAV) lassen die Anwendung beim Start mit Exception abbrechen, wenn sie auf true stehen (BootGuard). Der Upload in den Posteingang über `POST /api/v1/documents` antwortet ohne Freigabe mit 403 `application/problem+json`, Code `write_disabled`.

## Zweck

Immoware24 bleibt führendes System. Der Hub hält einen versionierten, nachvollziehbaren Spiegel der Stammdaten, Kontakte, Dokumente und Buchhaltungsdaten und stellt genau einen eng begrenzten Schreibpfad bereit: neue Dateien per WebDAV in den DMS-Posteingang legen. Alle anderen Schreibrichtungen laufen über einen manuellen Rückweg (proposed_change).

## Oberste Regel

Nichts erfinden. Jede Aussage zu Immoware24-Schnittstellen trägt einen Belegstatus:

| Status | Bedeutung |
|---|---|
| VERIFIZIERT | Offizielle Immoware24-Quelle (immoware24.de, support.immoware24.de, content.immoware24.de, config.dav.immoware24.de), Wortlaut in einem WebSearch-Snippet oder per Fetch tatsächlich gesehen. Ein nahezu wörtlich gesehener Kernsatz zählt, eine sinngemäße Zusammenfassung nicht. |
| DOKUMENTIERT | Quelle mit URL, die die Aussage trägt: offizielle Quelle, deren Wortlaut nur einmal gesehen, in der Gegenprüfung nicht reproduziert oder nur als Snippet-Paraphrase vorliegt, oder glaubwürdige Drittquelle. |
| VERMUTET | Plausibel, aber nicht durch gesichtete Quelle gedeckt, oder Interpretation über den Quellentext hinaus. Vor Nutzung am eigenen Mandanten zu verifizieren. |
| NICHT VERFÜGBAR | Negativbefund: kein Beleg gefunden. Keine offizielle Aussage, dass die Funktion fehlt. Gilt auch für alle Aussagen der Form "ohne API", "nicht dokumentiert", "keine Angabe". |

Diese Definition ist für alle Dokumente des Repositories verbindlich (Leitdefinition in README.md). Abweichende Kurzfassungen in Einzeldokumenten sind durch diese Tabelle ersetzt.

Nur eigener autorisierter Zugang. Keine Umgehung von Authentifizierung oder 2FA. Kein Scraping als Datenbankersatz.

## Belegstand der Zugangswege (Kurzfassung)

| Zugangsweg | Status | Nutzung im Hub |
|---|---|---|
| WebDAV auf DMS (Posteingang, Dokumente) | VERIFIZIERT: Existenz, Netzlaufwerk, Scanner-Upload, Änderungen und Löschungen wirken im Livesystem | Lesen; einziger Schreibkanal, create-only in den Posteingang |
| CardDAV Kontaktfreigabe | VERIFIZIERT für Existenz, Schreibrichtung unklar | Lesen |
| CalDAV Kalenderfreigabe | wie CardDAV | Lesen, niedrige Priorität |
| Konfigurationsportal config.dav.immoware24.de | VERIFIZIERT (Web-UI, Freigaben durch admin, eigenes Freigabe-Passwort) | Manuelle Einrichtung, kein programmatischer Zugriff |
| DAV-Modul | VERIFIZIERT als buchbares Zusatzmodul über Support oder Vertrieb (Wortlaut "buchen", Artikel 360010876038); Kostenpflicht ist Ableitung (VERMUTET), Kosten WAITING_FOR_VENDOR_ACCESS | Blocker vor Phase 0 |
| CSV-Export der Auswertungen | DOKUMENTIERT (Export-Button, manuell), Spaltenformat NICHT VERFÜGBAR | Datei-Import |
| DATEV-CSV-Buchungsexport | DOKUMENTIERT (manuell) | Datei-Import, Phase 3 |
| CAMT.053, MT940, SEPA pain | DOKUMENTIERT (UI und Banking-Client) | CAMT.053 nur lesend |
| REST-API, Webhooks, API-Keys, Zapier/Make/n8n | NICHT VERFÜGBAR (Negativbefund) | Kein Baustein darf darauf bauen |
| Rate Limits, Quotas, SLA | NICHT VERFÜGBAR | Feste konservative Limits |

Vollständige Herleitung mit Quellen in `docs/immoware/` und in der Architekturentscheidung.

## Verzeichnisstruktur

```
.
├── README.md                      Projektübersicht (diese Datei)
├── .env.example                   Platzhalter, keine echten Werte
├── .gitignore
└── docs/
    ├── implementation-plan.md     Phasen 0 bis 14 mit Definition of Done
    ├── adr/
    │   ├── 0001-modularer-monolith.md
    │   ├── 0002-immoware-master.md
    │   └── 0003-dav-first.md
    ├── api/openapi.json           Generiert durch hub:openapi:export
    ├── mcp/                       MCP-Tool-Katalog (README.md, tools.json aus hub:mcp:export)
    ├── n8n/
    │   ├── README.md              Anbindung von n8n an den Hub (Hub-Webhooks, nicht Immoware24)
    │   ├── beispiele.md           Webhook-Payload-Beispiele
    │   └── workflows/             Vier importierbare n8n-Workflows (JSON)
    ├── operations/                Deployment, Backup und Restore, Monitoring, Runbook, Go-live-Checkliste
    └── immoware/                  Schnittstellenrecherche und Testbericht (01 bis 10)
```

Betrieb und Tests:

```
Dockerfile, compose.yaml, .dockerignore   Container-Build und lokaler Verbund (app, web, worker, scheduler, mariadb, redis)
docker/                                   nginx-, php-fpm- und Entrypoint-Konfiguration für die Container
deploy/                                   nginx (TLS), supervisord, systemd, deploy.sh, backup.sh, restore-test.sh
.github/workflows/                        ci.yml (Pint, PHPStan, Tests SQLite und MariaDB), deploy.yml (nur manuell)
public/css/hub.css, public/js/hub.js      Handgeschriebene Admin-Oberfläche, kein Build
tests/mock-immoware/                      PHP-Mock-Server für WebDAV, CardDAV, CalDAV mit Fehlerszenarien
tests/Contract/                           Contract-Tests gegen Mock-Server oder per CONTRACT_DAV_BASE_URL gegen einen DAV-Server
```

Anwendungsstruktur (modularer Monolith, siehe ADR 0001 und CLAUDE.md):

```
app/Core/                 Contracts, DTOs, Enums, BootGuard, Correlation-ID, hub:doctor
app/Modules/
  Security     Users, Rollen, 2FA TOTP, API-Keys, Scopes, Audit (append-only, Hash-Kette)
  Connector    ImmowareConnectorInterface, ConnectorManager, CapabilityRegistry, RateLimitManager, CircuitBreaker, Probe, REST-API-Slot
  Documents    WebDAV-Adapter, Dokumentenspiegel, Posteingang-Upload (einziger Schreibpfad)
  Contacts     CardDAV-Adapter, vCard-Parser, Kontaktspiegel, Duplikatvorschläge
  Calendar     CalDAV-Adapter, iCalendar-Parser, Terminspiegel
  Sync         Jobs, Queues, Retry, DLQ, Locks, Konflikte, proposed_change, Payload-Archiv, Mapping-Versionen, Zeitpläne
  Imports      CSV, DATEV-CSV, CAMT.053, Drop-Ordner, Export-Erinnerungen, file_import-Adapter
  Estate       Spiegeltabellen properties, units, contracts, open_items, transactions
  Api          REST v1, OpenAPI, Directory, Health, RFC-7807-Fehler
  Webhooks     Outbox, HMAC-Signatur, Zustellungen, Redelivery
  Mcp          Tool-Katalog und Permission Layer für Modellzugriffe, Aufrufe laufen als interne Sub-Requests durch die REST-API
  Admin        Blade-Oberfläche: Dashboard und 16 weitere Bereiche, Rechte über die Permission-Map, Bestätigungswort für gefährliche Aktionen
routes/modules/<name>.php, config/hub/<name>.php, resources/views/<name>/, tests/Feature|Unit/<Name>/
```

## Leitprinzipien

1. Immoware24 ist Master, der Hub ist Spiegel.
2. Rohdaten vor Interpretation: jede Nutzlast wird mit SHA-256 archiviert, Mapping ist reproduzierbar.
3. Hash statt Zeitstempel: kein Zugangsweg liefert ein verlässliches updated_at.
4. Schreiben ist die Ausnahme: nur PUT mit If-None-Match: * in den Posteingang. Kein DELETE, MOVE, COPY, PROPPATCH, LOCK, kein Overwrite.
5. Read-only ist Standard: jede Connection startet mit write_enabled = false, Aktivierung im Vier-Augen-Prinzip mit Freigabe der Geschäftsführung.
6. Fähigkeiten werden gemessen (Probe), nicht angenommen.
7. Datenalter ist sichtbar (data_age_seconds, stale_since, source_status).

## Offene Punkte vor Baubeginn (Phase 0)

- Buchung des DAV-Moduls und Kostenfreigabe: WAITING_FOR_VENDOR_ACCESS
- Schriftliche Bestätigung des Immoware24-Supports zur Zulässigkeit automatisierter WebDAV-Nutzung: WAITING_FOR_VENDOR_ACCESS
- Auth-Schema, ETag-Stabilität, sync-token, CTag, If-None-Match-Verhalten des DAV-Servers: zu verifizieren am eigenen Mandanten
- Ordnerumfang per WebDAV und Beschränkbarkeit einer Freigabe auf den Posteingang: zu verifizieren am eigenen Mandanten
- Nutzerrolle, die DAV-Freigaben tragen darf: zu verifizieren am eigenen Mandanten
- Spaltenformate aller CSV-Exporte und der DATEV-Datei: zu verifizieren am eigenen Mandanten
- AGB-Wortlaut zu automatisiertem Zugriff und Exportregelung bei Vertragsende: Prüfung durch Rechtsanwalt

## Hinweise zur Recherche

Der Host www.immoware24.de sowie support.immoware24.de und content.immoware24.de waren aus der Rechercheumgebung gesperrt (EGRESS_BLOCKED). Belege beruhen auf WebSearch-Snippets offizieller Quellen und auf Drittquellen. Snippet-Paraphrasen sind in `docs/immoware/` als solche gekennzeichnet und in Phase 0 gegen das Original abzugleichen.

## Lizenz und Vertraulichkeit

Internes Projekt der Hausverwaltung Müller GmbH. Keine Zugangsdaten, Steuernummern oder Bankverbindungen im Repository.

## Betriebsdomain

Der Hub wird unter `https://immoware.muellerhv.de` betrieben (Vorgabe der Geschäftsführung vom 11.09.2026). Admin-Oberfläche, API (`/api/v1`), API-Dokumentation (`/api/docs`) und Health-Endpunkte laufen unter dieser Domain. DNS, TLS-Zertifikat und Reverse Proxy sind Bestandteil von Phase 1.

## Mail-Modul

Stand 12.09.2026, Phase 0 (Konzept). Interne Mail- und Vorgangsbearbeitung der Hausverwaltung Müller GmbH unter `https://mail.muellerhv.de`, additiv in derselben Anwendung (domaingebundene Routen, eigene Middleware-Gruppe `mail`, eigene Queues `mail-high`, `mail-sync`, `mail-ai`, alle Tabellen mit Präfix `mail_`). Bestehende Funktionen, Routen, Tabellen und Tests bleiben unverändert; der Immoware-Connector wird nur über seine öffentlichen Services genutzt. Gmail ist Mailsystem, Immoware24 Fachsystem, Lexware Office Rechnungsprogramm, Google Drive Dokumentenquelle. Nicht konfigurierte Integrationen erscheinen sichtbar als "Nicht eingerichtet".

Leitregeln: Gelesen ist nicht bearbeitet. Beantwortet ist nicht erledigt. Ein erfolgreicher HTTP-Aufruf ist kein verifiziertes Geschäftsergebnis. Jeder offene Vorgang hat Verantwortlichen, nächsten Schritt und Fälligkeit. Technische Fehler erscheinen nie als Erfolg.

Feature-Flags, alle Standard `false`: `MAIL_IMPORT_ENABLED`, `MAIL_AI_ENABLED`, `MAIL_GMAIL_DRAFTS_ENABLED`, `MAIL_GMAIL_SEND_ENABLED`, `MAIL_IMMOWARE_WRITE_ENABLED`, `MAIL_LEXWARE_WRITE_ENABLED`. Aktivierung einzeln nach Freigabe der Geschäftsführung; Schreib- und Versand-Flags sind in Staging gesperrt.

Stand der Umsetzung: kein Anwendungscode, keine Migrationen, keine Tests des Mail-Moduls vorhanden. Alle Aussagen zu Gmail-, Lexware-, Drive- und OpenAI-APIs stammen aus WebSearch-Snippets (Doku-Hosts gesperrt) und sind vor Implementierung am Original zu prüfen; Live-Tests sind in der Entwicklungsumgebung nicht möglich, ein Mock-Erfolg (`Http::fake`) gilt nie als Live-Test.

| Dokument | Inhalt |
|---|---|
| `docs/mail/00-bestandsaufnahme.md` | Befunde am Repository, Wiederverwendung, Schutzregeln |
| `docs/mail/01-architekturentscheidung.md` | Domain-Routing, Sitzungen, Middleware, Queues, Modulschnitt, Flags, Staging-Schutz |
| `docs/mail/02-datenmodell.md` | Alle `mail_`-Tabellen mit Spalten, Indizes, Fremdschlüsseln |
| `docs/mail/03-capability-matrix.md` | Fähigkeiten je Zielsystem, für Immoware24 aus der Capability-Registry abgeleitet |
| `docs/mail/04-status-und-sla.md` | Drei Statusdimensionen, Abschlussbedingungen, P0 bis P3, vier Uhren, zwei Ampeln, Arbeitskalender |
| `docs/mail/05-rollen-und-rechte.md` | Permission-Katalog `mail.*`, Mail-Rollen, Vier-Augen, Re-Authentifizierung |
| `docs/mail/06-testplan.md` | 20 Abnahmefälle als Testmatrix |
| `docs/mail/07-gmail-berechtigungen.md` | Minimale OAuth-Scope-Matrix |
| `docs/mail/08-datenschutz-sicherheit.md` | AVV, Datenflüsse, Maskierung, Retention, Logging |
| `docs/mail/09-deployment.md` | DNS, TLS, nginx-Block, Staging und Produktion |
| `docs/mail/10-implementierungsliste.md` | Anforderungen je Abschnitt des Auftrags mit Status |
| `docs/mail/research/` | Rechercheergebnisse zu Gmail, Lexware, OpenAI, Drive (Snippets) |

## Betrieb

Stand 12.09.2026. Betriebsunterlagen unter `docs/operations/`, Konfigurationen unter `deploy/`, `docker/`, `Dockerfile`, `compose.yaml`.

| Thema | Datei |
|---|---|
| Deployment (Docker Compose oder Host mit nginx, php-fpm, supervisord oder systemd), CI, manueller Deploy-Workflow, `TRUSTED_PROXIES` | `docs/operations/01-deployment.md` |
| Backup (mariadb-dump, GPG, Retention, Offsite) und Restore, Wiederherstellungstest | `docs/operations/02-backup-restore.md` |
| Health-Endpunkte, Metriken, Alarme (stale, DLQ, 429, Circuit open) | `docs/operations/03-monitoring.md` |
| Runbook Störfälle (401, Passwort-Rotation, Ordnerstruktur, Worker, DLQ, Upload) | `docs/operations/04-runbook.md` |
| Go-live-Checkliste (Phase 0, Testreihenfolge 1/10/100/1000/alle, Freigabe Schreibpfad) | `docs/operations/05-go-live-checkliste.md` |

Kurzfassung Docker:

```
docker compose up -d --build
docker compose run --rm app php artisan migrate --force
docker compose exec app php artisan hub:doctor
```

Prozessrollen: `app` (php-fpm), `web` (nginx, Port 127.0.0.1:8080, TLS terminiert ein vorgelagerter Proxy), `worker` (`queue:work redis --queue=high,default,sync,write,documents,low --max-time=3600 --memory=256`), `scheduler` (`schedule:work`, auch als systemd-Dauerdienst ohne Docker, kein Timer mehr), `mariadb` 11.4, `redis` 7 (appendonly). Migrationen laufen nie automatisch beim Containerstart. Keine Secrets in Repository-Dateien, alle Werte über `.env` oder Secret-Store. GitHub Actions: `ci.yml` (validate, audit, Pint, PHPStan, Tests SQLite und MariaDB), `deploy.yml` nur manuell mit Platzhalter-Secrets.
