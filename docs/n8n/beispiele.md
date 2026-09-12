# n8n-Beispielflows und Webhook-Payloads

Stand: 11.09.2026. Status: Entwurf für Phase 4. Alle Beispieldaten sind fiktive Platzhalter, keine Daten aus dem Bestand der Hausverwaltung Müller GmbH. Header, Signatur, Payload-Format und Ereignisnamen folgen verbindlich docs/immoware/09-api-documentation.md Abschnitt 5: Payloads enthalten nur IDs, Typ, Link, Herkunft und nicht personenbezogene Steuerfelder, keine Namen, Adressen, E-Mails, Telefonnummern oder Beträge. Fachliche Details lädt der Flow über die lesende Hub-API mit eigenem Key nach (Schritt 5 des Grundmusters). Die Ereignisse stammen aus dem Hub-Spiegel, nicht aus einer Immoware24-API (NICHT VERFÜGBAR).

Jeder Flow folgt demselben Grundmuster:

1. Webhook-Node (POST, Raw Body aktiviert).
2. Code-Node: Signatur und Zeitstempel prüfen, bei Fehler HTTP 401 antworten.
3. IF-Node: `event_id` bereits verarbeitet, dann HTTP 200 und Ende.
4. IF-Node: `source.status` gleich fresh, sonst Benachrichtigung an Operator und Ende.
5. HTTP-Request-Node: Details über `data.href` von der Hub-API laden (Authorization: Bearer API-Key aus Credentials, Scopes gemäß 08-security.md Abschnitt 5). Danach fachliche Verarbeitung.
6. `event_id` als verarbeitet speichern, HTTP 200 antworten.

## Signaturprüfung (Code-Node, JavaScript)

Änderungsvermerk 12.09.2026: Format an die Implementierung des Moduls Webhooks angepasst (`X-Hub-Signature: t=<unix>,v1=<hex>`). Vollständige, importierbare Fassung in `workflows/`.

```javascript
const crypto = require('crypto');
const secret = $env.HUB_WEBHOOK_SECRET; // aus n8n-Credentials, nie im Flow
const item = $input.first().json;
const headers = item.headers;
const binary = $input.first().binary;
const rawBody = binary && binary.data && binary.data.data
  ? Buffer.from(binary.data.data, 'base64').toString('utf8') // Raw Body im Webhook-Node aktivieren
  : JSON.stringify(item.body);
const header = headers['x-hub-signature'] || ''; // Format t=<unix>,v1=<hex>[,v1=<hex alt>]

let ts = null;
const candidates = [];
for (const part of header.split(',')) {
  const [k, v] = part.trim().split('=');
  if (k === 't' && /^\d+$/.test(v || '')) ts = Number(v);
  if (k === 'v1' && v) candidates.push(v.toLowerCase());
}
const now = Math.floor(Date.now() / 1000);
if (ts === null || Math.abs(now - ts) > 300) {
  throw new Error('Zeitstempel fehlt oder ausserhalb des Replay-Fensters');
}
const expected = Buffer.from(crypto.createHmac('sha256', secret).update(`${ts}.${rawBody}`).digest('hex'));
const valid = candidates.some(c => Buffer.from(c).length === expected.length && crypto.timingSafeEqual(Buffer.from(c), expected));
if (!valid) {
  throw new Error('Signatur ungueltig');
}
return [{ json: JSON.parse(rawBody) }];
```

## Flow 1: Neuer Mieter

Fachlicher Zweck: Willkommensschreiben vorbereiten (Entwurf), Aufgabe im Hub-Vorgang anlegen, Zählerstände anfordern. Kein Versand ohne Freigabe eines Mitarbeiters.

Quelle: CSV-Import der Auswertung Mieter- und Verwaltungseinheiten-Stammdaten (DOKUMENTIERT: "Alle aufgeführten Auswertungen lassen sich über den Export-Button in eine CSV-Datei exportieren."). Spaltenformat NICHT VERFÜGBAR, daher `identity_confidence` ausgeben.

