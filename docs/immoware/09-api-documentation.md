# 09 Hub-REST-API v1, Entwurf

Stand: 11.09.2026
Bezug: Architekturentscheidung Abschnitte 2, 5, 6, 7 und 9 (Phase 4); Datenmodell; 08-security.md (Rollen, Scopes, API-Keys).

Status dieses Dokuments: Entwurf. Die API ist die Schnittstelle des Immoware Hub für Fremdsysteme und den späteren KI/MCP-Layer. Sie ist keine Immoware24-API. Immoware24 stellt nach aktuellem Recherchestand keine öffentliche REST-API, keine Webhooks und keine API-Keys bereit (NICHT VERFÜGBAR, Negativbefund vom 11.09.2026, keine offizielle Aussage). Jede Ressource dieser API liefert deshalb Spiegeldaten mit Datenalter und Belegstatus der Quelle, nie Live-Daten aus Immoware24.

Zeitplan: Die Endpunkte unter /api/v1 werden in Phase 1 bis 3 nur intern von der Hub-UI genutzt (Session-Auth). Externe API-Keys und Webhooks werden erst in Phase 4 und nur für benannte Konsumenten freigeschaltet (Architekturentscheidung Abschnitt 9).

## 0. Grundsätze

1. Lesend zuerst. Es gibt genau einen schreibenden Endpunkt mit Wirkung auf Immoware24: POST /api/v1/documents/uploads (Upload-Antrag in den Posteingang). Alle anderen schreibenden Endpunkte ändern nur Hub-eigene Daten (Vorgänge, Konflikte, Änderungsvorschläge).
2. Keine Endpunkte für Update oder Delete von Spiegeldaten. Wer etwas in Immoware24 ändern will, legt einen Änderungsvorschlag an (proposed_change), der von einem Menschen in Immoware24 umgesetzt wird.
3. Jede Antwort trägt Herkunft und Datenalter (meta.source).
4. Stabile Hub-IDs (UUIDv7). Externe Schlüssel werden nur als Zusatzinformation ausgegeben.
5. Versionierung im Pfad (/api/v1). Abwärtskompatible Erweiterungen (neue Felder) ohne Versionswechsel; entfernte oder umbenannte Felder nur mit /api/v2 und Übergangsfrist von 6 Monaten.

## 1. Basis

| Punkt | Festlegung |
|---|---|
| Basis-URL | https://immoware.muellerhv.de/api/v1 (Vorgabe Geschäftsführung 11.09.2026) |
| Transport | nur HTTPS, TLS 1.2 und höher, HSTS |
| Authentifizierung | Header Authorization: Bearer hub_live_<prefix>_<secret> (API-Key, 08-security.md Abschnitt 3.2) oder Session-Cookie der Hub-UI mit CSRF-Token |
| Autorisierung | Scopes je Key, Rolle je Nutzer (08-security.md Abschnitte 4 und 5) |
| Format | JSON, UTF-8, Content-Type application/json; Fehler als application/problem+json |
| Zeit | ISO 8601 mit Zeitzone, UTC, Millisekunden, Beispiel 2026-09-11T08:15:30.000Z |
| Beträge | Ganzzahl in Cent plus currency, Beispiel amount_cents 123456, currency EUR |
| IDs | UUIDv7 als String |
| Idempotenz | Header Idempotency-Key (UUID) Pflicht für alle POST mit Wirkung; Wiederholung liefert dieselbe Antwort mit Header Idempotent-Replayed: true |
| Request-ID | Header X-Request-Id wird gespiegelt, sonst erzeugt; erscheint im Audit und im Fehlerobjekt |
| Rate Limit | 600 Requests pro Minute lesend, 60 pro Minute für Uploads je Key; Header RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset; bei Überschreitung 429 mit Retry-After |
| Sprache | Fehlermeldungen Deutsch, Feldnamen Englisch |

## 2. Antwortformat

### 2.1 Einzelressource

```json
{
  "data": { "...": "..." },
  "meta": {
    "request_id": "6f1c0a2e-...",
    "source": {
      "system": "immoware24",
      "connection_id": "018f...",
      "access_path": "carddav",
      "evidence_status": "VERIFIZIERT",
      "source_status": "fresh",
      "data_age_seconds": 1740,
      "last_synced_at": "2026-09-11T07:46:30.000Z",
      "stale_since": null,
      "sync_version": 3,
      "checksum": "sha256:9a1f..."
    }
  }
}
```

