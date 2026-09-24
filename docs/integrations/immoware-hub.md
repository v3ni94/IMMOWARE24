# Integrationsdossier Immoware Hub Integrationsplattform

Stand 25.09.2026. Erstellt nach Anhang B des Master-Prompts der MH Verwaltungsplattform (CRM-Repo `v3ni94/CRM-HV-Verwaltungssoftware`). Quelle: Repo `v3ni94/IMMOWARE24`, Branch `claude/vibrant-lovelace-c624qw`. Enthält nur Strukturen, keine personenbezogenen Daten.

Das Mailprogramm liegt im selben Repo und hat ein eigenes Dossier (`mail-optimierung.md`). Dieses Dossier beschreibt nur die Integrationsschicht zu Immoware24 und die Dokumentenanbindung.

## 1. Zweck und Nutzer

Integrationsschicht der Hausverwaltung Müller GmbH um den Immoware24-Mandanten. Immoware24 bleibt führendes System, der Hub ist standardmäßig nur lesend.

- Lesespiegel der Stammdaten aus manuell erzeugten Immoware24-Exporten (CSV, DATEV-CSV, CAMT.053) über einen Drop-Ordner.
- DAV-Adapter (WebDAV, CardDAV, CalDAV) für Dokumente, Kontakte und Termine. Das Immoware24-DAV-Modul ist beim Mandanten nicht gebucht; Stand 24.09.2026 wird dieser Weg nicht genutzt.
- Paperless-ngx (`dms.muellerhv.de`) und Google Drive als Dokumentenquellen.
- REST-API v1, Webhooks mit HMAC, Directory-Endpunkte für andere Programme, MCP-Server.
- Lernphase (Strukturvergleich der Quellen) mit KI-Beratung, Vorschläge nur nach Freigabe.

Nutzer: Geschäftsführung und Sachbearbeitung der HVM, Administratoren. Prozesse: Stammdaten sichten, Dokumente zum Objekt finden, Konflikte zwischen Quellen klären, Daten an andere Programme weitergeben.

## 2. Technik

- PHP 8.4, Laravel 13, modularer Monolith unter `app/Modules/<Name>`, Blade-Oberfläche ohne Frontend-Build.
- MariaDB 11.4 (Produktion), SQLite (Tests), Redis für Queue, Cache und Sperren.
- Betrieb: Docker Compose unter `/opt/immoware-hub` auf dem IONOS-Server `82.165.98.36`, Traefik als Reverse Proxy (Netz `traefik-proxy`). Dienste: `app` (php-fpm), `web` (nginx), `worker`, `scheduler`, `mail-worker`, `mail-worker-high`, `mariadb`, `redis`.
- Domains: `immoware.muellerhv.de` (Hub), `mail.muellerhv.de` (Mailoberfläche).
- Backup: nächtlich 02:00 `deploy/scripts/backup-docker.sh`, zstd und GPG (nur öffentlicher Schlüssel auf dem Server).
- Externe Dienste: Immoware24 (nur Exporte, keine API), Paperless-ngx, Google Drive, Gmail, Lexware, Anthropic und OpenAI.
- Konfigurationsvariablen (Auswahl, ohne Werte): `IMMOWARE_WRITE_ENABLED`, `IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED`, `IMMOWARE_WRITE_WEBDAV_{OVERWRITE,DELETE,MOVE}_ENABLED`, `IMMOWARE_WRITE_{CARDDAV,CALDAV}_ENABLED`, `HUB_IMPORT_DROP_PATH`, `HUB_HASH_PEPPER`, `MAIL_PAPERLESS_PROVIDER`, `MAIL_PAPERLESS_BASE_URL`, `MAIL_PAPERLESS_API_TOKEN`, `MAIL_PAPERLESS_OBJECT_NUMBER_FIELD_ID`, `MAIL_PAPERLESS_COMPANY_FIELD_ID`, `MAIL_PAPERLESS_WRITE_ENABLED`, `AI_PROVIDER`, `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`.

## 3. Datenmodell

Spiegeltabellen (jede externe Entität mit `source_system`, `external_id`, `external_parent_id`, `external_updated_at`, `first_synced_at`, `last_synced_at`, `checksum`, `sync_version`, Löschen nur über `deleted_at`):

