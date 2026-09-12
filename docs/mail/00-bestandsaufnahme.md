# 00 Bestandsaufnahme: Immoware Hub als Basis für das Mail-Modul

Stand: 12.09.2026. Phase 0 (Konzept) des Mail-Moduls für https://mail.muellerhv.de, Hausverwaltung Müller GmbH. Alle Befunde wurden am Repository `/home/user/IMMOWARE24` (Branch `claude/vibrant-lovelace-c624qw`) real erhoben, nicht aus Dokumenten übernommen. Wo ein Befund aus einer Datei stammt, ist die Datei genannt.

## 1. Befunde

### 1.1 Framework, Laufzeit, Datenbank

| Thema | Befund | Quelle |
|---|---|---|
| Framework | Laravel 13 (`laravel/framework ^13.17`), Bootstrap über `Application::configure()` in `bootstrap/app.php` | `composer.json`, `bootstrap/app.php` |
| PHP | 8.4.19 in der Umgebung, `composer.json` verlangt `^8.3`; alle Dateien mit `declare(strict_types=1);` | `php -v`, `composer.json` |
| Datenbank | MariaDB 10.11+ / 11.4 in Produktion (compose), SQLite in-memory in Tests; Migrationen müssen auf beiden laufen, JSON-Spalten als `json()` | `compose.yaml`, `phpunit.xml`, `CLAUDE.md` |
| Cache, Queue, Session | Redis 7 (`CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`); Tests: array, sync, array | `.env.example`, `compose.yaml`, `phpunit.xml` |
| Queues | `high`, `default`, `sync`, `write`, `documents`, `low`; Worker-Kommando `queue:work redis --queue=high,default,sync,write,documents,low` | `config/hub/core.php`, `compose.yaml` |
| Scheduler | `schedule:work` als Dauerdienst, Zeitpläne pro Modul in Providern (`SyncSchedule`, `SecurityServiceProvider`), `withoutOverlapping()->onOneServer()` | `app/Modules/Sync/Schedule/SyncSchedule.php` |
| Frontend | Blade ohne Build, `public/css/hub.css`, `public/js/hub.js`; kein Vite, kein npm | `CLAUDE.md`, `resources/views/layouts/admin.blade.php` |
| Pakete | Keine neuen Composer- oder npm-Pakete möglich (kein Netz); HTTP nur über die `Http`-Facade | `CLAUDE.md` Regel 8 |
| Zeit | Speicherung UTC (`immutable_datetime`), Anzeige über `App\Core\Support\GermanDate` (TT.MM.JJJJ, Europe/Berlin) | `app/Core/Support/GermanDate.php` |

### 1.2 Module und Verdrahtung

Modularer Monolith. Jedes Modul unter `app/Modules/<Name>/` hat einen `ServiceProvider`, der `config/hub/<name>.php` als `hub.<name>` einbindet, `routes/modules/<name>.php` lädt und `resources/views/<name>` als View-Namespace registriert. Alle Provider stehen in `bootstrap/providers.php` (Reihenfolge: App, Security, Connector, Documents, Contacts, Calendar, Estate, Sync, Imports, Api, Webhooks, Mcp, Admin).

Vorhandene Module: Security, Connector, Documents, Contacts, Calendar, Estate, Sync, Imports, Api, Webhooks, Mcp, Admin. Module greifen aufeinander nur über `app/Core/Contracts` oder öffentliche Services zu.

### 1.3 Contracts und Core-Bausteine (app/Core)