Feld source_status: fresh (Health-Check ok, Sync innerhalb des Sollintervalls), stale (Health-Check fehlgeschlagen oder Export überfällig, stale_since gesetzt), degraded (Connection im Status degraded, Fingerprint geändert). evidence_status ist der Belegstatus des Zugangswegs laut Capability Registry.

### 2.2 Listen und Pagination

Cursor-basiert, keine Offsets (stabil bei laufendem Sync, performant bei Millionen Zeilen).

```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "request_id": "...",
    "page": {
      "limit": 100,
      "next_cursor": "eyJpZCI6IjAxOGYuLi4ifQ",
      "prev_cursor": null,
      "has_more": true
    },
    "source": { "...": "wie 2.1, aggregiert: source_status ist der schlechteste Wert der Seite" }
  }
}
```

Parameter: limit (Default 100, Maximum 1000), cursor (opaque, Base64url-JSON aus Sortierschlüssel und id). Sortierung über sort=field oder sort=-field, erlaubte Felder je Endpunkt dokumentiert. Cursor sind an sort und Filter gebunden; ein Cursor mit anderen Filtern liefert 400 invalid_cursor.

### 2.3 Filter

Query-Parameter im Schema filter[feld]=wert bzw. filter[feld][op]=wert mit op aus eq, ne, gt, gte, lt, lte, in (kommagetrennt), like (Prefix-Suche, mindestens 3 Zeichen), null (true oder false).

Globale Filter auf allen Spiegelressourcen:
- filter[updated_since]=ISO-Zeit (Änderung im Hub, basiert auf sync_events, nicht auf einem Immoware24-updated_at, das es nicht gibt)
- filter[include_deleted]=true (Standard false, Soft-Deleted werden ausgeblendet)
- filter[source_status]=fresh|stale|degraded
- filter[identity_confidence]=exact|derived|uncertain
- filter[connection_id]=uuid

Feldauswahl: fields=id,name,unit_number (Sparse Fieldsets). Einbettung: include=property,units (nur je Endpunkt erlaubte Relationen, maximal eine Ebene).

### 2.4 Fehlerformat

application/problem+json nach RFC 9457 mit Erweiterungen.

```json
{
  "type": "https://immoware.muellerhv.de/errors/validation_failed",
  "title": "Validierung fehlgeschlagen",
  "status": 422,
  "detail": "Das Feld filename darf keine Pfadtrenner enthalten.",
  "instance": "/api/v1/documents/uploads",
  "code": "validation_failed",
  "request_id": "6f1c0a2e-...",
  "errors": [
    { "field": "filename", "code": "invalid_characters", "message": "Pfadtrenner sind nicht erlaubt." }
  ]
}
```

| HTTP | code | Bedeutung |
|---|---|---|
| 400 | bad_request, invalid_cursor, invalid_filter | Syntaxfehler in Parametern |
| 401 | unauthenticated, key_expired, key_revoked | Kein oder ungültiger Key bzw. Session |
| 403 | insufficient_scope, role_forbidden, ip_not_allowed, write_disabled, connection_degraded, capability_locked | Berechtigung fehlt oder Schreibpfad gesperrt; write_disabled und connection_degraded enthalten im Feld detail den Grund ohne interne Details |
| 404 | not_found | Ressource existiert nicht oder ist für den Aufrufer nicht sichtbar |
| 409 | conflict, write_target_exists, idempotency_mismatch | Zielpfad existiert bereits; Idempotency-Key mit abweichendem Body wiederverwendet |
| 410 | gone | Version oder Payload pseudonymisiert |
| 413 | payload_too_large | Upload über max_upload_bytes der Connection |
| 415 | unsupported_media_type | |
| 422 | validation_failed | Feldfehler im Array errors |
| 423 | locked | Ressource in Konfliktbearbeitung durch andere Person |
| 429 | rate_limited | Retry-After gesetzt |
| 500 | internal_error | request_id für den Support |
| 503 | service_unavailable, source_unavailable | Hub im Wartungsmodus bzw. Connection nicht erreichbar bei on-demand Download |

Fehlermeldungen enthalten nie Zugangsdaten, Pfade des Dateisystems oder Stacktraces.

## 3. Endpunkte

