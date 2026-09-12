# Immoware Hub

Integrationsschicht der Hausverwaltung Müller GmbH um den Immoware24-Mandanten. Laravel 13, PHP 8.4, MariaDB 10.11+ (Produktion), SQLite in-memory (Tests), Redis 7 (Queue, Cache, Locks), Scheduler.

Stand: 12.09.2026. Projektphase: Anwendungscode der Phasen 1 bis 8 des Implementierungsplans vorhanden und automatisiert getestet, Phase 0 (Zugang und Probe am eigenen Mandanten) nicht begonnen. Siehe Abschnitt Status.

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
| 7 | Resilienz, DLQ, Locks, Payload-Archiv, Metriken, Zeitpläne | vorhanden, Modul Sync |
| 8 | Schreibpfad Posteingang (create-only PUT, Idempotenz, Verify), Flags, BootGuard | vorhanden, Modul Documents und Api, Standard deaktiviert |
| 10, 11, 12 | DATEV-CSV, CAMT.053 (lesend), CalDAV-Terminspiegel | vorhanden (Importer, Parser, Modul Calendar), MT940 nur Stub |
| 13, 14 | Ausgehende HMAC-Webhooks (Outbox), REST-API v1 mit Scopes, OpenAPI, Directory, Health | vorhanden, Module Webhooks und Api, Webhooks standardmäßig deaktiviert |

Alle Bausteine sind ausschließlich gegen simulierte DAV-Server (`Http::fake()`) und Testdateien geprüft. Kein Baustein wurde bisher am echten Immoware24-Mandanten getestet. Verhalten des DAV-Servers (Auth-Schema, ETag-Stabilität, sync-token, 412 bei If-None-Match) bleibt VERMUTET bis zur Probe in Phase 0. Die Admin-Oberfläche (Modul Admin) ist noch nicht ausgebaut. Die Zahlen der automatisierten Tests stehen in `docs/immoware/10-test-report.md`, Abschnitt 6.

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
php artisan hub:imports:scan --process              Drop-Ordner erfassen und verarbeiten
php artisan hub:imports:remind                      Überfällige manuelle Exporte auflisten
php artisan documents:scan {connection}             WebDAV-Ordner scannen
php artisan hub:openapi:export                      OpenAPI nach docs/api/openapi.json schreiben
php artisan audit:verify, audit:anchor              Audit-Hash-Kette prüfen und verankern
php artisan hub:webhooks:redeliver                  Fehlgeschlagene Zustellungen erneut versuchen
php artisan schedule:list                           Alle Zeitpläne (routes/console.php, Modul-Provider)
```

Testlauf und Qualitätssicherung (vor jedem Commit):

```
php artisan test                                            Gesamte Suite, SQLite in-memory
php artisan test --filter=Documents                         Teilmenge je Modul
DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan migrate:fresh --env=testing
vendor/bin/pint --test                                      Codestil
phpstan analyse --no-progress                               Statische Analyse, Level 5
```

Hinweis: `migrate:fresh --env=testing` greift ohne die beiden DB-Variablen auf die MariaDB-Verbindung der lokalen `.env` zu. Die Testsuite selbst setzt SQLite in-memory über `phpunit.xml`.

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
    ├── n8n/
    │   ├── README.md              Anbindung von n8n an den Hub (Hub-Webhooks, nicht Immoware24)
    │   └── beispiele.md           Webhook-Payload-Beispiele
    └── immoware/                  Schnittstellenrecherche (01 bis 10)
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
  Admin        Blade-Oberfläche (im Aufbau)
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
