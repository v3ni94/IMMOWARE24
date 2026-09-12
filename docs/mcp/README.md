# KI- und MCP-Vorbereitung des Immoware Hub

Stand: 12.09.2026. Status: Tool-Katalog und Aufruf-Endpunkt im Modul Mcp vorhanden und getestet. Ein MCP-Server im Sinne des Protokolls (stdio) ist nicht Teil des Repositories; er wird erst gebaut, wenn der Schreibpfad produktiv und stabil ist (Architekturentscheidung, Einleitung, und 08-security.md Abschnitt 8). Kein KI-Assistent ist produktiv angebunden.

Verbindliche Grundlage: `docs/immoware/08-security.md` Abschnitt 8 (KI/MCP-Permission-Layer), `docs/immoware/09-api-documentation.md` (Hub-API v1). Dieses Dokument beschreibt nur die technische Vorbereitung.

## 1. Architektur

```
KI-Assistent (Claude, anderer LLM-Client)
   |  MCP (stdio), Tool-Aufrufe
   v
MCP-Server (eigener Prozess, dünner Client, eigener API-Key, kein Datenbankzugang)
   |  HTTPS, Authorization: Bearer hub_live_..., POST /api/v1/mcp/tools und /api/v1/mcp/call
   v
Immoware Hub, Modul Mcp
   Permission Layer: Katalogprüfung, never_autonomous-Sperre, Scope des Endpunkts, Scope mcp:write, JSON-Schema, Audit (Quelle mcp)
   |  interner Sub-Request durch den Router, Bearer-Token unverändert weitergereicht
   v
Hub-API v1 (Module Api, Webhooks): api.auth, api.scope, api.throttle, api.idempotency, FormRequests, Controller, Services
   |
   v
Spiegeltabellen des Hubs (properties, units, contacts, contracts, documents, cases, proposed_changes)
   |  nur Sync-Jobs und Importe, nie ein Tool
   v
Immoware24 (WebDAV, CardDAV, CalDAV lesend; create-only PUT in den Posteingang als einziger Schreibpfad)
```

Regeln, die der Aufbau technisch durchsetzt:

1. Ein Tool erreicht Immoware24 nie. Jeder Aufruf endet an der Hub-API; Daten sind Spiegeldaten mit `provenance`, `meta.source`, `data_age_seconds` und Belegstatus.
2. Ein Tool kann nie mehr als der API-Key. Der Sub-Request läuft mit demselben Bearer-Token durch dieselben Route-Middlewares wie ein externer Aufruf. Zusätzlich prüft der ToolExecutor die Scopes vorab und antwortet mit einer klaren Fehlermeldung.
3. Write-Tools verändern nur Hub-Zustand (Vorgang, Änderungsvorschlag). Sie verlangen neben dem fachlichen Scope (`cases:write`, `contacts:write`) ausdrücklich den Scope `mcp:write`. Der Scope `admin` ersetzt `mcp:write` nicht.
4. Handlungen der Spalte "nie autonom" aus 08-security.md sind im Katalog als `never_autonomous` gelistet, ohne Endpunkt und ohne Implementierung. Ein Aufruf liefert 403 `never_autonomous` mit Begründung. Aktuell: `immoware_change_bank_account`, `immoware_delete_document`.
5. Jeder Write-Tool-Aufruf erzeugt einen append-only Audit-Eintrag `mcp.tool.called` mit Quelle `mcp` (zusätzlich zum Audit des aufgerufenen Endpunkts). Read-Tools werden mit `HUB_MCP_AUDIT_READS=true` ebenfalls auditiert.
6. Der Agent erhält keine Immoware24-Zugangsdaten und keinen Datenbankzugang. Er kennt ausschließlich Hub-URL und einen API-Key mit minimalen Scopes.

## 2. Endpunkte

Beide Endpunkte liegen unter der Hub-API und verlangen einen gültigen API-Key (401 ohne Key). Rate Limit wie Lesezugriffe (`HUB_API_RATE_LIMIT_READ`).