Legende Spalte Scope: benötigter Scope laut 08-security.md. Spalte Wirkung: hub (nur Hub-Daten) oder immoware24 (Wirkung auf das Livesystem).

### 3.1 Directory und Meta

| Methode | Pfad | Scope | Beschreibung |
|---|---|---|---|
| GET | /api/v1 | keiner (Key gültig) | Directory: Liste aller Ressourcen mit URL, erlaubten Methoden, benötigten Scopes und Belegstatus des zugrunde liegenden Zugangswegs; Link auf /api/docs |
| GET | /api/v1/me | keiner | Aktueller Aufrufer: Typ (user, api_key), Rolle, Scopes, Ablauf, Organisation |
| GET | /api/v1/capabilities | sync:read | Capability Registry: capability_key, evidence_status, source_url, enabled, hard_locked, tested_at. Fähigkeiten unter DOKUMENTIERT werden nie als verfügbar gemeldet |

Directory-Antwort (gekürzt):

```json
{
  "data": {
    "version": "v1",
    "docs_url": "https://immoware.muellerhv.de/api/docs",
    "openapi_url": "https://immoware.muellerhv.de/api/docs/openapi.json",
    "resources": [
      { "name": "properties", "href": "/api/v1/properties", "methods": ["GET"], "scopes": ["properties:read"], "source": { "access_path": "csv_export", "evidence_status": "DOKUMENTIERT" } },
      { "name": "contacts", "href": "/api/v1/contacts", "methods": ["GET"], "scopes": ["contacts:read"], "source": { "access_path": "carddav", "evidence_status": "VERIFIZIERT" } },
      { "name": "documents", "href": "/api/v1/documents", "methods": ["GET"], "scopes": ["documents:read"], "source": { "access_path": "webdav", "evidence_status": "VERIFIZIERT" } },
      { "name": "document_uploads", "href": "/api/v1/documents/uploads", "methods": ["GET", "POST"], "scopes": ["documents:create"], "source": { "access_path": "webdav", "evidence_status": "VERIFIZIERT", "write_enabled": false } },
      { "name": "proposals", "href": "/api/v1/proposals", "methods": ["GET", "POST"], "scopes": ["proposals:create"], "source": { "access_path": "manual", "evidence_status": null } }
    ]
  }
}
```

### 3.2 Stammdaten (lesend, Quelle CSV-Export, Spaltenformat NICHT VERFÜGBAR bis Phase 0)

| Methode | Pfad | Scope | Filter, Sort | include |
|---|---|---|---|---|
| GET | /api/v1/properties | properties:read | management_type, immoware_object_number, postal_code, city, name (like); sort name, immoware_object_number | buildings |
| GET | /api/v1/properties/{id} | properties:read | | buildings, units |
| GET | /api/v1/properties/{id}/units | units:read | wie units | |
| GET | /api/v1/properties/{id}/documents | documents:read | wie documents | |
| GET | /api/v1/properties/{id}/open-items | finance:read | as_of_date, kind | |
| GET | /api/v1/units | units:read | property_id, unit_type, unit_number, floor; sort unit_number | property, building, current_contract, current_owners |
| GET | /api/v1/units/{id} | units:read | | property, contracts, ownerships, documents |
| GET | /api/v1/contracts | contracts:read | unit_id, property_id, start_date, end_date, active (true: end_date null oder in der Zukunft) | unit, parties |
| GET | /api/v1/contracts/{id} | contracts:read | | |
| GET | /api/v1/ownerships | ownerships:read | unit_id, contact_id, valid_from, valid_to | |

Felder mit identity_confidence = uncertain tragen im Datensatz das Flag identity_uncertain: true. Konsumenten dürfen solche Datensätze nicht als eindeutig zugeordnet behandeln.

### 3.3 Kontakte (lesend, Quelle CardDAV VERIFIZIERT und CSV DOKUMENTIERT)

