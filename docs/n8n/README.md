# n8n-Anbindung an den Immoware Hub

Stand: 12.09.2026. Status: Hub-Seite (Modul Webhooks, Outbox, HMAC-Signatur, Endpunktverwaltung über /api/v1/webhook-endpoints) ist im Code vorhanden und getestet, standardmäßig deaktiviert (HUB_WEBHOOKS_ENABLED=false). Aktivierung nur bei benanntem Konsumenten mit Freigabe der Geschäftsführung. Importierbare Beispiel-Workflows liegen unter `workflows/`.

Änderungsvermerk 12.09.2026: Das Signaturformat wurde an die Implementierung des Moduls Webhooks angepasst. Der Header `X-Hub-Signature` enthält den Zeitstempel als eigenen Bestandteil (`t=<unix>,v1=<hex>`), zusätzlich sendet der Hub weiterhin `X-Hub-Timestamp`. Die frühere Kurzform `v1=<hex>` ohne `t=` in diesem Dokument und in beispiele.md war unvollständig. Bis zur Anpassung von 09-api-documentation.md Abschnitt 5.2 gilt für Konsumenten die Implementierung (`app/Modules/Webhooks/Services/WebhookSigner.php`, `app/Modules/Webhooks/Jobs/DeliverWebhookJob.php`).

## Einordnung

n8n spricht ausschließlich mit dem Immoware Hub, niemals direkt mit Immoware24.

Belegstand zu Immoware24:

- Ein n8n-Community-Node für Immoware24 existiert nicht. Die npm-Registry liefert für "immoware24" 0 Treffer (geprüft 11.09.2026, https://registry.npmjs.org/-/v1/search?text=immoware24). Status: NICHT VERFÜGBAR (nur für n8n belastbar).
- Zapier- und Make-App-Verzeichnisse waren aus der Rechercheumgebung nicht abrufbar; für sie gilt VERMUTET (nicht auffindbar), manuelle Prüfung im Browser steht aus.
- Eine REST-API, Webhooks oder API-Keys von Immoware24 sind NICHT VERFÜGBAR (Negativbefund, keine offizielle Aussage).

Daraus folgt: Jeder n8n-Flow konsumiert Ereignisse, die der Hub aus seinem Spiegel ableitet. Die Ereignisse entstehen durch Hub-Sync-Läufe (WebDAV, CardDAV, CalDAV) oder durch manuelle Importe (CSV-Auswertungen, DATEV, CAMT). Das Datenalter ist deshalb Teil jeder Nutzlast.

## Was der Hub liefern wird

Modul Outbound (Architekturentscheidung, Abschnitt 7):

- Ausgehende Webhooks je registriertem Endpunkt (Tabelle webhook_endpoints), signiert mit HMAC-SHA256 über Body plus Zeitstempel. Verbindliche Spezifikation von Headern, Signatur, Payload-Format und Ereignisnamen ist docs/immoware/09-api-documentation.md Abschnitt 5; dieses Dokument und beispiele.md wiederholen sie nur.
- Replay-Fenster 5 Minuten. Zustellung über webhook_outbox und webhook_deliveries mit Retry und Backoff.
- Aktivierung erst, wenn ein konkreter Konsument benannt und von der Geschäftsführung freigegeben ist. In Phase 1 bis 3 ist das Modul vorhanden, aber inaktiv (HUB_WEBHOOKS_ENABLED=false).
- Optional in Phase 4 eine lesende API mit API-Keys (Argon2id, Scopes wie documents:read, contacts:read, units:read). In Phase 1 werden keine Keys ausgegeben.

Was der Hub nicht liefern wird:

- Keine Schreibaktion nach Immoware24 aus n8n heraus, mit einer Ausnahme: das Einreichen eines Dokuments in den Posteingang über den Hub-Schreibpfad (Scope documents:create), sofern write_enabled für die Connection freigegeben ist. Auch dann läuft jede Anfrage durch Idempotenzschlüssel, Precheck, PUT mit If-None-Match: * und Verifikation.
- Kein Löschen, kein Überschreiben, kein Schreiben von Kontakten, Terminen oder Stammdaten. Änderungswünsche werden als proposed_change im Hub erfasst und manuell in Immoware24 umgesetzt.

## Sicherheitsanforderungen an einen n8n-Flow

1. Signatur prüfen. Header `X-Hub-Signature` hat das Format `t=<unix-sekunden>,v1=<hex>`; bei Secret-Rotation folgt ein weiterer Eintrag `,v1=<hex>` für das vorherige Secret, es genügt ein Treffer. Berechnung: HMAC-SHA256 über `"{t}.{roher Body}"` mit dem Endpunkt-Secret, hex-kodiert. Vergleich zeitkonstant. Der rohe Body muss byteidentisch verwendet werden (Webhook-Node mit Raw Body, Inhalt aus `binary.data`).
2. Zeitstempel prüfen. `t` aus der Signatur (identisch mit `X-Hub-Timestamp`, Unix-Sekunden) darf höchstens 300 Sekunden von der eigenen Uhr abweichen (`hub.webhooks.replay_window_seconds`).
3. Ereignis-ID deduplizieren. `event_id` (UUID des Outbox-Eintrags, auch im Header X-Hub-Event-Id) je Ereignis; n8n speichert verarbeitete IDs (z. B. in einer Datenbank-Tabelle oder Static Data) und ignoriert Wiederholungen.
4. Datenalter beachten. `source.status` kann fresh, stale oder degraded sein. Flows, die Aktionen auslösen (z. B. E-Mail an Mieter), sollen bei stale oder degraded stoppen oder eine Rückfrage erzeugen.
5. Keine Weitergabe personenbezogener Daten an Dienste ohne AV-Vertrag. Webhook-Payloads enthalten keine personenbezogenen Feldwerte und keine Beträge, nur IDs, Typ, Link, Herkunft und Steuerfelder (09-api-documentation.md Abschnitt 5.3). Namen, Adressen, E-Mails, Telefonnummern und Beträge holt der Flow bei Bedarf über die lesende Hub-API mit eigenem Key und passenden Scopes (Phase 4).
6. Endpunkt-Secret und Hub-URL ausschließlich als n8n-Credentials, nie in Flow-Parametern.

## Header jeder Zustellung

Stand der Implementierung (DeliverWebhookJob, 12.09.2026):

| Header | Inhalt |
|---|---|
| Content-Type | application/json |
| User-Agent | ImmowareHub-Webhooks/1.0 |
| X-Hub-Event | Ereignisname, z. B. contract.created |
| X-Hub-Event-Id | UUID des Outbox-Eintrags (event_id, Grundlage der Deduplizierung) |
| X-Hub-Delivery | UUID der Zustellung (je Endpunkt und Versuch stabil) |
| X-Hub-Timestamp | Unix-Sekunden, identisch mit t in der Signatur |
| X-Hub-Signature | t=<unix>,v1=<hex HMAC-SHA256>, bei Rotation zusätzlich ,v1=<hex alt> |

Ein Header für die Versuchszählung wird derzeit nicht gesendet; die Zahl der Versuche steht nur in webhook_deliveries.

## Gemeinsamer Nutzlast-Rahmen

```json
{
  "event_id": "018f6b2a-6d3e-7c1a-9f2b-0a1b2c3d4e5f",
  "event": "contact_role.created",
  "occurred_at": "2026-09-11T08:15:30.000Z",
  "organization_id": "018f6b2a-0000-7c1a-9f2b-0a1b2c3d4e5f",
  "data": { "id": "018f6b2a-2222-7c1a-9f2b-0a1b2c3d4e5f", "type": "contact_role", "href": "/api/v1/contacts/018f6b2a-3333-7c1a-9f2b-0a1b2c3d4e5f/roles", "sync_version": 1 },
  "source": {
    "connection_id": "018f6b2a-9999-7c1a-9f2b-0a1b2c3d4e5f",
    "access_path": "csv_export",
    "evidence_status": "DOKUMENTIERT",
    "sync_run_id": "018f6b2a-1111-7c1a-9f2b-0a1b2c3d4e5f",
    "detected_by": "hash",
    "status": "fresh",
    "data_age_seconds": 1830,
    "stale_since": null
  }
}
```

Das Format entspricht 09-api-documentation.md Abschnitt 5.3 (Feld `event`, nicht `event_type`). `data` trägt nur id, type, href, sync_version und nicht personenbezogene Steuerfelder (z. B. role, identity_confidence, as_of_date, status). Alle fachlichen Details werden über href mit dem eigenen API-Key nachgeladen.

Feldbedeutungen:

- `source.evidence_status`: Belegstatus des Zugangswegs, über den der Datensatz in den Hub kam (VERIFIZIERT, DOKUMENTIERT, VERMUTET). Werte unter DOKUMENTIERT werden nicht als Ereignis versandt.
- `source.detected_by`: Erkennungsgrundlage aus sync_events (etag, ctag, sync_token, lastmodified, size, hash, missing, manual).
- `source.status`: fresh, stale oder degraded; `source.data_age_seconds` und `source.stale_since` wie in meta.source der API.
- `source.data_age_seconds`: Sekunden seit dem letzten erfolgreichen Sync bzw. Import dieser Connection.
- `data.sync_version`: Version des Datensatzes im Hub; Versionen entstehen nur bei Checksum-Änderung.

Beispiele für die vier Kernflows stehen in `beispiele.md`. Importierbare Workflows liegen unter `workflows/`.

## Importierbare Workflows

Verzeichnis `docs/n8n/workflows/`, Import in n8n über Workflows, Import from File. Jeder Workflow hat denselben Aufbau: Webhook-Node (POST, Raw Body), Code-Node HMAC-Prüfung (Format t=..,v1=.., Replay-Fenster 300 s), Code-Node Deduplizierung über event_id, IF-Node source.status = fresh, HTTP-Request-Node zum Nachladen über data.href mit API-Key-Credential, danach vier Platzhalter-Ziele (NoOp-Nodes): Google Workspace, Google Drive, Telefonbuch/CRM, Reporting.

| Datei | Ereignis | Zusatz |
|---|---|---|
| 01-neuer-mieter-contract-created.json | contract.created | Vertrag über GET /api/v1/contracts/{id} (contracts:read) |
| 02-neues-dokument-document-created.json | document.created | Metadaten über GET /api/v1/documents/{id} (documents:read) |
| 03-neuer-eigentuemer-contact-updated.json | contact.updated | IF-Node data.role = owner, Kontakt über GET /api/v1/contacts/{id} (contacts:read) |
| 04-offener-posten-open-item-created.json | open_item.created | Posten über GET /api/v1/open-items/{id} (finance:read) |

Voraussetzungen im n8n: Umgebungsvariablen oder Credentials `HUB_WEBHOOK_SECRET` (Endpunkt-Secret aus der Registrierung) und `HUB_BASE_URL` (https://immoware.muellerhv.de), ein Header-Auth-Credential `Authorization: Bearer <API-Key>` mit den genannten Scopes. Die Platzhalter-Ziele sind durch echte Nodes zu ersetzen, sobald AV-Verträge und Freigaben vorliegen. Alle Ereignisnamen entsprechen `config/hub/webhooks.php` (events).

Hinweis zum Ereignis Neuer Eigentümer: Die Ereignisliste des Moduls Webhooks kennt derzeit `contact.updated`, nicht `contact_role.created`. Der Workflow filtert deshalb auf `data.role = owner`. Ob der Sync dieses Steuerfeld bei Kontaktänderungen aus CSV-Importen setzt, ist bei Aktivierung zu prüfen.

## Späterer Community-Node

Ein n8n-Community-Node "n8n-nodes-immoware-hub" ist vorgesehen, aber nicht gebaut. Er würde den Webhook-Trigger (inklusive HMAC-Prüfung und Deduplizierung) und die Hub-API-Aufrufe (Credential mit API-Key, Ressourcen properties, units, contacts, contracts, documents, cases) kapseln, so dass die Code-Nodes aus den Beispielen entfallen. Bis dahin sind die Beispiel-Workflows die Referenz. Der Node spricht ausschließlich mit dem Hub, nie mit Immoware24; ein Community-Node für Immoware24 selbst existiert nicht (NICHT VERFÜGBAR).

## Geplante Ereignisnamen (Phase 4, Entwurf)

Namen gemäß 09-api-documentation.md Abschnitt 5.3 (verbindliche Liste dort).

| Ereignisname | Quelle im Hub | Auslöser |
|---|---|---|
| contact_role.created (data.role = tenant) | CSV-Import Auswertung Mieter und VE, optional CardDAV | Neuer Mieter |
| contact_role.created (data.role = owner) | CSV-Import Auswertung Eigentümer und VE | Neuer Eigentümer |
| document.created | WebDAV-Scan Posteingang oder Dokumente (nicht für origin hub_upload) | Neues Dokument |
| open_item.created, open_item.updated | CSV-Import OP-Liste | Offener Posten je Stichtag |
| contact.updated | CardDAV, CSV | Kontaktänderung |
| unit.updated | CSV | Stammdatenänderung |
| document.upload.queued, document.upload.sent, document.upload.succeeded, document.upload.skipped_exists, document.upload.failed, document.upload.failed_verify, document.upload.unknown | WriteOperationService | Upload-Ergebnis |
| connection.degraded, connection.restored | Probe, Health-Check | Betriebszustand |

## Offene Punkte

- Konkreter n8n-Konsument und Zweck: nicht benannt. Ohne benannten Konsumenten wird das Outbound-Modul nicht aktiviert.
- Spaltenformate der CSV-Exporte (Grundlage für tenant- und owner-Ereignisse): zu verifizieren am eigenen Mandanten (Phase 0).
- Ob der DMS-Posteingang hochgeladene Dateien automatisch Objekten zuordnet: NICHT belegt, Test in Phase 2.
- Hosting des n8n-Servers: EU-Standort und AV-Vertrag erforderlich, wenn personenbezogene Daten verarbeitet werden.