| Baustein | Inhalt | Nutzung im Mail-Modul |
|---|---|---|
| `Contracts\AuditLoggerInterface` | `log(action, entity, before, after, source, correlationId)` | Alle Mail-Aktionen auditieren |
| `Contracts\CapabilityRegistryInterface` | `has()`, `all()`, `refresh(connectionId)` | Capability-Matrix je Immoware-Connection ableiten |
| `Contracts\ImmowareConnectorInterface` | `authenticate()`, `testConnection()`, `capabilities()`, `pull()`, `push()` | Nur lesend über Services, nie direkt |
| `Contracts\WebhookDispatcherInterface` | `dispatch(event, payload, organizationId)` | Ausgehende Ereignisse (optional) |
| `Contracts\RateLimiterInterface`, `FieldMapperInterface` | Rate Limit je Schlüssel, Mapping | Rate Limit für Gmail, Lexware, OpenAI |
| `Enums\Role` | owner, administrator, developer, operator, read_only, api_client; `canLogin()`, `canRequestWriteEnable()`, `canConfirmWriteEnable()` | Bestehende Rollen bleiben, Mail-Rollen als Permission-Sets (05) |
| `Enums\CapabilityStatus` | verified, documented, tested, assumed, unavailable, waiting_for_vendor_access; `allowsActivation()` | Statusmodell der Capability-Matrix (03) |
| `Enums\AuditSource` | immoware_sync, api, user, system, n8n, mcp, import, webhook | Erweiterung um `mail`, `gmail_push`, `ai` nötig (additiv) |
| `Traits\BelongsToOrganization` | Global Scope über `OrganizationContext`, automatische `organization_id` | Alle `mail_*`-Tabellen mandantengebunden |
| `Traits\HasExternalIdentity` | `source_system`, `external_id`, `external_id_hash`, Checksumme, `sync_version`, SoftDeletes | Für Gmail-Spiegel (`mail_messages`) sinngemäß, `source_system = gmail` |
| `Support\OrganizationContext`, `CorrelationId`, `SecretMasker`, `HashedIdentifier`, `GermanDate`, `Money`, `UrlGuard` | Querschnitt | Direkt wiederverwenden |
| `Boot\BootGuard` | Bricht Boot ab, wenn hart gesperrte Immoware-Schreibflags true sind | Muster für Staging-Schutz der Mail-Flags (01) |
| `Database\BlueprintMacros::externalIdentity()` | Herkunftsblock in Migrationen | Für `mail_messages`, `mail_document_references` |

### 1.4 Security (Auth, Rollen, 2FA, Permission-Map)

| Befund | Quelle |
|---|---|
| `User` (Tabelle `users`): `organization_id`, `role` (Enum), `totp_secret` (encrypted), `totp_confirmed_at`, `recovery_codes`, `locked_until`, `failed_login_count`, `disabled_at` | `app/Modules/Security/Models/User.php`, Migration `0001_01_01_000000` |
| Login mit Sperre nach 5 Fehlversuchen (15 min), neue-IP-Benachrichtigung, Passwort mindestens 12 Zeichen | `config/hub/security.php` |
| 2FA TOTP selbst implementiert (`Totp`, `Base32`, `RecoveryCodes`); Middleware `2fa` (bestätigte 2FA in Sitzung) und `2fa.fresh` (Re-Authentifizierung höchstens 15 min alt) | `SecurityServiceProvider`, `RequireTwoFactor`, `RequireFreshTwoFactor` |
| Session: Cookie Secure, SameSite strict, 30 min inaktiv, 8 h absolut (`EnforceAbsoluteSessionLifetime` in Gruppe `web`); `SESSION_DOMAIN` leer = Host des Requests, kein Wildcard | `.env.example`, `config/session.php` Zeile 160 |
| Permission-Map: `config('hub.security.permission_catalog')` plus `permissions` je Rolle; `PermissionMap::allows()`; jedes Recht wird als Gate registriert; `Gate::before` sperrt deaktivierte oder gesperrte Nutzer | `PermissionMap.php`, `SecurityServiceProvider::registerGates()` |
| Bestehende Rechte: connections.manage, sync.run, writes.request, writes.approve, api_keys.manage, users.manage, audit.view, exports.run, imports.run, conflicts.resolve, webhooks.manage, records.view, payloads.view | `config/hub/security.php` |
| API-Keys mit Scopes (`cases:read`, `cases:write`, ...), Middleware `auth.apikey`, `scope`, `throttle.apikey` | `config/hub/security.php`, Middleware |
| Audit: `audit_logs` append-only mit Hash-Kette (`prev_hash`, `row_hash`), Update/Delete werfen `LogicException`, Kette per Cache-Lock serialisiert, tägliche Prüfung und Verankerung (`audit:verify`, `audit:anchor`) | `AuditLog.php`, `AuditLogger.php` |
| Vier-Augen-Prinzip existiert für den Immoware-Schreibpfad: Administrator beantragt, Owner bestätigt, `write_approval_document_id` Pflicht, gleiche Person unzulässig | `ImmowareConnection::writeApprovalIncompleteReason()` |