| Methode | Pfad | Scope | Beschreibung |
|---|---|---|---|
| GET | /api/v1/contacts | contacts:read | Filter kind, last_name (like), email (eq, normalisiert, Lookup über contact_identifiers), phone (eq, nur Ziffern, Lookup über contact_identifiers), role, property_id, unit_id, merged (Standard false: zusammengeführte Quellkontakte ausgeblendet); sort last_name, first_name |
| GET | /api/v1/contacts/{id} | contacts:read | Bei merged_into_id gesetzt: 301 auf den Zielkontakt mit Header Location, Body enthält merged_into_id |
| GET | /api/v1/contacts/{id}/roles | contacts:read | Rollen mit Objekt, Einheit, Gültigkeit |
| GET | /api/v1/contacts/{id}/documents | documents:read | |
| GET | /api/v1/contacts/{id}/bank-accounts | finance:read | IBAN nur last4; mit finance:read_iban vollständig, jeder Abruf auditiert, Header X-Purpose Pflicht |
| GET | /api/v1/contacts/{id}/versions | contacts:read | Versionshistorie (sync_version, valid_from, valid_to, checksum, detected_by), pseudonymisierte Versionen als 410 in der Einzelabfrage |
| GET | /api/v1/contacts/{id}/duplicates | contacts:read | Duplikatskandidaten aus similarity_hash, nur Vorschlag |

Ohne contacts:read_sensitive fehlen birth_date und Adresshistorie. E-Mail- und Telefonlisten werden ausgegeben, wie im Spiegel gespeichert (Telefon nur Ziffern, Quelle Immoware24 akzeptiert laut Snippet nur Ziffern, DOKUMENTIERT).

### 3.4 Dokumente (Quelle WebDAV VERIFIZIERT)

| Methode | Pfad | Scope | Wirkung | Beschreibung |
|---|---|---|---|---|
| GET | /api/v1/documents | documents:read | hub | Metadaten. Filter folder_id, path (like Prefix), filename (like), content_type, size_bytes (gt, lt), remote_last_modified (gte, lte), property_id, unit_id, contact_id, case_id, origin, content_hash; sort remote_last_modified, filename |
| GET | /api/v1/documents/{id} | documents:read | hub | Metadaten plus Zuordnungen |
| GET | /api/v1/documents/{id}/content | documents:download | hub, on-demand Lesen bei Immoware24 | Liefert den Inhalt. Ist content_stored = 1: aus dem Blob. Sonst on-demand GET per WebDAV über die Lese-Connection mit Rate Limit, Ergebnis wird nicht gespeichert (außer content_policy store), content_hash wird nachgetragen. 503 source_unavailable bei degraded oder offenem Breaker. Jeder Abruf auditiert |
| GET | /api/v1/documents/{id}/versions | documents:read | hub | Metadatenversionen |
| GET | /api/v1/document-folders | documents:read | hub | Ordnerbaum mit scan_priority, content_policy, writable_by_hub, last_scanned_at |
| PATCH | /api/v1/documents/{id}/assignment | cases:write | hub | Hub-eigene Zuordnung property_id, unit_id, contact_id, case_id setzen. Ändert nichts in Immoware24 |

Es gibt kein PUT, PATCH oder DELETE auf den Inhalt oder Pfad eines Dokuments. Ein solcher Aufruf liefert 405 mit Allow-Header.

### 3.5 Upload in den Posteingang (einziger Schreibpfad gegen Immoware24)

| Methode | Pfad | Scope | Wirkung | Beschreibung |
|---|---|---|---|---|
| POST | /api/v1/documents/uploads | documents:create | immoware24 (verzögert) | Legt einen Upload-Antrag an (write_operations, status queued). Multipart: file (Pflicht), filename (Pflicht), case_id, source_document_id, note. Header Idempotency-Key Pflicht. Antwort 202 mit upload_id und status |
| GET | /api/v1/documents/uploads | documents:create oder sync:read | hub | Liste mit Filter status, connection_id, requested_via, sent_at |
| GET | /api/v1/documents/uploads/{id} | documents:create oder sync:read | hub | Status, precheck_result, verify_result (length, hash_match), sent_at, verified_at, target_path, resultierendes document_id |
| POST | /api/v1/documents/uploads/{id}/approve | documents:create, nur Rolle Operator oder höher mit Session und TOTP-Re-Auth | immoware24 | Nur für Anträge mit requested_via = api_key: menschliche Freigabe, danach Ausführung. Anträge aus der UI durch Operatoren sind mit dem Anlegen freigegeben |
| POST | /api/v1/documents/uploads/{id}/cancel | documents:create | hub | Nur in Status queued oder precheck. Ab sent kein Abbruch, weil das Livesystem betroffen sein kann |

