# 09 Hub-REST-API v1, Entwurf

Stand: 11.09.2026, Änderungsvermerk 12.09.2026 (Abgleich mit dem Code: Endpunkte, Statuswerte, Capability-Schlüssel, Pagination, Health)
Bezug: Architekturentscheidung Abschnitte 2, 5, 6, 7 und 9 (Phase 4); Datenmodell; 08-security.md (Rollen, Scopes, API-Keys).

Status dieses Dokuments: Entwurf. Die API ist die Schnittstelle des Immoware Hub für Fremdsysteme und den späteren KI/MCP-Layer. Sie ist keine Immoware24-API. Immoware24 stellt nach aktuellem Recherchestand keine öffentliche REST-API, keine Webhooks und keine API-Keys bereit (NICHT VERFÜGBAR, Negativbefund vom 11.09.2026, keine offizielle Aussage). Jede Ressource dieser API liefert deshalb Spiegeldaten mit Datenalter und Belegstatus der Quelle, nie Live-Daten aus Immoware24.

Zeitplan: Die Endpunkte unter /api/v1 werden in Phase 1 bis 3 nur intern von der Hub-UI genutzt (Session-Auth). Externe API-Keys und Webhooks werden erst in Phase 4 und nur für benannte Konsumenten freigeschaltet (Architekturentscheidung Abschnitt 9).

## 0. Grundsätze

1. Lesend zuerst. Es gibt genau einen schreibenden Endpunkt mit Wirkung auf Immoware24: POST /api/v1/documents (Upload-Antrag in den Posteingang, asynchron, Antwort 202, Status über GET /api/v1/documents/uploads/{operation_uuid}). Alle anderen schreibenden Endpunkte ändern nur Hub-eigene Daten (Vorgänge, Konflikte, Änderungsvorschläge).
2. Keine Endpunkte für Update oder Delete von Spiegeldaten. Wer etwas in Immoware24 ändern will, legt einen Änderungsvorschlag an (proposed_change), der von einem Menschen in Immoware24 umgesetzt wird.
3. Jede Antwort trägt Herkunft und Datenalter (meta.source).
4. Stabile Hub-IDs. Im Code sind das BIGINT-Autoincrement-IDs (Änderungsvermerk 12.09.2026, 02-data-model.md Konventionen); write_operations tragen zusätzlich eine operation_uuid, die in der Upload-API als Kennung dient. Externe Schlüssel werden nur als Zusatzinformation ausgegeben.
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
| IDs | Ganzzahl (BIGINT), in Pfaden numerisch; operation_uuid der Uploads als UUID-String |
| Idempotenz | Header Idempotency-Key (UUID) Pflicht für alle POST mit Wirkung; Wiederholung liefert dieselbe Antwort mit Header Idempotent-Replayed: true |
| Request-ID | Header X-Request-Id wird gespiegelt, sonst erzeugt; erscheint im Audit und im Fehlerobjekt |
| Rate Limit | 600 Requests pro Minute lesend, 60 pro Minute für schreibende Endpunkte je Key (config/hub/api.php rate_limits, zusätzlich rate_limit_per_minute je Key); Header RateLimit-Limit, RateLimit-Remaining, RateLimit-Reset; bei Überschreitung 429 mit Retry-After |
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

Zwei Modi (Änderungsvermerk 12.09.2026, ListQuery und ApiResponse): Offset-Pagination als Standard (page, per_page, Default 100, Maximum 500) und Cursor-Pagination, sobald der Parameter cursor vorhanden ist (auch leer für die erste Seite). Cursor ist opaque (Base64url-JSON aus Sortierschlüssel und id), stabil bei laufendem Sync und für große Tabellen zu bevorzugen. Cursor sind an sort und Filter gebunden; ein Cursor mit anderer Sortierung liefert 400 invalid_cursor.

Cursor-Modus (?cursor=&per_page=100&sort=-updated_at):

```json
{
  "data": [ { "...": "..." } ],
  "meta": {
    "per_page": 100,
    "count": 100,
    "next_cursor": "eyJ1cGRhdGVkX2F0IjoiLi4uIiwiaWQiOjQyfQ",
    "prev_cursor": null,
    "request_id": "...",
    "source": { "...": "wie 2.1, aggregiert: source_status ist der schlechteste Wert der Seite" }
  },
  "links": { "self": "...", "next": "...?cursor=eyJ1...", "prev": null }
}
```