### 1.5 Connector (ConnectorManager, CapabilityRegistry, Verbindungen)

| Befund | Quelle |
|---|---|
| Adaptertypen: webdav, carddav, caldav, file_import, rest_api_slot (WAITING_FOR_VENDOR_ACCESS, keine Endpunkte) | `Enums\ConnectorType` |
| `ConnectorManager::resolve(ImmowareConnection)` liefert den Adapter; Zugangsdaten nur im Speicher (`ConnectorContext`) | `Services\ConnectorManager` |
| `CapabilityRegistry`: Fähigkeit verfügbar nur bei Status verified oder tested UND Config-Flag UND nicht hard_locked. `all()` liefert je Schlüssel `available`, `status`, `enabled`, `hard_locked`, `config_allowed`, `tested_at` | `Services\CapabilityRegistry` |
| Capability-Schlüssel: contacts.read, contacts.write (hard_locked), calendar.read, calendar.write (hard_locked), documents.read, documents.write (create-only PUT, zwei Flags), documents.overwrite/delete/move (hard_locked), properties.read, units.read, contracts.read, finance.read, cases.read, cases.write (config_flag null, damit gesperrt) | `config/hub/connector.php` |
| `immoware_connections`: `organization_id`, `connector_type`, `base_url` (encrypted), `credentials` (encrypted:array), `purpose` read/write, `status`, `write_enabled`, `write_enabled_by`, `write_confirmed_by`, `write_approval_document_id` | Migration `2026_09_12_000101` |
| Rate Limit (`RateLimitManager`, Token-Bucket, Drosselung nach 429/5xx/Latenz), Circuit Breaker (5 Fehler in 120 s, 600 s offen), `RemoteRequestLogger` (jeder externe Aufruf protokolliert, Secrets maskiert) | `config/hub/connector.php`, Services |
| Egress-Allowlist für Connection-Hosts (`HUB_CONNECTION_ALLOWED_HOSTS`, Standard `*.immoware24.de`); private Adressen abgelehnt | `config/hub/connector.php`, `UrlGuard` |

### 1.6 Kontakt- und Objektspiegel (Contacts, Estate)

| Befund | Quelle |
|---|---|
| `contacts`: kind, salutation, first_name, last_name, company_id, `emails`/`phones`/`addresses` (JSON), vcard_uid, similarity_hash, merged_into_id, personal_data_erased_at, Herkunftsblock | Migration `2026_09_12_000104` |
| `ContactMirrorService::upsert()`, `sweep()`, `markMissingByHref()`; Zuordnung nur über `external_id`, nie über E-Mail (CLAUDE.md Regel 4) | `Services\ContactMirrorService` |
| `properties` (immoware_object_number, name, management_type, Adresse), `buildings`, `units` (unit_number, unit_type, floor), `contracts` (unit_id, contract_number, start/end, Beträge in Cent), `contract_parties`, `ownerships`, `bank_accounts` | Migrationen `000103`, `000105` |
| Tabelle `cases` existiert bereits (Modell `Estate\Models\CaseFile`: property_id, unit_id, contact_id, title, status, immoware_ticket_reference). Namenskonflikt mit dem Mail-Vorgang wird durch das Präfix `mail_` vermieden; `cases` bleibt unangetastet | Migration `000106`, `Estate\Models\CaseFile` |
| Duplikatvorschläge (`ContactDuplicateDetector`), Kontaktrollen (`ContactRole`, `ContactRoleType`) | Modul Contacts |