Verhalten (Architekturentscheidung Abschnitt 6): Idempotenzschlüssel SHA-256(connection_id, content_hash, Idempotency-Key des Aufrufers), der Header ist damit Bestandteil des Schlüssels und Pflicht; Guards write_enabled, status active, allowed_write_prefix, Größe, Dateiname (ASCII, keine Pfadtrenner, keine führenden Punkte, maximal 120 Zeichen, Hub-UUIDv7-Suffix); Precheck PROPFIND; PUT mit If-None-Match: *; Verifikation per PROPFIND und GET plus Hash. Statuswerte in der API: queued, precheck, sent, unknown, verifying, succeeded, skipped_exists, failed, failed_verify. Status unknown wird ausschließlich lesend aufgelöst, ein zweites PUT gibt es nie.

Solange write_enabled = false ist (Standard, Phase 1 und Phase 3 ohne Freigabe), antwortet POST mit 403 write_disabled. Der Endpunkt wird nicht versteckt, damit Konsumenten ihn korrekt behandeln.

Hinweis Belegstand: Der Upload durch Geräte ist VERIFIZIERT. Ob eine Serveranwendung so zugreifen darf, ist WAITING_FOR_VENDOR_ACCESS. Ob Immoware24 hochgeladene Dateien automatisch Objekten zuordnet, ist NICHT belegt; der Hub liefert nur die Dateinamenskonvention.

### 3.6 Änderungsvorschläge (Human-in-the-Loop-Rückweg)

| Methode | Pfad | Scope | Wirkung | Beschreibung |
|---|---|---|---|---|
| POST | /api/v1/proposals | proposals:create | hub | Body: entity_type (contact, unit, contract, ownership, calendar_event), entity_id, field, old_value, new_value, reason. Erzeugt conflicts mit conflict_type proposed_change, status open. Kein Writeback |
| GET | /api/v1/proposals | conflicts:read | hub | Filter status, entity_type, assigned_to |
| GET | /api/v1/proposals/{id} | conflicts:read | hub | Inklusive confirmed_by_sync_run_id, sobald der Sync die manuelle Umsetzung per Hash bestätigt hat |
| POST | /api/v1/proposals/{id}/resolve | conflicts:resolve | hub | status resolved_applied_manually oder dismissed mit resolution_note. Nur Rolle Operator oder höher |

### 3.7 Konflikte, Merges, Vorgänge (Hub-eigene Daten)

| Methode | Pfad | Scope | Beschreibung |
|---|---|---|---|
| GET | /api/v1/conflicts | conflicts:read | Filter conflict_type, status, entity_type, connection_id, assigned_to |
| GET | /api/v1/conflicts/{id} | conflicts:read | inklusive remote_payload_id, local_snapshot_json |
| POST | /api/v1/conflicts/{id}/assign | conflicts:resolve | assigned_to setzen, 423 wenn bereits in_progress bei anderer Person |
| POST | /api/v1/conflicts/{id}/resolve | conflicts:resolve | status aus resolved_keep_remote, resolved_keep_local, resolved_merge, dismissed plus resolution_note |
| POST | /api/v1/contacts/{id}/merge | conflicts:resolve | Body target_contact_id, reason. Setzt merged_into_id, erzeugt contact_merges. Nie physisch |
| POST | /api/v1/contact-merges/{id}/undo | conflicts:resolve | Rückgängig |
| GET, POST | /api/v1/cases | cases:read, cases:write | Hub-Vorgänge mit property_id, unit_id, contact_id, title, status, immoware_ticket_reference (nur Text, kein Sync belegt) |
| GET, PATCH | /api/v1/cases/{id} | cases:read, cases:write | |

### 3.8 Buchhaltung (lesend, Phase 3, Quellen DATEV-CSV und CAMT.053 DOKUMENTIERT)

| Methode | Pfad | Scope | Beschreibung |
|---|---|---|---|
| GET | /api/v1/transactions | finance:read | Filter kind (ledger, bank), property_id, bank_account_id, booking_date (gte, lte), amount_cents (gte, lte), debit_account, credit_account, cost_center, end_to_end_id; sort booking_date |
| GET | /api/v1/transactions/{id} | finance:read | inklusive import_payload_id |
| GET | /api/v1/open-items | finance:read | Filter property_id, unit_id, contact_id, kind, as_of_date (Pflicht oder latest=true), due_date |
| GET | /api/v1/invoices | finance:read | Filter property_id, creditor_contact_id, invoice_date, invoice_number |
| GET | /api/v1/bank-accounts | finance:read | IBAN last4; vollständig nur mit finance:read_iban und Header X-Purpose |
| GET | /api/v1/finance/summary | finance:read | Aggregate je Objekt und Zeitraum (Summen, Anzahl). Einziger Finanzendpunkt für API Clients in Phase 4 ohne Einzelfreigabe |