Offset-Modus (Standard): meta enthält page, per_page, total, last_page, request_id, source; links enthält self, first, last, next, prev. Für Exporte über viele Seiten den Cursor-Modus verwenden, weil total bei Millionen Zeilen einen Count je Anfrage kostet.

Sortierung über sort=field oder sort=-field, erlaubte Felder je Endpunkt in der OpenAPI.

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
  "instance": "/api/v1/documents",
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
| 501 | not_implemented | WAITING_FOR_MODULE, Baustein nicht verfügbar (z. B. Upload-Service) |
| 503 | service_unavailable, source_unavailable | Hub im Wartungsmodus bzw. Connection nicht erreichbar bei on-demand Download |

Fehlermeldungen enthalten nie Zugangsdaten, Pfade des Dateisystems oder Stacktraces.

## 3. Endpunkte

Änderungsvermerk 12.09.2026 (Abgleich mit routes/modules/api.php, routes/modules/webhooks.php, routes/modules/mcp.php): Spalte Stand nennt **umgesetzt** (Route im Code vorhanden) oder **geplant** (nur Konzept, Aufruf liefert 404). Geplante Endpunkte werden erst mit Bedarf eines benannten Konsumenten gebaut; bis dahin erledigen Operator und Administrator die betreffenden Aktionen in der Admin-Oberfläche (Blade, Session, TOTP). Scope-Namen sind die des Codes (config/hub/security.php api_keys.scopes): der frühere Scope documents:create heißt documents:write.

Legende Spalte Scope: benötigter Scope laut 08-security.md. Spalte Wirkung: hub (nur Hub-Daten) oder immoware24 (Wirkung auf das Livesystem). Die Spalten Filter, Sort und include geben den Konzeptumfang wieder; verbindlich für den Ist-Stand ist die aus dem Code erzeugte OpenAPI unter /api/docs/openapi.json.

### 3.1 Directory und Meta

| Methode | Pfad | Scope | Stand | Beschreibung |
|---|---|---|---|---|
| GET | /api/v1 | keiner (Key gültig) | umgesetzt | Directory: Liste aller Ressourcen mit URL, erlaubten Methoden, benötigten Scopes und Belegstatus des zugrunde liegenden Zugangswegs; Link auf /api/docs |
| GET | /api/v1/me | keiner | umgesetzt | Aktueller Aufrufer: Typ (user, api_key), Rolle, Scopes, Ablauf, Organisation |
| GET | /api/v1/capabilities | sync:read | umgesetzt | Capability Registry: capability_key (fachliche Schlüssel aus App\Modules\Connector\Enums\CapabilityKey: contacts.read, contacts.write, calendar.read, calendar.write, documents.read, documents.write, documents.overwrite, documents.delete, documents.move, properties.read, units.read, contracts.read, finance.read, cases.read, cases.write), evidence_status, source_url, enabled, hard_locked, tested_at. Fähigkeiten unter DOKUMENTIERT werden nie als verfügbar gemeldet |
| GET | /api/v1/sync/status | sync:read | umgesetzt | Sync-Stand je Connection und Entität (last_success_at, data_age_seconds, stale) |
| GET | /api/docs, /api/docs/openapi.json | Key gültig | umgesetzt | HTML-Dokumentation und OpenAPI 3.1, aus dem Code generiert (OpenApiGenerator) |
| POST | /api/v1/mcp/tools, /api/v1/mcp/call | Scopes des Keys | umgesetzt | MCP-Tool-Katalog und Aufruf (docs/mcp/), lesend, delegiert an die Ressourcen dieser API |

Directory-Antwort (gekürzt):