### 1.7 Sync-Engine (Jobs, Locks, DLQ, Konflikte, proposed_change)

| Befund | Quelle |
|---|---|
| `RunSyncJob` als Orchestrator mit Cursor-Chunks, `WithoutOverlapping`, Selbst-Neueinplanung, Lock je Connection und Entität (`SyncLockManager`, Owner-Token aus Job-UUID, Heartbeat-Verlängerung) | `Jobs\RunSyncJob`, `Support\SyncLockManager` |
| Retry-Trait `SyncJobRetries`: `tries = 0`, `maxExceptions = 5`, Backoff 30 s, 120 s, 600 s, 1800 s mit Jitter, Timeout 900 s | `Jobs\Concerns\SyncJobRetries`, `Support\SyncBackoff` |
| DLQ: `DlqService::store()` mit maskiertem Payload, retry, ignore; Tabelle `dlq_items` | `Services\DlqService` |
| `sync_states` (Cursor je Connection und Entität), `sync_runs`, `sync_events`, `external_payloads` (Rohnutzlast mit SHA-256), `external_mappings`, `field_mappings` (versioniert) | Migration `000109` |
| Konflikte (`conflicts`, `ConflictService`), Änderungsvorschläge (`proposed_changes`, `ProposedChangeService::propose/markTransferred/confirm/reject`) als manueller Rückweg Richtung Immoware24 | Modul Sync |
| `write_operations` mit `operation_uuid`, `idempotency_key`, `payload_hash`, Status-Maschine (create-only PUT) | Migration `000109`, `Documents\Services\PosteingangUploadService` |
| Metriken (`SyncMetrics`), Datenalter (`DataAgeService`, `stale_since`), Worker-Heartbeat | Modul Sync |

### 1.8 Webhooks, API, MCP

| Befund | Quelle |
|---|---|
| Outbox-Muster (`webhook_outbox`, `webhook_deliveries`), HMAC-Signatur, Redelivery; Ereigniskatalog in `config/hub/webhooks.php` (u. a. `case.created`, `case.updated` für Hub-Vorgänge) | Modul Webhooks |
| REST v1 mit Scopes, RFC 7807, OpenAPI-Export, Idempotency-Keys (`idempotency_keys`) | Modul Api |
| MCP-Tool-Katalog als interne Sub-Requests der REST-API | Modul Mcp |

### 1.9 Admin-Oberfläche

| Befund | Quelle |
|---|---|
| Middleware-Gruppe `admin` = `web, auth, admin.access, 2fa`; `EnsureAdminAccess` setzt `OrganizationContext` aus dem Nutzer, sperrt api_client und gesperrte Konten | `AdminServiceProvider`, `EnsureAdminAccess` |
| Routen-Prefix `/admin`, Namen `admin.*`; sicherheitskritische Aktionen mit `2fa.fresh` | `routes/modules/admin.php` |
| Layout `layouts.admin` mit View-Composer (Navigation aus `config/hub/admin.php`, Umgebungsanzeige, Produktname); Komponenten `x-admin.*` in `resources/views/components/admin` | `AdminServiceProvider::registerViewComposers()` |
| 17 Bereiche, Bestätigungswort für gefährliche Aktionen | `config/hub/admin.php` |

### 1.10 Tests und Qualität

| Befund | Quelle |
|---|---|
| Testlauf 12.09.2026 in dieser Umgebung: 554 Tests, 546 bestanden, 8 übersprungen (Contract-Tests ohne DAV-Server, MariaDB-Zweig), 4.223 Assertions, Laufzeit 18 s | `php artisan test` |
| 106 Testklassen unter `tests/Feature/<Modul>`, `tests/Unit/<Modul>`, `tests/Contract`, Mock-Immoware-Server unter `tests/mock-immoware` | Verzeichnis `tests/` |
| PHPUnit mit `RefreshDatabase`, SQLite in-memory, `Http::fake()` für alle externen Aufrufe; Pint für eigene Pfade, PHPStan Level 5 | `phpunit.xml`, `CLAUDE.md` |