Duplikate in DATEV-Zeilen werden bewusst als Duplikate geliefert, nicht verschmolzen (Datenmodell Abschnitt 3.4). Konsumenten erhalten row_hash zur eigenen Deduplikation.

### 3.9 Synchronisation und Importe

| Methode | Pfad | Scope | Beschreibung |
|---|---|---|---|
| GET | /api/v1/connections | sync:read | Ohne Geheimnisse und ohne base_url: name, connector_type, purpose, status, degraded_reason, write_enabled, last_health_at, last_health_ok, last_probe_at, server_fingerprint (nur Präfix 8 Zeichen), auth_scheme |
| GET | /api/v1/connections/{id}/sync-runs | sync:read | Filter run_type, phase, started_at; counters |
| GET | /api/v1/sync-runs/{id} | sync:read | |
| GET | /api/v1/sync-runs/{id}/events | sync:read | sync_events mit action, detected_by, old_checksum, new_checksum |
| POST | /api/v1/connections/{id}/sync-runs | sync:trigger | Body run_type (incremental, full_reconcile, probe). Nur Rolle admin (in Staging ebenfalls admin, keine eigene Developer-Rolle). 409 wenn Lauf aktiv (Redis-Lock) |
| POST | /api/v1/imports | admin:formats oder Rolle Operator | Datei-Import (CSV-Auswertung, DATEV, CAMT) mit Pflichtmetadaten export_type, property_id oder cross_property=true, exported_at, exported_by, full_export (true, false). Antwort 202 mit import_id; Duplikat per Datei-Hash liefert 409 |
| GET | /api/v1/imports/{id} | sync:read | Status (received, quarantined, mapped, done), format_version, Zeilenzähler, Konflikte |
| GET | /api/v1/import-formats | sync:read | Formatversionen mit status, header_columns, key_schema |
| GET | /api/v1/export-schedules | sync:read | Sollrhythmus, next_due_at, überfällig |
| GET | /api/v1/payloads/{id} | sync:read, Rolle admin | Rohpayload (inline oder signierte Blob-URL mit 5 Minuten Gültigkeit). 410 wenn pseudonymisiert. Auditiert |

Cursor, Tokens, Zugangsdaten und vollständige Fingerprints werden nie ausgegeben.

### 3.10 Verwaltung (nur Session, Rolle Administrator oder Owner, TOTP-Re-Auth)

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | /api/v1/connections/{id}/write-enable/request | Erste Person, ausschließlich Rolle admin. Body approval_document_id |
| POST | /api/v1/connections/{id}/write-enable/confirm | Zweite Person (Owner, Rolle release). Muss von write_enabled_by verschieden sein, sonst 403 role_forbidden |
| POST | /api/v1/connections/{id}/write-disable | Jede Person mit Rolle Administrator oder Owner, sofort wirksam |
| POST | /api/v1/connections/{id}/degraded/clear | Nur Owner, Body reason |
| POST | /api/v1/connections/{id}/secret | Rotation, Body secret (nur über TLS, wird nie zurückgegeben) |
| GET, POST, DELETE | /api/v1/api-keys | Anlegen liefert den Klartext genau einmal; DELETE setzt revoked_at |
| GET, POST, PATCH, DELETE | /api/v1/webhook-endpoints | siehe Abschnitt 5 |
| GET, POST | /api/v1/users, PATCH /api/v1/users/{id}/role, POST /api/v1/users/{id}/disable | Nutzerverwaltung. Die Rollen admin und release dürfen nur durch einen Nutzer der Rolle release vergeben oder entzogen werden; admin vergibt nur viewer und operator; niemand ändert die eigene Rolle (403 role_forbidden). Vier Rollen verbindlich, siehe 08-security.md Abschnitt 4 |
| GET | /api/v1/audit-logs | Filter actor_type, actor_id, action, entity_type, entity_id, occurred_at; Read Only ohne before_json und after_json |
| GET | /api/v1/audit-logs/verify | Ergebnis der letzten Kettenprüfung und letzter Anker |