```json
{
  "data": {
    "version": "v1",
    "docs_url": "https://immoware.muellerhv.de/api/docs",
    "openapi_url": "https://immoware.muellerhv.de/api/docs/openapi.json",
    "resources": [
      { "name": "properties", "href": "/api/v1/properties", "methods": ["GET"], "scopes": ["properties:read"], "source": { "access_path": "file_import", "evidence_status": "DOKUMENTIERT" } },
      { "name": "contacts", "href": "/api/v1/contacts", "methods": ["GET"], "scopes": ["contacts:read"], "source": { "access_path": "carddav", "evidence_status": "VERIFIZIERT" } },
      { "name": "documents", "href": "/api/v1/documents", "methods": ["GET", "POST"], "scopes": ["documents:read", "documents:write"], "source": { "access_path": "webdav", "evidence_status": "VERIFIZIERT", "write_enabled": false } }
    ]
  }
}
```

### 3.2 Stammdaten (lesend, Quelle CSV-Export, Spaltenformat NICHT VERFÜGBAR bis Phase 0)

Umgesetzt sind die Listen- und Einzelendpunkte der Ressourcen properties, units, contracts (ResourceRegistry, generischer ResourceController). Sub-Ressourcen und ownerships sind geplant.

| Methode | Pfad | Scope | Stand | Filter, Sort | include |
|---|---|---|---|---|---|
| GET | /api/v1/properties | properties:read | umgesetzt | management_type, immoware_object_number, postal_code, city, name (like); sort name, immoware_object_number | buildings |
| GET | /api/v1/properties/{id} | properties:read | umgesetzt | | buildings, units |
| GET | /api/v1/properties/{id}/units | units:read | geplant | wie units, bis dahin filter[property_id] auf /units | |
| GET | /api/v1/properties/{id}/documents | documents:read | geplant | bis dahin filter[property_id] auf /documents | |
| GET | /api/v1/properties/{id}/open-items | finance:read | geplant | bis dahin filter[property_id] auf /open-items | |
| GET | /api/v1/units | units:read | umgesetzt | property_id, unit_type, unit_number, floor; sort unit_number | property, building, current_contract, current_owners |
| GET | /api/v1/units/{id} | units:read | umgesetzt | | property, contracts, ownerships, documents |
| GET | /api/v1/contracts | contracts:read | umgesetzt | unit_id, property_id, start_date, end_date, active (true: end_date null oder in der Zukunft) | unit, parties |
| GET | /api/v1/contracts/{id} | contracts:read | umgesetzt | | |
| GET | /api/v1/ownerships | ownerships:read | geplant | unit_id, contact_id, valid_from, valid_to; Scope existiert im Code noch nicht | |

Felder mit identity_confidence = uncertain tragen im Datensatz das Flag identity_uncertain: true. Konsumenten dürfen solche Datensätze nicht als eindeutig zugeordnet behandeln.

### 3.3 Kontakte (lesend, Quelle CardDAV VERIFIZIERT und CSV DOKUMENTIERT)

| Methode | Pfad | Scope | Stand | Beschreibung |
|---|---|---|---|---|
| GET | /api/v1/contacts | contacts:read | umgesetzt | Filter kind, last_name (like), email (eq, normalisiert, Lookup über contact_identifiers), phone (eq, nur Ziffern, Lookup über contact_identifiers), role, property_id, unit_id, merged (Standard false: zusammengeführte Quellkontakte ausgeblendet); sort last_name, first_name |
| GET | /api/v1/contacts/{id} | contacts:read | umgesetzt | Bei merged_into_id gesetzt: 301 auf den Zielkontakt mit Header Location, Body enthält merged_into_id |
| PATCH | /api/v1/contacts/{id} | contacts:write | umgesetzt | Kein Writeback: erzeugt einen Änderungsvorschlag (conflicts, conflict_type proposed_change), Antwort 202. Header Idempotency-Key Pflicht |
| GET | /api/v1/directory, /api/v1/directory/search | directory:read | umgesetzt | Kontaktverzeichnis (json, vcf, xml) ohne Notizen, für Telefonanlagen und Adressbücher |
| GET | /api/v1/contacts/{id}/roles | contacts:read | geplant | Rollen mit Objekt, Einheit, Gültigkeit |
| GET | /api/v1/contacts/{id}/documents | documents:read | geplant | bis dahin filter[contact_id] auf /documents |
| GET | /api/v1/contacts/{id}/bank-accounts | finance:read | geplant | IBAN nur last4; Scope finance:read_iban existiert im Code noch nicht |
| GET | /api/v1/contacts/{id}/versions | contacts:read | geplant | Versionshistorie |
| GET | /api/v1/contacts/{id}/duplicates | contacts:read | geplant | Duplikatskandidaten aus similarity_hash, nur Vorschlag (Admin-Oberfläche zeigt sie bereits) |