### 1.11 Deployment und Domains

| Befund | Quelle |
|---|---|
| `compose.yaml`: Dienste app (php-fpm), web (nginx 1.27, Port 127.0.0.1:8080, TLS terminiert davor), worker, scheduler, mariadb 11.4, redis 7; `APP_URL` Standard `https://immoware.muellerhv.de` | `compose.yaml` |
| nginx-Host-Konfiguration `deploy/nginx/immoware.muellerhv.de.conf` (HTTP zu HTTPS, TLS 1.2/1.3, HSTS, Security-Header, `client_max_body_size 34m`, php-fpm Socket); Container-Variante `docker/nginx/immoware.muellerhv.de.conf` | `deploy/nginx/`, `docker/nginx/` |
| Supervisor- und systemd-Units für Worker und Scheduler, Deploy-, Backup-, Restore-Skripte | `deploy/supervisor`, `deploy/systemd`, `deploy/scripts` |
| `TRUSTED_PROXIES` in `bootstrap/app.php`, nie `*` | `bootstrap/app.php` |
| Betriebsdomain bisher ausschließlich `immoware.muellerhv.de`; keine Domain-Logik in Routen (`Route::domain` nirgends verwendet) | `routes/`, README |
| CI: `.github/workflows/ci.yml` (Pint, PHPStan, Tests SQLite und MariaDB), `deploy.yml` manuell | `.github/workflows/` |

### 1.12 Vorarbeiten zum Mail-Modul im Repository

Unter `docs/mail/research/` liegen zwei Rechercheberichte (Stand 12.09.2026), beide ausschließlich aus WebSearch-Snippets, da die Doku-Hosts gesperrt sind:

- `gmail-api.md`: Scopes, users.watch, Pub/Sub-Push, history.list, Drafts, Formate, sendAs, Quota, Workspace-Verifizierung, offene Punkte.
- `lexware-openai-drive.md`: Lexware Office Public API (Basis-URL, Bearer-Key, 2 rps, Contacts, `version`-PUT, Fehlercodes), OpenAI (Responses API, Structured Outputs, Datenschutz), Google Drive v3 (files.list, Scopes, permissions, export, changes).

Beide Dokumente gelten als "aus Snippets, vor Implementierung am Original zu prüfen" und sind Grundlage der Capability-Matrix (03) und der Berechtigungsmatrix (07).

## 2. Was wiederverwendet wird

| Bedarf des Mail-Moduls | Wiederverwendung (ohne Duplikat) |
|---|---|
| Nutzer, Anmeldung, 2FA, Sitzungen, Re-Authentifizierung | Modul Security unverändert: `User`, Middleware `auth`, `2fa`, `2fa.fresh`, `EnforceAbsoluteSessionLifetime` |
| Rechte | `PermissionMap` mit zusätzlichem Katalog `mail.*` (additiv in `config/hub/security.php` oder per Merge aus `config/hub/mail.php`, siehe 05) |
| Audit | `AuditLoggerInterface`, `audit_logs`; neue `AuditSource`-Fälle additiv |
| Mandant | `BelongsToOrganization`, `OrganizationContext`, `organizations` |
| Immoware-Daten | Nur lesend über `Contact`, `Property`, `Unit`, `Contract`, `ContactMirrorService`, `CapabilityRegistry`, `ProposedChangeService`; kein zweiter DAV-Client |
| Schreibweg Immoware Posteingang | `PosteingangUploadService::submit()` (create-only PUT über `write_operations`), nur wenn `MAIL_IMMOWARE_WRITE_ENABLED` und die bestehende Freigabe vorliegen |
| Manuelle Änderungen in Immoware24 (Adresse, Bankdaten) | `ProposedChangeService::propose()` als Alt/Neu-Vorschlag, Abschluss über `markTransferred()` und Verifikation |
| Externe HTTP-Aufrufe | `Http`-Facade, `RateLimitManager`, `CircuitBreaker`, `RemoteRequestLogger`, `UrlGuard`, `SecretMasker` |
| Jobs | Muster `SyncJobRetries` (Backoff-Reihe, `maxExceptions`, `failed()` mit `DlqService::store()`), `SyncLockManager`-Muster für Postfach-Locks |
| Outbox | Muster `WebhookDispatcher` (Ereignis in derselben Transaktion, Job nach Commit) für `mail_outbox` |
| Oberfläche | Layout `layouts.admin`, `hub.css`, `hub.js`, Komponenten `x-admin.*`; Mail-Views unter `resources/views/mail` mit eigenem Layout, das dieselben Assets nutzt |
| Betrieb | compose, Worker, Scheduler, Backup; zusätzlicher nginx-Server-Block und zusätzliche Queues |