Ereignistyp: `contact_role.created` mit `data.role = tenant`.

```json
{
  "event_id": "018f6b2a-6d3e-7c1a-9f2b-0a1b2c3d4e5f",
  "event": "contact_role.created",
  "occurred_at": "2026-09-11T08:15:30.000Z",
  "organization_id": "018f6b2a-0000-7c1a-9f2b-0a1b2c3d4e5f",
  "data": {
    "id": "018f6b2a-2222-7c1a-9f2b-0a1b2c3d4e5f",
    "type": "contact_role",
    "href": "/api/v1/contacts/018f6b2a-3333-7c1a-9f2b-0a1b2c3d4e5f/roles",
    "sync_version": 1,
    "role": "tenant",
    "identity_confidence": "exact",
    "contact_id": "018f6b2a-3333-7c1a-9f2b-0a1b2c3d4e5f",
    "unit_id": "018f6b2a-4444-7c1a-9f2b-0a1b2c3d4e5f",
    "property_id": "018f6b2a-5555-7c1a-9f2b-0a1b2c3d4e5f",
    "contract_id": "018f6b2a-6666-7c1a-9f2b-0a1b2c3d4e5f"
  },
  "source": {
    "connection_id": "018f6b2a-9999-7c1a-9f2b-0a1b2c3d4e5f",
    "access_path": "csv_export",
    "evidence_status": "DOKUMENTIERT",
    "sync_run_id": "018f6b2a-1111-7c1a-9f2b-0a1b2c3d4e5f",
    "detected_by": "hash",
    "status": "fresh",
    "data_age_seconds": 1830,
    "stale_since": null,
    "import": { "export_type": "auswertung_mieter_ve", "exported_at": "2026-09-11T07:45:00Z", "full_export": true, "format_version": 1 }
  }
}
```

Hinweise für den Flow:

- Name, Anschrift, E-Mail, Telefon und Mietbeträge sind nicht im Webhook enthalten. Der Flow lädt sie über GET /api/v1/contacts/{contact_id} (Scope contacts:read), GET /api/v1/units/{unit_id} (units:read) und GET /api/v1/contracts/{contract_id} (contracts:read). Beträge kommen als Cent-Ganzzahl; Anzeige im Format 1.120,00 EUR erfolgt im Flow.
- Bei `data.identity_confidence` ungleich exact keine automatische Aktion, sondern Hinweis an den Operator, weil der Mieter möglicherweise aus einer Schlüsselkollision stammt.
- Ob `contract_id` gefüllt ist, hängt davon ab, welche Spalten der CSV-Export tatsächlich enthält (zu verifizieren am eigenen Mandanten).

## Flow 2: Neues Dokument

Fachlicher Zweck: Benachrichtigung des zuständigen Sachbearbeiters, wenn im Posteingang ein neues Dokument erscheint, das nicht vom Hub selbst stammt (z. B. Scanner-Upload).

Quelle: WebDAV-Scan des Ordners Posteingang alle 30 Minuten (VERIFIZIERT: Posteingang ist per WebDAV erreichbar; Scanner laden per WebDAV in den Posteingang). Für den Posteingang gilt content_policy hash_on_change, daher ist `content_hash` in der Regel gefüllt.

Ereignistyp: `document.created`.