Ohne contacts:read_sensitive (geplant) fehlen birth_date und Adresshistorie. E-Mail- und Telefonlisten werden ausgegeben, wie im Spiegel gespeichert (Telefon nur Ziffern, Quelle Immoware24 akzeptiert laut Snippet nur Ziffern, DOKUMENTIERT).

### 3.4 Dokumente (Quelle WebDAV VERIFIZIERT)

| Methode | Pfad | Scope | Stand | Wirkung | Beschreibung |
|---|---|---|---|---|---|
| GET | /api/v1/documents | documents:read | umgesetzt | hub | Metadaten. Filter folder_id, path (like Prefix), filename (like), content_type, size_bytes (gt, lt), remote_last_modified (gte, lte), property_id, unit_id, contact_id, case_id, origin, content_hash; sort remote_last_modified, filename |
| GET | /api/v1/documents/{id} | documents:read | umgesetzt | hub | Metadaten plus Zuordnungen |
| GET | /api/v1/documents/{id}/content | documents:download | geplant | hub, on-demand Lesen bei Immoware24 | Inhalt aus Blob oder on-demand GET per WebDAV über die Lese-Connection; 503 source_unavailable bei degraded oder offenem Breaker; jeder Abruf auditiert. Scope existiert im Code noch nicht |
| GET | /api/v1/documents/{id}/versions | documents:read | geplant | hub | Metadatenversionen |
| GET | /api/v1/document-folders | documents:read | geplant | hub | Ordnerbaum mit scan_priority, content_policy, writable_by_hub, last_scanned_at (Admin-Oberfläche zeigt ihn) |
| PATCH | /api/v1/documents/{id}/assignment | cases:write | geplant | hub | Hub-eigene Zuordnung property_id, unit_id, contact_id, case_id setzen |

Es gibt kein PUT, PATCH oder DELETE auf den Inhalt oder Pfad eines Dokuments. Ein solcher Aufruf liefert 405 mit Allow-Header.

### 3.5 Upload in den Posteingang (einziger Schreibpfad gegen Immoware24, asynchron)

| Methode | Pfad | Scope | Stand | Wirkung | Beschreibung |
|---|---|---|---|---|---|
| POST | /api/v1/documents | documents:write | umgesetzt | immoware24 (verzögert) | Upload-Antrag (write_operations, status pending). Multipart: file (Pflicht, bis max_upload_bytes), filename (Pflicht, ASCII, maximal 120 Zeichen), connection_id (optional, sonst die einzige aktive Schreib-Connection), case_id, source_document_id (beide nur aus dem eigenen Mandanten, sonst 422), note. Header Idempotency-Key Pflicht. Antwort 202 Accepted mit Header Location auf den Status-Endpunkt |
| GET | /api/v1/documents/uploads/{operation_uuid} | documents:write oder sync:read | umgesetzt | hub | Status eines Antrags des eigenen Mandanten (Zuordnung über die Connection), sonst 404 |
| GET | /api/v1/documents/uploads | documents:write oder sync:read | geplant | hub | Liste mit Filter status, connection_id, requested_via, sent_at (Admin-Oberfläche zeigt sie) |
| POST | /api/v1/documents/uploads/{operation_uuid}/approve | documents:write, nur Session mit TOTP-Re-Auth | geplant | immoware24 | Menschliche Freigabe eines Antrags mit requested_via = api_key; bis dahin Freigabe ausschließlich im Hub durch Operator oder höher (Admin-Oberfläche, Kommando hub:write:approve) |
| POST | /api/v1/documents/uploads/{operation_uuid}/cancel | documents:write | geplant | hub | Nur in Status pending oder prechecked. Ab sent kein Abbruch, weil das Livesystem betroffen sein kann |

Asynchroner Ablauf: POST legt nur den Antrag an und antwortet sofort mit 202. Die Ausführung (Precheck, PUT, Verifikation) läuft in der Queue write. Der Aufrufer fragt den Status über status_url ab (Polling, empfohlen ab 30 Sekunden, dann exponentiell bis 5 Minuten); Ereignisse per Webhook für Uploads sind geplant (Abschnitt 5.3).