| Endpunkt | Zweck | Antwort |
|---|---|---|
| `POST /api/v1/mcp/tools` | Tool-Katalog mit JSON-Schema, Hub-API-Zuordnung, Scopes, Klassifikation, `callable_with_current_key` | `{"data": {"tools": [...]}, "meta": {"total": n}}` |
| `POST /api/v1/mcp/call` | Tool ausführen. Body `{"tool": "immoware_get_property", "arguments": {"id": 12}, "idempotency_key": "optional"}` | `{"data": {"tool", "class", "effect", "hub_api": {"method", "path", "status"}, "result": <Antwort der Hub-API>, "note"}}` |

Fehler kommen als `application/problem+json` (RFC 7807):

| Status | code | Bedeutung |
|---|---|---|
| 400 | bad_request | `tool` fehlt oder `arguments` ist kein Objekt |
| 403 | insufficient_scope | fachlicher Scope des Endpunkts fehlt (`missing_scopes`) |
| 403 | mcp_write_scope_required | Write-Tool ohne `mcp:write` |
| 403 | never_autonomous | Tool ist nur durch einen Menschen zulässig |
| 404 | unknown_tool | Tool nicht im Katalog |
| 422 | validation_failed | Argumente verletzen das Schema (`errors[]`) |

Antworten der Hub-API (auch deren Fehler wie 404, 422, 403 `write_disabled`) stehen unverändert in `data.result`, der HTTP-Status der Hub-API in `data.hub_api.status`. Der äußere Aufruf antwortet dann trotzdem 200, damit der Assistent den Fehler lesen und erklären kann.

`idempotency_key` gilt nur für Write-Tools und wird als `Idempotency-Key` an die Hub-API gereicht. Ohne Angabe erzeugt der Hub je Aufruf einen neuen Schlüssel; ein Retry des Assistenten würde dann einen zweiten Vorgang anlegen. Ein MCP-Server soll deshalb je Tool-Aufruf des Modells einen stabilen Schlüssel bilden.

## 3. Tool-Katalog

Quelle: `app/Modules/Mcp/Support/ToolCatalog.php`. Export: `php artisan hub:mcp:export` schreibt `docs/mcp/tools.json`.

| Tool | Klasse | Hub-API | Scope |
|---|---|---|---|
| immoware_search_contacts | read | GET /api/v1/contacts | contacts:read |
| immoware_get_contact | read | GET /api/v1/contacts/{id} | contacts:read |
| immoware_search_properties | read | GET /api/v1/properties | properties:read |
| immoware_get_property | read | GET /api/v1/properties/{id} | properties:read |
| immoware_search_units | read | GET /api/v1/units | units:read |
| immoware_get_unit | read | GET /api/v1/units/{id} | units:read |
| immoware_get_contract | read | GET /api/v1/contracts/{id} | contracts:read |
| immoware_search_documents | read | GET /api/v1/documents | documents:read |
| immoware_create_case | write | POST /api/v1/cases | cases:write und mcp:write |
| immoware_propose_contact_change | write | PATCH /api/v1/contacts/{id} | contacts:write und mcp:write |
| immoware_change_bank_account | never_autonomous | keiner | keiner, nicht implementiert |
| immoware_delete_document | never_autonomous | keiner | keiner, nicht implementiert |

Nicht im Katalog, bewusst: Upload in den Posteingang (einziger Schreibpfad ins Livesystem), Finanzdaten (Einzelumsätze, IBAN), Sync-Steuerung, Konflikt- und Merge-Entscheidungen, Nutzer- und Key-Verwaltung, Versand von E-Mails oder Briefen. Erweiterungen des Katalogs erfordern eine Änderung von 08-security.md Abschnitt 8 und Freigabe der Geschäftsführung.

Such-Tools begrenzen `per_page` auf 50 (`hub.mcp.max_per_page`), damit Antworten in das Kontextfenster eines Assistenten passen.