Capabilities mit hard_locked = 1 haben keinen Endpunkt zum Entsperren. Ein PATCH auf /api/v1/capabilities/{id} mit enabled = true wird zusätzlich vom Datenbank-Trigger abgewiesen (403 capability_locked).

## 4. Health-Endpunkte

| Pfad | Auth | Antwort | Zweck |
|---|---|---|---|
| GET /health/live | keine | 200 {"status":"ok"} sobald der Prozess antwortet | Liveness für Orchestrierung |
| GET /health/ready | keine, nur aus internem Netz | 200 oder 503 mit Checks db, redis, blob, queue_workers, scheduler_heartbeat (letzter Lauf unter 5 Minuten), encryption_key_loaded | Readiness |
| GET /api/v1/health | sync:read | Detailstatus je Connection: status, last_health_ok, last_health_at, breaker_state (closed, open, half_open), data_age_seconds je Collection, offene Konflikte, write_operations in unknown, überfällige Exporte, Ergebnis audit_chain_ok, letzte Anker-Zeit | Betrieb und Konsumenten |
| GET /api/v1/health/connections/{id} | sync:read | wie oben für eine Connection | |

Health-Antworten enthalten keine URLs von Immoware24, keine Nutzernamen und keine Fehlermeldungen des DAV-Servers im Wortlaut (nur HTTP-Statuscode und Kategorie). Der Health-Check gegen Immoware24 selbst ist ein PROPFIND Depth 0 auf den Freigabe-Root mit den festen Limits der Connection; er erzeugt keine Schreibvorgänge.

## 5. Webhook-Events (ausgehend)

Das Modul ist vorhanden, wird aber erst aktiviert, wenn ein konkreter Konsument benannt ist (Architekturentscheidung Abschnitt 7). Es gibt keine eingehenden Webhooks von Immoware24 (NICHT VERFÜGBAR).

### 5.1 Zustellung

- Registrierung über /api/v1/webhook-endpoints mit url (nur HTTPS), events (Liste), secret (vom Hub erzeugt, einmal angezeigt, verschlüsselt gespeichert).
- Outbox-Muster: Ereignis wird in derselben Transaktion wie die fachliche Änderung in webhook_outbox geschrieben; ein Worker stellt zu. Keine Doppelzustellung durch Unique (endpoint_id, outbox_id); Konsumenten müssen dennoch idempotent auf event_id sein.
- Retry: exponentiell mit Jitter, 1 Minute bis 24 Stunden, maximal 10 Versuche, danach status failed und Alarm an Administrator. Manuelle Wiederzustellung über POST /api/v1/webhook-deliveries/{id}/retry.
- Zeitlimit für die Antwort des Konsumenten 10 Sekunden; jede 2xx-Antwort gilt als zugestellt.

### 5.2 Signatur

Header:
- X-Hub-Event: Ereignisname
- X-Hub-Event-Id: UUID des Outbox-Eintrags
- X-Hub-Timestamp: Unix-Sekunden
- X-Hub-Signature: v1=<hex HMAC-SHA256>

Signatur: HMAC-SHA256(secret, X-Hub-Timestamp + "." + rohem Request-Body). Konsumenten verwerfen Anfragen, deren Zeitstempel mehr als 300 Sekunden von der eigenen Uhr abweicht (Replay-Fenster 5 Minuten), und vergleichen die Signatur zeitkonstant. Bei Secret-Rotation sendet der Hub für 24 Stunden zwei Signaturen (v1=alt, v1=neu, kommagetrennt).

### 5.3 Ereignisse

Payload-Format:

```json
{
  "event_id": "018f...",
  "event": "document.created",
  "occurred_at": "2026-09-11T08:15:30.000Z",
  "organization_id": "018f...",
  "data": { "id": "018f...", "type": "document", "href": "/api/v1/documents/018f..." },
  "source": { "connection_id": "018f...", "access_path": "webdav", "evidence_status": "VERIFIZIERT", "sync_run_id": "018f...", "detected_by": "etag" }
}
```

Payloads enthalten nur IDs, Typ, Link und Herkunft sowie nicht personenbezogene Steuerfelder (role, identity_confidence, as_of_date, status einer write_operation), keine personenbezogenen Feldwerte und keine Beträge (Datensparsamkeit, 08-security.md Abschnitt 7.2). Der Konsument holt Details mit seinem Key. Dieses Format ist verbindlich für docs/n8n/; die Beispiele dort folgen ihm.