Antwort 202 (Felder aus DocumentUploadController::present):

```json
{
  "data": {
    "operation_uuid": "0192a3b4-...-...",
    "upload_id": 17,
    "status": "pending",
    "operation": "webdav_create",
    "target_path": "/Posteingang/Rechnung_Hausmeister_2026-09_0192a3b4.pdf",
    "original_filename": "Rechnung_Hausmeister_2026-09.pdf",
    "size_bytes": 18234,
    "content_hash": "9a1f...",
    "requested_via": "api_key",
    "precheck_result": null,
    "verify_result": null,
    "last_error": null,
    "document_id": null,
    "case_id": null,
    "source_document_id": null,
    "sent_at": null, "verified_at": null, "failed_at": null,
    "created_at": "2026-09-12T08:15:30.000Z", "updated_at": "2026-09-12T08:15:30.000Z",
    "outcome": "pending_approval",
    "effect": "immoware24_after_approval",
    "approval_required": true,
    "status_url": "/api/v1/documents/uploads/0192a3b4-...-..."
  },
  "meta": { "request_id": "..." }
}
```

Statuswerte (App\Core\Enums\WriteOperationStatus, verbindlich seit 12.09.2026; Zuordnung zu den früheren Konzeptnamen in 05-write-capabilities.md Abschnitt 3.3):

| Status | Bedeutung | final |
|---|---|---|
| pending | Antrag angelegt, noch kein Netzwerkzugriff; auch bei gesperrtem Flag oder fehlender Freigabe (outcome denied oder pending_approval) | nein |
| prechecked | PROPFIND Depth 0 auf target_path ausgeführt, Ziel frei | nein |
| sent | PUT mit If-None-Match: * abgesetzt (Status wird vor dem Senden persistiert, put_attempts = 1) | nein |
| unknown | Netzwerkfehler nach PUT ohne verwertbare Antwort; Auflösung ausschließlich per PROPFIND, nie durch ein zweites PUT | nein |
| verified | Ressource per PROPFIND (und GET-Hash bei verify_with_hash) bestätigt; documents-Zeile auf der gekoppelten Lese-Connection angelegt | ja |
| failed | Fehler vor oder nach dem PUT ohne bestätigte Ressource (precheck_result beziehungsweise last_error nennen den Grund, z. B. verify_failed, unknown_unresolved) | ja |
| rejected | Abgewiesen ohne Livesystem-Wirkung: Guards, 412 Precondition Failed (Ziel existiert, precheck_result.conflict = write_target_exists), Validierung | ja |

Verhalten (Architekturentscheidung Abschnitt 6): Idempotenzschlüssel SHA-256(connection_id, content_hash, Idempotency-Key des Aufrufers), der Header ist damit Bestandteil des Schlüssels und Pflicht; Guards write_enabled, status active, allowed_write_prefix, Größe, Dateiname (ASCII, keine Pfadtrenner, keine führenden Punkte, maximal 120 Zeichen, Hub-UUIDv7-Suffix); Precheck PROPFIND; PUT mit If-None-Match: *; Verifikation per PROPFIND und GET plus Hash. Anträge aus API-Key-Kontext wirken erst nach menschlicher Freigabe (approval_required true, effect immoware24_after_approval).

Solange IMMOWARE_WRITE_ENABLED oder IMMOWARE_WRITE_WEBDAV_CREATE_ENABLED false ist (Standard, Phase 1 und Phase 3 ohne Freigabe), antwortet POST mit 403 write_disabled (WriteGuard). Der Endpunkt wird nicht versteckt, damit Konsumenten ihn korrekt behandeln. Fehlt das Documents-Modul: 501 WAITING_FOR_MODULE.

Hinweis Belegstand: Der Upload durch Geräte ist VERIFIZIERT. Ob eine Serveranwendung so zugreifen darf, ist WAITING_FOR_VENDOR_ACCESS. Ob Immoware24 hochgeladene Dateien automatisch Objekten zuordnet, ist NICHT belegt; der Hub liefert nur die Dateinamenskonvention. Stand 12.09.2026: kein Upload wurde am echten Mandanten ausgeführt.

### 3.6 Änderungsvorschläge (Human-in-the-Loop-Rückweg)