```json
{
  "event_id": "018f6b2b-7777-7c1a-9f2b-0a1b2c3d4e5f",
  "event": "document.created",
  "occurred_at": "2026-09-11T09:02:11.000Z",
  "organization_id": "018f6b2a-0000-7c1a-9f2b-0a1b2c3d4e5f",
  "data": {
    "id": "018f6b2b-9999-7c1a-9f2b-0a1b2c3d4e5f",
    "type": "document",
    "href": "/api/v1/documents/018f6b2b-9999-7c1a-9f2b-0a1b2c3d4e5f",
    "sync_version": 1,
    "folder_id": "018f6b2b-0001-7c1a-9f2b-0a1b2c3d4e5f",
    "origin": "remote",
    "content_hash_present": true
  },
  "source": {
    "connection_id": "018f6b2b-0002-7c1a-9f2b-0a1b2c3d4e5f",
    "access_path": "webdav",
    "evidence_status": "VERIFIZIERT",
    "sync_run_id": "018f6b2b-8888-7c1a-9f2b-0a1b2c3d4e5f",
    "detected_by": "etag",
    "status": "fresh",
    "data_age_seconds": 131,
    "stale_since": null
  }
}
```

Hinweise für den Flow:

- Für Dokumente mit origin = hub_upload sendet der Hub kein document.created, sondern document.upload.succeeded (Datenmodell Abschnitt C, Regel gegen Duplikate zwischen Lese- und Schreib-Connection); ein IF-Node auf `data.origin` ist damit nur eine zusätzliche Absicherung. Dateiname, Pfad, Größe und ETag können personenbezogene Anteile tragen und sind deshalb nicht im Webhook, sondern über GET data.href (Scope documents:read) abrufbar.
- Der Hub liefert keinen Dateiinhalt im Webhook. Abruf nur über die lesende Hub-API (Phase 4) mit Scope documents:read, sofern content_policy store aktiv ist, sonst ist der Inhalt nur in Immoware24 vorhanden.
- `assignments` sind Hub-Zuordnungen. Ob Immoware24 das Dokument im DMS einem Objekt zuordnet, ist nicht belegt und im Webhook nicht enthalten.

## Flow 3: Neuer Eigentümer

Fachlicher Zweck: Eigentümerwechsel im WEG-Objekt erkennen, Checkliste im Hub-Vorgang anlegen (Verwalterzustimmung, Beschlusssammlung, Hausgeldkonto), Begrüßungsschreiben als Entwurf vorbereiten.

Quelle: CSV-Import einer Auswertung mit Eigentümer- und VE-Daten (DOKUMENTIERT für Auswertungen allgemein; welche Auswertung Eigentümerdaten mit MEA enthält, ist zu verifizieren am eigenen Mandanten).

Ereignistyp: `contact_role.created` mit `data.role = owner`, ergänzt um `ownership`.

```json
{
  "event_id": "018f6b2c-aaaa-7c1a-9f2b-0a1b2c3d4e5f",
  "event": "contact_role.created",
  "occurred_at": "2026-09-11T10:30:00.000Z",
  "organization_id": "018f6b2a-0000-7c1a-9f2b-0a1b2c3d4e5f",
  "data": {
    "id": "018f6b2c-cccc-7c1a-9f2b-0a1b2c3d4e5f",
    "type": "contact_role",
    "href": "/api/v1/contacts/018f6b2c-dddd-7c1a-9f2b-0a1b2c3d4e5f/roles",
    "sync_version": 1,
    "role": "owner",
    "identity_confidence": "exact",
    "contact_id": "018f6b2c-dddd-7c1a-9f2b-0a1b2c3d4e5f",
    "unit_id": "018f6b2c-eeee-7c1a-9f2b-0a1b2c3d4e5f",
    "property_id": "018f6b2c-ffff-7c1a-9f2b-0a1b2c3d4e5f",
    "ownership_id": "018f6b2d-0000-7c1a-9f2b-0a1b2c3d4e5f",
    "previous_owner_role": { "id": "018f6b2d-1111-7c1a-9f2b-0a1b2c3d4e5f", "status": "missing_since_set" }
  },
  "source": {
    "connection_id": "018f6b2c-0003-7c1a-9f2b-0a1b2c3d4e5f",
    "access_path": "csv_export",
    "evidence_status": "DOKUMENTIERT",
    "sync_run_id": "018f6b2c-bbbb-7c1a-9f2b-0a1b2c3d4e5f",
    "detected_by": "hash",
    "status": "fresh",
    "data_age_seconds": 900,
    "stale_since": null,
    "import": { "export_type": "auswertung_eigentuemer_ve", "exported_at": "2026-09-11T10:15:00Z", "full_export": true, "format_version": 1 }
  }
}
```