| Entität | Tabelle | Identifikation |
| --- | --- | --- |
| Objekt | `properties` | `immoware_object_number` plus `external_id` |
| Gebäude, Einheit | `buildings`, `units` | externe ID, Bezug auf Objekt |
| Kontakt | `contacts`, `contact_identifiers`, `contact_roles`, `contact_merges`, `companies` | externe ID, nie Name oder E-Mail |
| Vertrag | `contracts`, `contract_parties`, `ownerships` | externe ID |
| Bank, Buchhaltung | `bank_accounts`, `transactions`, `open_items`, `invoices` | externe ID |
| Dokumente, Termine | `documents`, `document_folders`, `calendar_events` | DAV-Pfad oder externe ID |

Synchronisation und Nachvollziehbarkeit: `sync_runs`, `sync_states`, `sync_events`, `external_payloads` (Rohdatenarchiv), `external_mappings`, `field_mappings`, `conflicts`, `proposed_changes`, `dlq_items`, `write_operations` (Status-Maschine mit `operation_uuid`, `idempotency_key`, `payload_hash`), `audit_logs` und `audit_anchors` (nur anfügen, Hash-Kette).

Importe: `import_formats` (gelernte Spaltenzuordnung je Exporttyp), `import_files`, `export_schedules`, `hub_exports`.

Paperless: keine eigene Tabelle. Objektzuordnung über das Paperless-Zusatzfeld 7 „MHV Objekt“ (Werte `523` oder `602, Bedburg, Am Fließ 6`), Gesellschaft über Feld 5 (Auswahl HVM, MHAG, TMREV, Sonstige).

## 4. Schnittstellen

- REST-API v1 unter `/api/v1` (40 Routen), OpenAPI unter `/api/docs`, Antwortformat `{"data","meta"}`, Fehler als RFC 7807. Authentifizierung über API-Schlüssel mit Scopes; Stand 24.09.2026 ist die API in Produktion abgeschaltet (`hub.api_keys.enabled=false`).
- Webhooks: Outbox, HMAC-Signatur, Zustellprotokoll (`webhook_endpoints`, `webhook_outbox`, `webhook_deliveries`); in Produktion abgeschaltet.
- Importe: Datei plus Begleitdatei `<datei>.json` (`organization`, `export_type`, `exported_at`, `exported_by`, `is_full_export`, `as_of_date`) im Drop-Ordner, Verarbeitung mit `php artisan hub:imports:scan --process`. Exporttypen unter anderem `properties`, Einheiten, Eigentümer, Mieter und Verträge, offene Posten, DATEV-Buchungsstapel, CAMT.053. Eine Upload-Oberfläche gibt es nicht.
- Paperless-ngx REST: `/api/documents/` mit `query` und `custom_field_query`, `/api/documents/{id}/`, `/api/custom_fields/`, `/api/documents/post_document/` (Upload nur mit `MAIL_PAPERLESS_WRITE_ENABLED`). Token-Authentifizierung.
- Zeitpläne: Scheduler im Container `scheduler`, nächtliches Backup per Cron.
- Anmeldung: eigene Benutzerverwaltung mit TOTP-2FA, kein OIDC.

## 5. Fachlogik, die erhalten bleiben soll

- Schreibsperren gegenüber Immoware24: nur create-only-PUT in den WebDAV-Posteingang, alle anderen Schreibwege hart gesperrt; ein `true` in einem Sperr-Flag lässt die Anwendung beim Start abbrechen (Boot-Guard, `hub:doctor`). Datenbank-Trigger auf `write_operations` verhindert doppelte Uploads.
- Zuordnung ausschließlich über externe IDs, nie über Namen oder E-Mail.
- Paperless-Objektsuche: exakt `<Nummer>` oder Beginn `<Nummer>, `, damit `523` nicht `5230` trifft (`app/Modules/Paperless/Services/PaperlessProvider.php`). Am Server mit Objekt 602 bestätigt.
- Gesellschaftsfilter über Options-IDs des Paperless-Felds 5 (`config/hub/paperless.php`).
- Importformate lernen: unbekannte Spaltenköpfe landen in Quarantäne und werden einmal bestätigt.
- Lernphase: Strukturvergleich der Quellen gegen den letzten Stand, Vorschläge nur nach Freigabe (`app/Modules/Learning`).

## 6. Bekannte Probleme und technische Schulden