| Methode | Pfad | Scope | Stand | Wirkung | Beschreibung |
|---|---|---|---|---|---|
| PATCH | /api/v1/contacts/{id} | contacts:write | umgesetzt | hub | Erzeugt conflicts mit conflict_type proposed_change, status open; Antwort 202. Kein Writeback (siehe 3.3) |
| GET | /api/v1/proposals, /api/v1/proposals/{id} | conflicts:read | umgesetzt | hub | Vorschläge lesen (Ressource proposals der ResourceRegistry), Filter status, entity_type, assigned_to |
| POST | /api/v1/proposals | proposals:create | geplant | hub | Generischer Vorschlag für entity_type unit, contract, ownership, calendar_event; bis dahin nur Kontakte per PATCH |
| POST | /api/v1/proposals/{id}/resolve | conflicts:resolve | geplant | hub | Auflösung; bis dahin in der Admin-Oberfläche (Konflikte) |

### 3.7 Konflikte, Merges, Vorgänge (Hub-eigene Daten)

| Methode | Pfad | Scope | Stand | Beschreibung |
|---|---|---|---|---|
| GET | /api/v1/conflicts, /api/v1/conflicts/{id} | conflicts:read | umgesetzt | Filter conflict_type, status, entity_type, connection_id, assigned_to |
| POST | /api/v1/conflicts/{id}/assign, /resolve | conflicts:resolve | geplant | bis dahin Admin-Oberfläche |
| POST | /api/v1/contacts/{id}/merge, /api/v1/contact-merges/{id}/undo | conflicts:resolve | geplant | Logischer Merge über merged_into_id, nie physisch; bis dahin Admin-Oberfläche |
| GET, POST | /api/v1/cases | cases:read, cases:write | umgesetzt | Hub-Vorgänge mit property_id, unit_id, contact_id, title, status, immoware_ticket_reference (nur Text, kein Sync belegt). POST mit Idempotency-Key |
| GET, PATCH | /api/v1/cases/{id} | cases:read, cases:write | umgesetzt | |

### 3.8 Buchhaltung (lesend, Phase 3, Quellen DATEV-CSV und CAMT.053 DOKUMENTIERT)

| Methode | Pfad | Scope | Stand | Beschreibung |
|---|---|---|---|---|
| GET | /api/v1/transactions, /{id} | finance:read | umgesetzt | Filter kind (ledger, bank), property_id, bank_account_id, booking_date (gte, lte), amount_cents (gte, lte), debit_account, credit_account, cost_center, end_to_end_id; sort booking_date |
| GET | /api/v1/open-items, /{id} | finance:read | umgesetzt | Filter property_id, unit_id, contact_id, kind, as_of_date, due_date |
| GET | /api/v1/invoices, /{id} | finance:read | umgesetzt | Filter property_id, creditor_contact_id, invoice_date, invoice_number |
| GET | /api/v1/calendar-events, /{id} | properties:read | umgesetzt | CalDAV-Terminspiegel, lesend |
| GET | /api/v1/bank-accounts | finance:read | geplant | IBAN last4; vollständig nur mit finance:read_iban (Scope geplant) und Header X-Purpose |
| GET | /api/v1/finance/summary | finance:read | geplant | Aggregate je Objekt und Zeitraum; Umfang mit Steuerberater und Geschäftsführung abzustimmen |

Duplikate in DATEV-Zeilen werden bewusst als Duplikate geliefert, nicht verschmolzen (Datenmodell Abschnitt 3.4). Konsumenten erhalten row_hash zur eigenen Deduplikation.

### 3.9 Synchronisation und Importe

| Methode | Pfad | Scope | Stand | Beschreibung |
|---|---|---|---|---|
| GET | /api/v1/sync/status | sync:read | umgesetzt | Sync-Stand je Connection und Entität ohne Geheimnisse und ohne base_url |
| GET | /api/v1/connections, /{id}/sync-runs, /api/v1/sync-runs/{id}, /events | sync:read | geplant | Admin-Oberfläche (Verbindungen, Sync) zeigt diese Daten |
| POST | /api/v1/connections/{id}/sync-runs | sync:trigger | geplant | Manueller Lauf; bis dahin Admin-Oberfläche oder hub:sync:* Kommandos |
| POST | /api/v1/imports, GET /api/v1/imports/{id}, /import-formats, /export-schedules | imports | geplant | Datei-Import läuft über Drop-Ordner und Admin-Oberfläche (Modul Imports) |
| GET | /api/v1/payloads/{id} | sync:read, Rolle admin | geplant | Rohpayload; Admin-Oberfläche (Records) zeigt Payloads auditiert |