## 4. Betrieb eines späteren MCP-Servers (stdio)

Der MCP-Server ist ein dünner Client der beiden Endpunkte, kein zweites Backend.

- Eigener Prozess auf einem Rechner oder Container ohne Zugriff auf Datenbank, Redis oder DAV-Zugangsdaten des Hubs. Konfiguration ausschließlich `HUB_BASE_URL` und `HUB_API_KEY` (Secret-Store oder Umgebungsvariable, nie in Dateien im Repository).
- Start: liest den Katalog über `POST /api/v1/mcp/tools` und meldet dem Client nur Tools mit `callable_with_current_key = true` und `implemented = true`. `never_autonomous`-Tools werden dem Modell nicht angeboten (08-security.md: Tool-Definitionen enthalten keine Werkzeuge der Spalte "nie autonom").
- `tools/call`: reicht `name` und `arguments` unverändert an `POST /api/v1/mcp/call` weiter, bildet je Modellaufruf einen stabilen `idempotency_key` (z. B. Hash aus Konversations-ID, Tool und Argumenten) und gibt `data.result` als Textinhalt zurück, einschließlich `meta.source` und `provenance`, damit das Modell Datenalter und Herkunft nennt.
- Prompt-Injection-Schutz: Inhalte aus Immoware24 (Dateinamen, Kontaktfelder, Vorgangstitel) werden dem Modell als Daten gekennzeichnet zurückgegeben. Der Server führt keine Anweisungen aus, die in Daten stehen, und ruft keine Tools ohne Modellanforderung auf.
- API-Key des Servers: minimale Read-Scopes, `mcp:write` nur nach Freigabe der Geschäftsführung und nur mit den fachlichen Write-Scopes `cases:write` oder `contacts:write`. Laufzeit höchstens 12 Monate, IP-Allowlist des Servers, Rotation wie in 08-security.md Abschnitt 5.
- Ausgaben des Assistenten an Dritte sind Entwürfe. Der Hub versendet nichts.
- Datenschutz: EU-Verarbeitung, Auftragsverarbeitungsvertrag mit dem KI-Anbieter, kein Training mit HVM-Daten. Ohne diese Voraussetzungen wird kein Key ausgegeben.

Anlegen eines Keys für den Server (Beispiel):

```
php artisan hub:api-key:create "MCP-Server Assistenz" --scopes=properties:read,units:read,contacts:read,contracts:read,documents:read --organization=<id>
```

## 5. Tests

`php artisan test --filter=Mcp` deckt ab: Katalog vollständig mit Schema und Klassifikation, Katalog ohne Key 401, Aufruf ohne fachlichen Scope 403, Read-Tool liefert Daten über die Hub-API (Mandantentrennung, Provenance), Argumentvalidierung 422, unbekanntes Tool 404, Write-Tool ohne mcp:write 403 (auch mit admin), Write-Tool legt Vorgang an und erzeugt Audit-Eintrag mit Quelle mcp, Idempotenz des Write-Tools, Änderungsvorschlag ohne Writeback, never_autonomous 403 mit klarer Meldung, Export-Kommando.

## 6. Offene Punkte

- MCP-Server (stdio) selbst: nicht gebaut. Entscheidung gemäß Implementierungsplan Phase 14 (AP 14.4) nur bei konkretem Bedarf.
- Scope `mcp:write` wird vom Modul Mcp zur Laufzeit in `hub.security.api_keys.scopes` ergänzt; eine Aufnahme in `config/hub/security.php` und in 08-security.md Abschnitt 5 steht aus (Änderungsvermerk 12.09.2026, Datei liegt außerhalb dieses Moduls).
- OpenAPI-Dokument (`docs/api/openapi.json`) kennt die Pfade `/api/v1/mcp/*` noch nicht.
- Dokumentinhalt (Download) ist absichtlich nicht als Tool vorhanden; 08-security.md sieht dafür `documents:download` mit Zweckangabe vor, der Scope existiert noch nicht.
