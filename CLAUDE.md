# Immoware Hub, Arbeitsregeln für dieses Repository

Integrationsschicht der Hausverwaltung Müller GmbH um den Immoware24-Mandanten. Laravel 13, PHP 8.4, MariaDB (Produktion), SQLite in-memory (Tests), Redis (Queue, Cache, Locks). Betriebsdomain https://immoware.muellerhv.de. Konzeptdokumente unter `docs/` sind verbindlich, insbesondere `docs/architecture/01-architecture-decision.md`, `docs/architecture/02-data-model.md`, `docs/immoware/05-write-capabilities.md`, `docs/immoware/07-sync-strategy.md`, `docs/immoware/08-security.md`, `docs/immoware/09-api-documentation.md`.

## Unverhandelbare Regeln

1. Nichts erfinden. Es gibt keine Immoware24-REST-API. Belegte Zugangswege sind ausschließlich WebDAV, CardDAV, CalDAV (DAV-Adapter) und manuell erzeugte Dateiexporte (CSV, DATEV-CSV, CAMT.053). Ein Adapter für eine spätere API existiert nur als Slot mit Status `WAITING_FOR_VENDOR_ACCESS` und ohne Endpunkte.
2. Immoware24 ist Master. Der Hub ist standardmäßig read_only. Einziger Schreibpfad: create-only PUT neuer Dateien in den WebDAV-Posteingang, gesteuert über `IMMOWARE_WRITE_ENABLED` und `IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED`. DELETE, MOVE, Overwrite per WebDAV sowie Schreiben per CardDAV oder CalDAV sind hart gesperrt; ein `true` in diesen Flags lässt die Anwendung beim Boot mit Exception abbrechen.
3. Kein Hard Delete auf Spiegeldaten, nur `deleted_at`. Kein `TRUNCATE`, kein `Model::all()` auf großen Tabellen, immer `chunkById`, `lazyById` oder Pagination. Bulk-Upsert über `upsert()`.
4. Jede externe Entität trägt `source_system`, `external_id`, `external_parent_id`, `external_updated_at`, `first_synced_at`, `last_synced_at`, `checksum`, `sync_version`. Zuordnung nur über externe IDs, nie über Namen oder E-Mail.
5. Jede Schreiboperation Richtung Immoware24 läuft über `write_operations` mit `operation_uuid`, `idempotency_key`, `payload_hash`, Status-Maschine. Ein Retry darf nie einen zweiten Upload erzeugen.
6. Secrets nie in Logs, Exceptions, Views, Git. Verschlüsselte Ablage über `encrypted` Casts. `.env` ist nicht im Repository.
7. Auditlog ist append-only. Kein Update, kein Delete auf `audit_logs`.
8. Keine neuen Composer- oder npm-Pakete ohne Rücksprache. Die Entwicklungsumgebung hat keinen Zugriff auf GitHub-Downloads. Alles mit Laravel-Bordmitteln, Guzzle (`Http` Facade), PHP-Standardbibliothek lösen. vCard, iCalendar, WebDAV-XML, TOTP, HMAC, OpenAPI werden selbst implementiert.
9. Kein Frontend-Build. Admin-UI ist Blade mit einer handgeschriebenen CSS-Datei unter `public/css/hub.css`, kein Vite, kein npm.

## Modulstruktur

```
app/
  Core/                       Basisklassen, Contracts, DTOs, Enums, Helper
  Modules/<Name>/
    <Name>ServiceProvider.php Registriert Routen, Config, Views, Commands, Events des Moduls
    Models/  Services/  Jobs/  Http/Controllers/  Http/Requests/  Console/  Events/  Listeners/  Policies/
routes/modules/<name>.php     Nur vom eigenen Modul-Provider geladen
config/hub/<name>.php         Modulkonfiguration
resources/views/<name>/       Blade-Views des Moduls
tests/Feature/<Name>/  tests/Unit/<Name>/
```

Alle Modul-Provider sind in `bootstrap/providers.php` registriert. Ein Modul greift auf andere Module nur über deren Contracts in `app/Core/Contracts` oder deren öffentliche Services zu, nie über deren Controller oder Views.

Module: Security (Users, Rollen, 2FA, API-Keys, Scopes, Audit), Connector (ConnectorInterface, ConnectorManager, CapabilityRegistry, RateLimitManager, CircuitBreaker, Probe, Discovery-Log), Documents (WebDAV-Adapter, Dokumentenspiegel, Posteingang-Upload), Contacts (CardDAV-Adapter, Kontaktspiegel), Calendar (CalDAV-Adapter), Sync (Jobs, Queues, Retry, DLQ, Locks, Konflikte, Payload-Archiv, Mapping-Versionen), Imports (CSV, DATEV, CAMT), Api (REST v1, OpenAPI, Directory, Health), Webhooks (Outbox, HMAC, Deliveries), Admin (Dashboard und alle Admin-Seiten).

## Konventionen

- `declare(strict_types=1);` in jeder PHP-Datei, `final` Klassen wo sinnvoll, Konstruktor-Injektion, keine Facades in Services außer `Log`, `Cache`, `DB`, `Http`.
- Enums als `enum` mit String-Backing. Zeitstempel UTC. Beträge als Integer in Cent (`amount_cents`) plus `currency`.
- Queue-Namen: `high`, `default`, `sync`, `write`, `documents`, `low`. Jobs setzen `$tries`, `backoff()` (30 s, 120 s, 600 s, 1800 s) und `failed()`.
- Structured Logging: `Log::withContext(['correlation_id' => ...])`. Correlation-ID-Middleware setzt `X-Correlation-Id`.
- API-Antworten: `{"data": ..., "meta": {"page", "per_page", "total"}}`, Fehler als RFC 7807 `application/problem+json`.
- Texte in UI und Doku deutsch, ohne Gedankenstriche (Kommas verwenden), Datum TT.MM.JJJJ, Beträge 1.234,56 EUR.
- Tests: PHPUnit, `RefreshDatabase`, SQLite in-memory. HTTP nach außen ausschließlich über `Http` Facade, damit `Http::fake()` greift. Migrationen müssen auf SQLite und MariaDB laufen (keine MariaDB-spezifischen SQL-Strings; JSON-Spalten als `json()`, in SQLite Text).

## Befehle

```
php artisan test                       Tests (SQLite in-memory)
php artisan test --filter=<Name>       Teilmenge
vendor/bin/pint --test <pfad>          Codestil nur für eigene Pfade prüfen, nie das ganze Repo formatieren
phpstan analyse --no-progress          Statische Analyse (phpstan global installiert, Level 5)
php artisan migrate:fresh --env=testing  Migrationen gegen SQLite prüfen
```

Vor jedem Commit: Tests grün, Pint sauber, PHPStan ohne Fehler.