Cursor, Tokens, Zugangsdaten und vollständige Fingerprints werden nie ausgegeben.

### 3.10 Verwaltung (geplant, bis dahin ausschließlich Admin-Oberfläche)

Alle Verwaltungsaktionen (write-enable request und confirm, write-disable, degraded clear, Secret-Rotation, Nutzer, Rollen, Audit-Ansicht, Kettenprüfung) sind im Code als Blade-Seiten des Moduls Admin umgesetzt (Session, 2FA, Bestätigungswort, für sicherheitskritische Aktionen frische TOTP-Bestätigung), nicht als REST-Endpunkte. Ausnahme: API-Keys und Webhook-Endpunkte (Abschnitt 5.1) sind zusätzlich per API verwaltbar.

| Methode | Pfad | Stand | Beschreibung |
|---|---|---|---|
| POST | /api/v1/connections/{id}/write-enable/request, /confirm, /write-disable, /degraded/clear, /secret | geplant | Vier-Augen-Prinzip: erste Person administrator, zweite Person owner, verschieden (08-security.md Abschnitt 4) |
| GET, POST, DELETE | /api/v1/api-keys | geplant | Admin-Oberfläche legt Keys an (Klartext genau einmal) und widerruft sie |
| GET, POST, DELETE | /api/v1/webhook-endpoints | umgesetzt | Scope webhooks:manage, Rate Limit wie Schreibendpunkte, POST mit Idempotency-Key; PATCH geplant |
| Nutzerverwaltung, Audit-Logs, Audit-Verify | | geplant | Sechs Rollen des Codes, siehe 08-security.md Abschnitt 4 |

Capabilities mit hard_locked = 1 haben keinen Endpunkt zum Entsperren. Die Sperre ist in der Anwendung fest verdrahtet (CapabilityRegistry, HttpClientFactory::BLOCKED_METHODS, BootGuard); ein Datenbank-Trigger für capabilities.enabled ist nicht umgesetzt (02-data-model.md, Abgleich 12.09.2026).

## 4. Health-Endpunkte

Änderungsvermerk 12.09.2026: Pfade und Verhalten gemäß routes/modules/api.php und HealthService. Ohne Auth nur status; mit API-Key und Scope admin zusätzlich details und checked_at. Cache-Control: no-store.

| Pfad | Auth | Antwort | Zweck |
|---|---|---|---|
| GET /up | keine | 200, Framework bootet | Liveness für Orchestrierung |
| GET /health | keine (Details mit Admin-Key) | Aggregat aus database, queue, immoware; 200 bei ok und degraded, 503 bei down. immoware ist down, sobald eine aktive Connection keinen Sync-Stand hat oder eine Entität stale ist; deshalb kein Deploy-Kriterium (docs/operations/03-monitoring.md) | Monitoring |
| GET /health/database | keine | 200 oder 503: PDO, select 1, Tabelle migrations, Latenz | Deploy und Readiness |
| GET /health/queue | keine | 200 oder 503: Tiefe der Tabelle jobs (bei Redis-Queue immer 0, Einschränkung in 03-monitoring.md Abschnitt 5), failed_jobs, offene dlq_items | Deploy und Readiness |
| GET /health/immoware | keine | 200 oder 503: je aktiver Connection last_health_ok, degraded_reason, je Entität last_success_at, data_age_seconds, threshold_seconds, stale | Betrieb |
| GET /health/live, /health/ready, /api/v1/health, /api/v1/health/connections/{id} | | geplant | Breaker-Zustand, offene Konflikte, write_operations in unknown, audit_chain_ok liefert bis dahin das Admin-Dashboard |