- Die Stammdaten sind in Produktion leer; bisher ist kein Import gelaufen.
- Kein Upload im Browser, Import nur über Terminal und Drop-Ordner.
- Eigene Benutzerverwaltung statt zentralem Login.
- Der DAV-Adapter ist gebaut, aber ohne gebuchtes Immoware24-Modul nicht nutzbar.
- Die Paperless-Filtersyntax ist aus der öffentlichen Doku abgeleitet und am Server nur für die Objektsuche geprüft.
- Behobene Fehler vom 24.09.2026: nicht exportierte Backup-Variablen im Cron, falsch-negative GPG-Prüfung, Zombie-Prozesse im Scheduler, leerer `HUB_IMPORT_DROP_PATH`.

## 7. Empfehlung

**Ablösen.** Das CRM hat für die Kernfunktionen des Hubs eigene Module, die zum Zielbild passen:

| Hub-Funktion | Im CRM | Vorgehen |
| --- | --- | --- |
| Immoware24-Import aus Exporten | `mhvp.imports` (M8, Import-Assistent mit Staging, Testlauf, Rückgängig) | CRM nutzen, Hub-Import nicht anbinden |
| Paperless und Drive | Spiegelung in `mhvp.documents` vorgesehen (11.1) | Hub-Logik zur Objektsuche und zum Gesellschaftsfilter übernehmen |
| KI mit Anthropic und OpenAI | `mhvp.ai` (M7) | CRM nutzen |
| Benutzer, 2FA, Rechte | `mhvp.core.auth` mit OIDC und Mandanten | CRM nutzen |
| REST-API, Webhooks | Kern des CRM | CRM nutzen |
| Schreibsperren zu Immoware24 | Parallelbetrieb über führendes Buch (`LeadingSystem.IMMOWARE24`) | Grundsatz übernehmen: Immoware24 bleibt Master, bis der Parallelbetrieb (M9-05) beendet ist |

Den Hub als Datenquelle ins CRM zu hängen, würde denselben Immoware24-Export zweimal verarbeiten und zwei Stände erzeugen. Deshalb nicht anbinden.

Reihenfolge:
1. Mailprogramm ins CRM übernehmen (siehe `mail-optimierung.md`), weil es die meiste eigene Fachlogik enthält.
2. Paperless-Objektsuche und Gesellschaftsfilter in `mhvp.documents` übernehmen.
3. Stammdaten nur noch über den CRM-Import einlesen.
4. Hub abschalten, wenn das CRM die Funktionen im Betrieb abdeckt; bis dahin läuft er weiter.

Aufwand grob, zu verifizieren nach Sichtung der betroffenen CRM-Module: Paperless-Übernahme 2 bis 3 Tage, Abschaltung und Aufräumen 1 Tag. Zu migrieren sind keine Stammdaten, weil der Hub-Spiegel leer ist; übernommen werden nur Konfigurationen (Paperless-Feld-IDs und Gesellschaftsoptionen).

## 8. Offene Fragen an den Betreiber

1. Wird das Immoware24-DAV-Modul später gebucht? Falls nein, entfällt der DAV-Adapter ersatzlos.
2. Nutzen andere Programme bereits die Hub-API oder Webhooks? Stand Produktion: beides abgeschaltet.
3. Soll Paperless im CRM auch beschreibbar sein (Upload mit Objekt- und Gesellschaftsfeld), oder zunächst nur lesend?
4. Stichtag für die Abschaltung des Hubs.

## Zusammenfassung

1. Der Hub ist eine Laravel-Integrationsschicht um Immoware24, lesend, mit Paperless, Drive und KI.
2. In Produktion läuft er stabil, die Stammdaten sind aber noch leer.
3. Das CRM deckt Import, KI, Anmeldung und API bereits selbst ab.
4. Empfehlung: Hub ablösen, nicht anbinden.
5. Übernehmen: Paperless-Objektsuche und Gesellschaftsfilter.
6. Übernehmen als Grundsatz: Immoware24 bleibt Master, Zuordnung nur über externe IDs.
7. Nicht übernehmen: eigene Benutzerverwaltung, eigenen Import, eigene API.
8. Stammdaten müssen nicht migriert werden.
9. Das Mailprogramm im selben Repo ist der größere Übernahmeblock.
10. Der Hub läuft weiter, bis das CRM die Funktionen im Betrieb abdeckt.