## 3. Schutzregeln für die Erweiterung

1. Additiv: keine Änderung an bestehenden Tabellen außer neuen nullable Spalten; keine Umbenennung, kein Drop, keine Datenmigration bestehender Zeilen. Alle neuen Tabellen tragen das Präfix `mail_`.
2. Bestehende Routen (`/admin`, `/api/v1`, `/login`, `/security`, `/up`) bleiben unter `immoware.muellerhv.de` unverändert. Mail-Routen hängen an `Route::domain('mail.muellerhv.de')` (01).
3. Der Immoware-Connector wird nur über seine öffentlichen Services genutzt (`ConnectorManager`, `CapabilityRegistry`, `ContactMirrorService`, `PosteingangUploadService`, `ProposedChangeService`). Kein zweiter WebDAV-, CardDAV- oder CalDAV-Client, keine Kopie von Mapping-Logik.
4. CLAUDE.md Regeln 1 bis 9 gelten uneingeschränkt: Immoware24 bleibt Master, kein Hard Delete, `audit_logs` append-only, keine Secrets in Logs, keine neuen Pakete, kein Frontend-Build.
5. Alle 554 bestehenden Tests bleiben grün; neue Tests unter `tests/Feature/Mail*`, `tests/Unit/Mail*`. Kein bestehender Test wird geändert, um ein neues Verhalten zu erzwingen.
6. Feature-Flags des Mail-Moduls stehen standardmäßig auf false. Ohne Flag und ohne Zugangsdaten zeigt die Oberfläche "Nicht eingerichtet", nie einen Mock-Erfolg.
7. Ein Mock-Test (`Http::fake`) ist eine Vertragsannahme gegen ein aus Snippets abgeleitetes Antwortformat, kein Live-Test. Dies wird in Testnamen, Doku und Oberfläche so benannt.
8. Kein Versand, keine Änderung in Fremdsystemen ohne menschliche Freigabe und ohne nachgelagerte Verifikation (ein 2xx ist kein Geschäftsergebnis).

## 4. Offene Fragen an die Geschäftsführung (vor Phase 1)

- Bestätigung, dass `mail.muellerhv.de` auf denselben Host wie `immoware.muellerhv.de` zeigt (gleiche Anwendung, gleiche Datenbank), siehe 01 und 09.
- Google Cloud Projekt in der Workspace-Organisation muellerhv.de anlegen (Consent Screen intern), Pub/Sub-Topic, Dienstkonto: wer richtet ein, wer verwaltet?
- Lexware Office API-Key: welche Organisation, welcher Nutzer, Aufbewahrungsort?
- OpenAI: Auftragsverarbeitungsvertrag, EU-Projekt, Zero Data Retention; bis zur Klärung bleibt `MAIL_AI_ENABLED=false`.
- Arbeitszeiten und Feiertagsliste (Startvorschlag in 04) bestätigen.