Health-Antworten enthalten keine URLs von Immoware24, keine Nutzernamen und keine Fehlermeldungen des DAV-Servers im Wortlaut (nur HTTP-Statuscode und Kategorie). Der Health-Check gegen Immoware24 selbst ist ein PROPFIND Depth 0 auf den Freigabe-Root mit den festen Limits der Connection; er erzeugt keine Schreibvorgänge.

## 5. Webhook-Events (ausgehend)

Das Modul ist vorhanden, wird aber erst aktiviert, wenn ein konkreter Konsument benannt ist (Architekturentscheidung Abschnitt 7). Es gibt keine eingehenden Webhooks von Immoware24 (NICHT VERFÜGBAR).

### 5.1 Zustellung

- Registrierung über /api/v1/webhook-endpoints (GET, POST, DELETE umgesetzt, PATCH geplant; Scope webhooks:manage) mit url (nur HTTPS, keine privaten, Link-Local- oder Loopback-Adressen, keine Redirects), events (Liste aus dem Katalog config/hub/webhooks.php), secret (vom Hub erzeugt, einmal angezeigt, verschlüsselt gespeichert).
- Outbox-Muster: Ereignis wird in derselben Transaktion wie die fachliche Änderung in webhook_outbox geschrieben; ein Worker stellt zu. Keine Doppelzustellung durch Unique (endpoint_id, outbox_id); Konsumenten müssen dennoch idempotent auf event_id sein.
- Retry: Backoff 30 s, 120 s, 600 s, 1800 s (config/hub/webhooks.php backoff_seconds), danach DLQ und Alarm an Administrator. Manuelle Wiederzustellung über das Kommando hub:webhooks:redeliver und die Admin-Oberfläche; POST /api/v1/webhook-deliveries/{id}/retry ist geplant.
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

Ereigniskatalog (Änderungsvermerk 12.09.2026, umgesetzt gemäß config/hub/webhooks.php events):

| Ereignis | Auslöser | Stand |
|---|---|---|
| contact.created, contact.updated | Spiegeländerung per CardDAV-Sync | umgesetzt |
| property.updated, unit.updated, contract.created, contract.terminated | Spiegel (CSV-Import) | umgesetzt |
| document.created | Neues Dokument im WebDAV-Spiegel | umgesetzt |
| invoice.created, open_item.created, open_item.paid | Phase 3 Importe | umgesetzt |
| case.created, case.updated | Hub-Vorgänge | umgesetzt |
| sync.failed, sync.stale | je fehlgeschlagenem SyncRun; Datenalter überschritten (hub:webhooks:emit-stale) | umgesetzt |
| document.updated, document.moved, document.deleted, contact.deleted, contact.merged, contact.merge_undone, property.created, property.deleted, unit.created, unit.deleted, contract.updated, contract.deleted, ownership.*, contact_role.*, transaction.imported, open_item.updated, conflict.opened, conflict.resolved, proposal.*, import.*, sync.run.finished, connection.*, export.overdue | Konzept | geplant |
| document.upload.queued, .sent, .succeeded, .skipped_exists, .failed, .failed_verify, .unknown | Statuswechsel in write_operations; Namen werden bei Umsetzung an WriteOperationStatus angepasst (pending, prechecked, sent, unknown, verified, failed, rejected) | geplant, bis dahin Statusabfrage über status_url |

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
| Verfügbarkeit des Upload-Endpunkts | write_enabled = false bis Freigabe der Geschäftsführung; Zulässigkeit automatisierter Nutzung WAITING_FOR_VENDOR_ACCESS; Stand 12.09.2026 kein Zugang am echten Mandanten getestet | Phase 2 |
| Als geplant gekennzeichnete Endpunkte (Abschnitte 3.2 bis 3.10, 4, 5.3) | nur Konzept, Aufruf liefert 404 | Umsetzung erst mit benanntem Konsumenten, Contract-Test gegen OpenAPI |
| Erster externer Konsument für API-Keys und Webhooks | nicht benannt | Phase 4, ohne Konsument keine Aktivierung |
| Domain und Hosting der API | festzulegen | Phase 1 |
| Kalenderressource /api/v1/calendar-events | CalDAV lesend, niedrige Priorität, Schreibrichtung nicht belegt | Phase 3 optional |
| Aggregationsumfang /api/v1/finance/summary | mit Steuerberater und Geschäftsführung abzustimmen | vor Phase 3 |