Hinweise für den Flow:

- `data.previous_owner_role.status = missing_since_set` bedeutet: Der Vorbesitzer fehlt im aktuellen Vollexport erstmals. Soft Delete erfolgt erst nach dem zweiten Vollexport ohne Treffer. Der Flow soll den Wechsel daher als "vorläufig" behandeln.
- Firmenname, Anschrift, MEA und Hausgeld werden über GET /api/v1/contacts/{contact_id} (contacts:read), GET /api/v1/units/{unit_id} (units:read) und GET /api/v1/ownerships (ownerships:read) nachgeladen. Adressen liegen dort im Format des Spiegels (street, extended, postal_code, city, region, country, label; keine getrennte Hausnummer, 03-field-mapping.md Abschnitt 1.2).
- Bankverbindungen (bank_accounts) werden in Webhooks nicht ausgegeben. Nur iban_last4 kann bei Bedarf über die lesende API mit Scope finance:read abgerufen werden.

## Flow 4: Offener Posten

Fachlicher Zweck: Überfällige Mietforderungen erkennen und einen Mahnvorschlag als Hub-Vorgang anlegen. Kein Versand einer Mahnung aus n8n; Mahnungen sind Kündigungsvorstufen und erfordern Prüfung durch einen Mitarbeiter und bei Eskalation Freigabe der Geschäftsführung.

Quelle: CSV-Import der Liste offener Posten (DOKUMENTIERT: Listen offener Posten für Rechnungen und Mietforderungen existieren in der UI; CSV-Export der Auswertungen über Export-Button). Ob die OP-Liste selbst über den Export-Button verfügbar ist, ist zu verifizieren am eigenen Mandanten.

Ereignistyp: `open_item.created` (neuer Stichtag) bzw. `open_item.updated`.

```json
{
  "event_id": "018f6b2e-2222-7c1a-9f2b-0a1b2c3d4e5f",
  "event": "open_item.created",
  "occurred_at": "2026-09-11T11:00:00.000Z",
  "organization_id": "018f6b2a-0000-7c1a-9f2b-0a1b2c3d4e5f",
  "data": {
    "id": "018f6b2e-4444-7c1a-9f2b-0a1b2c3d4e5f",
    "type": "open_item",
    "href": "/api/v1/open-items?filter[unit_id]=018f6b2a-4444-7c1a-9f2b-0a1b2c3d4e5f&filter[as_of_date]=2026-09-11",
    "sync_version": 1,
    "kind": "receivable_rent",
    "as_of_date": "2026-09-11",
    "days_overdue": 8,
    "identity_confidence": "derived",
    "contact_id": "018f6b2a-3333-7c1a-9f2b-0a1b2c3d4e5f",
    "unit_id": "018f6b2a-4444-7c1a-9f2b-0a1b2c3d4e5f",
    "property_id": "018f6b2a-5555-7c1a-9f2b-0a1b2c3d4e5f"
  },
  "source": {
    "connection_id": "018f6b2e-0004-7c1a-9f2b-0a1b2c3d4e5f",
    "access_path": "csv_export",
    "evidence_status": "DOKUMENTIERT",
    "sync_run_id": "018f6b2e-3333-7c1a-9f2b-0a1b2c3d4e5f",
    "detected_by": "hash",
    "status": "fresh",
    "data_age_seconds": 600,
    "stale_since": null,
    "import": { "export_type": "op_liste", "exported_at": "2026-09-11T10:50:00Z", "full_export": true, "as_of_date": "2026-09-11", "format_version": 1 }
  }
}
```

Hinweise für den Flow:

- Offene Posten sind Stichtagsdaten (`as_of_date`). Ein Posten, der im nächsten Export fehlt, ist nicht automatisch bezahlt; der Hub meldet dazu kein Ereignis, bevor der Import als Vollexport gekennzeichnet war.
- `days_overdue` berechnet der Hub aus `as_of_date` minus `due_date`, nicht aus dem aktuellen Datum, damit das Ergebnis dem Exportstand entspricht. Beträge (amount_cents, open_cents) und Fälligkeit sind nicht im Webhook; der Flow lädt sie über data.href mit Scope finance:read (Schutzstufe hoch, 08-security.md Abschnitt 7.5).
- `identity_confidence = derived` ist bei OP-Zeilen wahrscheinlich, weil DATEV- und OP-Zeilen keine garantiert eindeutige Kennung tragen. Der Flow soll den Vorgang als Vorschlag anlegen, nicht als Tatsache.
- Ein Mahnschreiben entsteht ausschließlich als Entwurf und wird nie automatisch versendet.

## Rückkanal: Dokument in den Posteingang einreichen (nur bei freigegebenem Schreibpfad)

Der einzige Schreibweg aus n8n ist ein HTTP-Request an den Hub (nicht an Immoware24) mit Scope documents:write (Änderungsvermerk 12.09.2026: der Code verwendet documents:write, nicht documents:create). Der Hub führt die Operation nach Abschnitt 6 der Architekturentscheidung aus.

Anfrage (Entwurf, einziger Schreibendpunkt gemäß 09-api-documentation.md Abschnitt 3.5):

```
POST /api/v1/documents
Authorization: Bearer <api_key>
Idempotency-Key: <UUID, Pflicht; der Hub bildet daraus zusammen mit connection_id und content_hash den Idempotenzschlüssel>
Content-Type: multipart/form-data

file=<Binärinhalt, Pflicht>
filename=Rechnung_Hausmeister_2026-09.pdf   (Pflicht)
case_id=                                     (optional)
source_document_id=                          (optional)
note=Eingangsrechnung aus Mailpostfach       (optional)
```

Es gibt kein Feld target_folder: Der Zielpfad wird vom Hub aus allowed_write_prefix der Schreib-Connection und dem sanitisierten Dateinamen mit UUIDv7-Suffix gebildet; Unterordner werden nicht angesteuert (05-write-capabilities.md Abschnitt 3.2). Ein Antrag aus n8n (requested_via = api_key) wird erst nach menschlicher Freigabe im Hub ausgeführt (outcome pending_approval, Freigabe über Admin-Oberfläche oder Kommando hub:write:approve); der Fortsetzungspfad für pending-Anträge wird im Fix-Lauf 12.09.2026 ergänzt, ein API-Endpunkt approve ist geplant und noch nicht umgesetzt (Änderungsvermerk 12.09.2026).

Antwort 202 mit operation_uuid, upload_id, status (pending, prechecked, sent, unknown, verified, failed, rejected gemäß App\Core\Enums\WriteOperationStatus), outcome, approval_required und status_url; Abfrage über GET /api/v1/documents/uploads/{operation_uuid} (Header Location der 202-Antwort). Ein Status unknown wird vom Hub ausschließlich lesend über PROPFIND aufgelöst, niemals durch ein zweites PUT. n8n soll bei unknown nicht erneut einreichen, sondern die status_url weiter abfragen (Webhook-Ereignisse für Uploads sind geplant, 09 Abschnitt 5.3). Solange write_enabled = false ist, antwortet der Endpunkt mit 403 write_disabled.

Voraussetzungen: DAV-Modul gebucht, technischer Schreibnutzer angelegt, Probe mit If-None-Match bestanden, schriftliche Bestätigung des Immoware24-Supports, Freigabe der Geschäftsführung. Alle Punkte derzeit offen (WAITING_FOR_VENDOR_ACCESS).