| Ereignis | Auslöser |
|---|---|
| document.created, document.updated, document.moved, document.deleted | Spiegeländerung per Sync (deleted erst nach zwei Läufen ohne Treffer) |
| document.upload.queued, document.upload.sent, document.upload.succeeded, document.upload.skipped_exists, document.upload.failed, document.upload.failed_verify, document.upload.unknown | Statuswechsel in write_operations |
| contact.created, contact.updated, contact.deleted, contact.merged, contact.merge_undone | Spiegel bzw. Hub |
| unit.created, unit.updated, unit.deleted, property.created, property.updated, property.deleted, contract.created, contract.updated, contract.deleted, ownership.created, ownership.updated, ownership.deleted | Spiegel |
| contact_role.created, contact_role.updated, contact_role.deleted | Spiegel (Belegungs- und Eigentümerlisten aus CSV); data enthält zusätzlich role und die IDs von contact, unit und property, keine Namen |
| open_item.created, open_item.updated, transaction.imported, invoice.imported | Phase 3; open_item.created bei neuem Stichtag, open_item.updated bei geändertem Betrag innerhalb desselben Stichtags |
| conflict.opened, conflict.resolved, proposal.created, proposal.resolved, proposal.confirmed_by_sync | Konfliktqueue |
| import.received, import.quarantined, import.done | Datei-Importe |
| sync.run.finished, sync.run.failed | je SyncRun |
| connection.degraded, connection.restored, connection.health_failed, connection.health_recovered | Betrieb |
| export.overdue | Export-Erinnerung |

Es gibt keine Ereignisse, die ein Fremdsystem zu Schreibvorgängen in Immoware24 auffordern.

## 6. OpenAPI und Dokumentation

- OpenAPI 3.1 wird aus Attributen im Code generiert (z. B. mit zircote/swagger-php oder vergleichbar) und im CI gegen die Implementierung geprüft (Contract-Test). Handgeschriebene Abweichungen zwischen Dokument und Code brechen den Build.
- Auslieferung unter /api/docs (Redoc oder Swagger UI) und /api/docs/openapi.json bzw. /api/docs/openapi.yaml. Zugriff nur für angemeldete Nutzer und gültige API-Keys, nicht öffentlich, weil die Ressourcenliste Rückschlüsse auf den Bestand zulässt.
- Jede Operation trägt in der OpenAPI-Beschreibung die Erweiterungen x-scope (benötigte Scopes), x-effect (hub oder immoware24), x-evidence-status (Belegstatus des Zugangswegs) und x-phase (1 bis 4). Die Directory-Antwort (3.1) wird aus denselben Attributen erzeugt.
- Beispielantworten in der OpenAPI enthalten fiktive Platzhalterdaten, keine echten Kontakte oder Objekte des Bestands.
- Änderungen an der API werden in docs/immoware/CHANGELOG-api.md (anzulegen) mit Datum TT.MM.JJJJ festgehalten.

## 7. Offene Punkte

| Punkt | Kennzeichnung | Nächster Schritt |
|---|---|---|
| Felder der Stammdatenressourcen hängen vom Spaltenformat der CSV-Auswertungen ab | NICHT VERFÜGBAR bis Sichtung echter Exportdateien | Phase 0, import_formats Version 1 |
| Kontaktfelder aus CardDAV (welche vCard-Properties Immoware24 füllt) | zu verifizieren am eigenen Mandanten | Phase 0, Beispiel-vCard sichern |
| Verfügbarkeit des Upload-Endpunkts | write_enabled = false bis Freigabe der Geschäftsführung; Zulässigkeit automatisierter Nutzung WAITING_FOR_VENDOR_ACCESS | Phase 2 |
| Erster externer Konsument für API-Keys und Webhooks | nicht benannt | Phase 4, ohne Konsument keine Aktivierung |
| Domain und Hosting der API | festzulegen | Phase 1 |
| Kalenderressource /api/v1/calendar-events | CalDAV lesend, niedrige Priorität, Schreibrichtung nicht belegt | Phase 3 optional |
| Aggregationsumfang /api/v1/finance/summary | mit Steuerberater und Geschäftsführung abzustimmen | vor Phase 3 |
