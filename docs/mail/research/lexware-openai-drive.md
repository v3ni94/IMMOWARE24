# Recherche Fremd-APIs: Lexware Office, OpenAI, Google Drive

Stand: 12.09.2026. Modul Mail und Vorgangsbearbeitung (https://mail.muellerhv.de), Hausverwaltung Müller GmbH.

## Methodik und Verbindlichkeit

Die offiziellen Dokumentationshosts (developers.lexware.io, developers.openai.com, developers.google.com) sind in dieser Umgebung per WebFetch gesperrt. Alle Aussagen stammen aus WebSearch-Snippets, teils aus Drittquellen (Blogs, Community-Clients, Wikipedia). Jede Aussage trägt einen Status:

| Status | Bedeutung |
|---|---|
| belegt (Snippet, Originalquelle) | Snippet stammt erkennbar von der offiziellen Doku |
| belegt (Snippet, Drittquelle) | Snippet stammt aus Blog, SDK-Doku oder Community, Original zu prüfen |
| nicht belegt | In den Snippets nicht gefunden, Annahme oder offene Frage |

Grundsatz: Alle Angaben zu Fremd-APIs sind aus Snippets und vor Implementierung am Original zu prüfen. Preise werden nur wiedergegeben, wo ein Snippet sie nennt, und sind nicht Planungsgrundlage. Kein Live-Test ist in dieser Umgebung möglich (keine Zugangsdaten, Hosts gesperrt). Http::fake-Tests belegen ausschließlich unser eigenes Verhalten gegen ein angenommenes Antwortformat, nie die Fremd-API.

---

## 1. Lexware Office Public API (ehemals lexoffice)

### 1.1 Basis-URL, Authentifizierung, Header

| Aussage | Status | Quelle |
|---|---|---|
| Basis-URL `https://api.lexware.io/v1`; kanonisches Gateway seit 26.05.2025 nach Rebranding; Legacy-Gateway `api.lexoffice.io` nur bis Dezember 2025 verfügbar | belegt (Snippet, Drittquelle) | Chift Blog, Rollout Guide |
| Auth über API-Key als `Authorization: Bearer <key>`, dazu `Accept: application/json`, bei Body `Content-Type: application/json` | belegt (Snippet, Originalquelle und Drittquelle) | developers.lexware.io/docs, Chift |
| API-Key wird in der Lexware-Oberfläche erzeugt (Einstellungen, Öffentliche API), unbegrenzte Gültigkeit, an eine Organisation gebunden, kein Refresh-Flow | belegt (Snippet, Drittquelle) | Chift Blog |
| Kein OAuth2 für die Public API (OAuth nur Partner-API) | nicht belegt, aus Snippets abgeleitet | developers.lexware.io/partner/docs |

Konsequenz für den Hub: API-Key als `encrypted` Cast in `connections`, nie in Logs. Status "Nicht eingerichtet", solange kein Key hinterlegt ist.

### 1.2 Rate Limit

| Aussage | Status | Quelle |
|---|---|---|
| Maximal 2 Requests pro Sekunde je Client, Token-Bucket-Algorithmus | belegt (Snippet, Originalquelle) | developers.lexware.io |
| Bei Überschreitung HTTP 429, Aufruf wird nicht ausgeführt; Sperre für Sekunden bis Minuten; bei fortgesetzter Überschreitung Sperre bis Reduktion | belegt (Snippet, Originalquelle) | developers.lexware.io |
| Retry-After-Header vorhanden | nicht belegt | |

Konsequenz: Eigener Token-Bucket im Hub (Redis), konservativ 1 Request/Sekunde, exponentielles Backoff bei 429, Circuit Breaker analog zum Connector-Modul.

### 1.3 Contacts-Endpunkt: Struktur

| Aussage | Status | Quelle |
|---|---|---|
| Felder: `id`, `version`, `roles`, `company`, `person`, `addresses`, `emailAddresses`, `phoneNumbers`, `note` | belegt (Snippet, Drittquelle: Go-, Elixir-, PHP-Clients, Microsoft Connector) | pkg.go.dev, hexdocs.pm, learn.microsoft.com |
| `roles` mit Schlüsseln `customer` und `vendor` (Objekte, je mit optionaler `number`) | belegt (Snippet, Drittquelle, Struktur der `number` nicht belegt) | Rollout, Microsoft Connector |
| `company` mit `name`, `contactPersons[]` (`salutation`, `firstName`, `lastName`, `emailAddress`, `phoneNumber`); alternativ `person` (`salutation`, `firstName`, `lastName`) | belegt (Snippet, Drittquelle) | Rollout |
| `addresses` mit `billing[]` und `shipping[]`, je `supplement`, `street`, `zip`, `city`, `countryCode` | belegt (Snippet, Drittquelle) | hexdocs.pm |
| `emailAddresses` mit Listen `business`, `office`, `private`, `other`; `phoneNumbers` mit `business`, `office`, `mobile`, `private`, `fax`, `other` | teils belegt (Snippet nennt `business`, `mobile`, `private`), übrige Schlüssel nicht belegt | Rollout |
| Kontaktnummer ist `id` (UUID); fachliche Kundennummer in `roles.customer.number` | belegt für `id`, `number` nicht belegt | |

### 1.4 PUT-Semantik, optimistic locking

| Aussage | Status | Quelle |
|---|---|---|
| Jede aktualisierbare Ressource trägt `version` (Integer); PUT muss die aktuelle Version enthalten, sonst HTTP 409 | belegt (Snippet, Drittquelle, deckungsgleich in zwei Quellen) | Chift, maesn |
| PUT ist Vollersetzung des Kontakts (kein PATCH) | nicht belegt in Snippets, plausibel aus PUT-Semantik; am Original prüfen | |
| Empfehlung: vor jedem PUT GET ausführen, Version übernehmen | belegt (Snippet, Drittquelle) | Chift |
| Einschränkung: Kontakte können nur mit maximal einer Rechnungs- und/oder einer Lieferadresse erstellt und geändert werden. Kontakte mit mehreren Rechnungs- oder Lieferadressen können abgerufen, aber nicht über die REST API aktualisiert werden | belegt (Snippet, Originalquelle developers.lexoffice.io/docs) | developers.lexoffice.io |
| Analoge Einschränkung für mehrere Kontaktpersonen | nicht belegt, im Auftrag als Vermutung genannt; am Original prüfen | |

Konsequenz: Vor jedem Schreibversuch prüft der Hub `count(addresses.billing) <= 1` und `count(addresses.shipping) <= 1`; sonst Vorgang mit Status "Manuell in Lexware pflegen" statt Fehlversuch. 409 wird als Konflikt in die bestehende Konfliktqueue geführt, nie als Erfolg.

### 1.5 Fehlercodes

| Code | Aussage | Status |
|---|---|---|
| 400 | Ungültige Anfrage, Validierungsfehler im Body | nicht belegt (Standard-HTTP) |
| 401 | Fehlender oder ungültiger API-Key | belegt (Snippet, Community: "The request is missing a valid API key") |
| 403 | Kein Zugriff auf Ressource oder fehlendes Recht der Organisation | nicht belegt (Standard-HTTP) |
| 404 | Ressource nicht vorhanden | belegt (Snippet, Originalquelle, Kontext Dateidownload) |
| 406 | Nicht akzeptierter Medientyp bei Downloads; laut Drittquelle auch Domain-Validierungsfehler (statt 400/422) | belegt (Snippet, Originalquelle für Downloads; Drittquelle für Validierung) |
| 409 | Versionskonflikt beim PUT; Ablehnung bei Entwurfsdokumenten | belegt (Snippet, Drittquelle und Originalquelle) |
| 415 | Nicht unterstützter Content-Type | nicht belegt (Standard-HTTP) |
| 429 | Rate Limit überschritten | belegt (Snippet, Originalquelle) |
| 500 | Serverfehler | nicht belegt (Standard-HTTP) |

Fehlerformat: Die Doku unterscheidet "Legacy error response" (Array `IssueList` mit `i18nKey`, `source`, `type`) und "Regular error response" (Struktur nicht im Snippet). Empfehlung aus Drittquelle: `i18nKey` maschinell auswerten, nicht `message`. Status: belegt (Snippet, Drittquelle), Format am Original prüfen.

Konsequenz: Der Hub behandelt jeden Nicht-2xx als Fehlschlag, speichert Statuscode und Body-Auszug (ohne Secrets) in `write_operations`, und mappt 409 auf Konflikt, 429 auf Retry mit Backoff, 401/403 auf "Zugang prüfen" mit Eskalation.

---

## 2. OpenAI API

### 2.1 Responses API und Chat Completions

| Aussage | Status | Quelle |
|---|---|---|
| Beide APIs unterstützen Structured Outputs; Chat Completions über `response_format`, Responses API über `text.format` | belegt (Snippet, Originalquelle developers.openai.com Guide) | developers.openai.com/api/docs/guides/structured-outputs |
| Responses API ist die neuere Schnittstelle, Endpoint `POST /v1/responses` mit `client.responses.create()` | belegt (Snippet, Originalquelle) | developers.openai.com |
| Responses API speichert Zustand standardmäßig 30 Tage (`store` Parameter, Default true) | belegt (Snippet, Originalquelle) | developers.openai.com/api/docs/guides/your-data |
| Chat Completions als abgekündigt | nicht belegt | |

Konsequenz: Implementierung gegen Responses API mit `store: false` als Standard; Chat Completions nur als Fallback-Konfiguration.

### 2.2 Structured Outputs

| Aussage | Status | Quelle |
|---|---|---|
| Schema-Typ `json_schema` mit `strict: true` erzwingt Schemaeinhaltung | belegt (Snippet, Originalquelle) | openai.com/index/introducing-structured-outputs-in-the-api |
| JSON Mode (`json_object`) gilt 2026 als Legacy | belegt (Snippet, Drittquelle) | digitalapplied.com |
| Strict-Modus verlangt `additionalProperties: false` und alle Felder als `required` | nicht belegt in Snippets, aus Erfahrung bekannt; am Original prüfen | |

Konsequenz: Klassifikation von Mails (Kategorie, Dringlichkeit, Objektbezug-Vorschlag) über strict JSON-Schema; das Ergebnis ist immer ein Vorschlag, Zuordnung erfolgt nach Regel 4 nur über externe IDs, nie über KI-Text.

### 2.3 Modellfamilien (nur namentlich belegte)

| Modell | Status | Quelle |
|---|---|---|
| GPT-5 (08/2025), GPT-5.3-Codex (05.02.2026), GPT-5.4 (05.03.2026), GPT-5.4 mini und GPT-5.4 nano (17.03.2026), GPT-5.5 (23.04.2026), GPT-5.6 (Datum nicht belegt) | belegt (Snippet, Drittquelle Wikipedia, eesel) | en.wikipedia.org |
| API-Modell-IDs `gpt-5.4-mini`, `gpt-5.4-nano`, `gpt-5-mini` (aus Doku-URLs abgeleitet) | belegt (Snippet, Originalquelle URL-Pfade) | developers.openai.com/api/docs/models/... |
| GPT-5.4 nano positioniert für Klassifizierung, Datenextraktion, Ranking; nur über API verfügbar | belegt (Snippet, Originalquelle Announcement) | openai.com |
| Genannte Preise (Snippet): GPT-5.4 mini 0,75 USD/1M Input, 4,50 USD/1M Output; GPT-5.4 nano 0,20 USD/1M Input, 1,25 USD/1M Output | belegt (Snippet, Originalquelle Community-Announcement), keine Planungsgrundlage, Preisseite prüfen | community.openai.com |

Konsequenz: Modell-ID ist Konfigurationswert (`config/hub/mail.php`), kein Hardcode. Vorbelegung leer, damit die Integration sichtbar "Nicht eingerichtet" bleibt.

### 2.4 Datenschutz

| Aussage | Status | Quelle |
|---|---|---|
| Abuse-Monitoring-Logs standardmäßig bis 30 Tage, können Prompts, Antworten und Metadaten enthalten | belegt (Snippet, Originalquelle) | developers.openai.com/api/docs/guides/your-data |
| Zero Data Retention (ZDR) und Modified Abuse Monitoring nur nach vorheriger Genehmigung durch OpenAI und Akzeptanz zusätzlicher Anforderungen; bei ZDR wird `store` immer als false behandelt, keine Speicherung auf Datenträger, kein menschlicher Zugriff | belegt (Snippet, Originalquelle) | developers.openai.com |
| Kein Training auf API-Daten ohne Opt-in | belegt (Snippet, Originalquelle) | openai.com/enterprise-privacy |
| EU-Datenresidenz für API-Plattform verfügbar; erfordert neues Projekt in der Europa-Region und EU-Endpoint, bestehende Projekte nicht umstellbar; Verarbeitung auf Microsoft-Azure-Rechenzentren in der EU | belegt (Snippet, Originalquelle Announcement) | openai.com/index/introducing-data-residency-in-europe |
| EU-Endpoint-Hostname | nicht belegt | |
| DPA, Rechtsgrundlage, Transferabsicherung liegen beim Verantwortlichen | belegt (Snippet, Drittquelle) | iubenda |

Konsequenz für die Hausverwaltung: Vor Freischaltung sind Auftragsverarbeitungsvertrag, EU-Projekt und Antrag auf ZDR zu klären (Geschäftsführung, Datenschutzberatung). Bis dahin nur Pseudonymisierung (keine Namen, Adressen, Bankdaten im Prompt) oder Integration deaktiviert. Basis-URL als Konfigurationswert, damit ein EU-Endpoint hinterlegt werden kann.

---

## 3. Google Drive API (v3)

### 3.1 files.list

| Aussage | Status | Quelle |
|---|---|---|
| Filter über `q`, z. B. `'<folderId>' in parents`, kombinierbar mit `name contains`, `mimeType =`, `trashed = false` | belegt (Snippet, Originalquelle) für `in parents`; übrige Operatoren nicht belegt | developers.google.com |
| `supportsAllDrives=true` signalisiert Unterstützung geteilter Ablagen; `includeItemsFromAllDrives=true` nimmt Dateien geteilter Ablagen in Ergebnisse auf; ohne beide Parameter leere Ergebnisse für geteilte Ablagen | belegt (Snippet, Originalquelle) | developers.google.com/workspace/drive/api/guides/enable-shareddrives |
| Für geteilte Ablagen zusätzlich `corpora=drive` und `driveId` | nicht belegt | |
| Pagination über `pageToken`/`nextPageToken`, Feldauswahl über `fields` | nicht belegt in Snippets, Standard der Google-APIs; am Original prüfen | |

### 3.2 Scopes

| Aussage | Status | Quelle |
|---|---|---|
| `https://www.googleapis.com/auth/drive.readonly` für breiten Lesezugriff; `drive.metadata.readonly` nur Metadaten; Scope muss dem Zugriffsmodus entsprechen | belegt (Snippet, Originalquelle) | developers.google.com/workspace/drive/api/guides/api-specific-auth |
| `drive.readonly` gilt als restricted Scope mit Verifizierungspflicht bei externen Nutzern | nicht belegt | |

Konsequenz: Drive ist Dokumentenquelle, Hub liest nur. Scope `drive.readonly`, kein Schreib-Scope. Service-Account oder OAuth2 des Postfachnutzers: am Original prüfen, Entscheidung offen.

### 3.3 Permissions

| Aussage | Status | Quelle |
|---|---|---|
| Permission-Typen `user`, `group`, `domain`, `anyone`; Rollen `owner`, `organizer`, `fileOrganizer`, `writer`, `commenter`, `reader` | belegt (Snippet, Originalquelle) | developers.google.com/workspace/drive/api/guides/manage-sharing |
| `permissions.create` benötigt `type` und `role`; bei `user`/`group` `emailAddress`, bei `domain` `domain`, bei `anyone` nichts weiter | belegt (Snippet, Originalquelle) | ebd. |
| `permissions.list` liefert standardmäßig nur `id`, `type`, `kind`, `role` | belegt (Snippet, Originalquelle) | ebd. |

Konsequenz: Nur `permissions.list` zur Anzeige "wer sieht dieses Dokument"; keine Freigaben aus dem Hub.

### 3.4 Export von Google Docs

| Aussage | Status | Quelle |
|---|---|---|
| `files.export` mit `fileId` und `mimeType` | belegt (Snippet, Originalquelle) | developers.google.com |
| Export-MIME-Typen für Google Docs: docx (`application/vnd.openxmlformats-officedocument.wordprocessingml.document`), odt, rtf, `application/pdf`, `text/plain`, HTML als `application/zip`, epub, `text/markdown` | belegt (Snippet, Originalquelle) | developers.google.com/workspace/drive/api/guides/ref-export-formats |
| Exportierter Inhalt auf 10 MB begrenzt; Umgehung über `exportLinks` aus `files.get`/`files.list` | belegt (Snippet, GitHub-Issue und Google Issue Tracker) | github.com/googleapis, issuetracker.google.com |
| Binärdateien (PDF-Uploads) über `files.get?alt=media` | nicht belegt in Snippets, Standard; am Original prüfen | |

### 3.5 Changes API

| Aussage | Status | Quelle |
|---|---|---|
| `changes.getStartPageToken` liefert Start-Token, optional mit `driveId` für eine geteilte Ablage | belegt (Snippet, Originalquelle) | developers.google.com/workspace/drive/api/reference/rest/v3/changes/getStartPageToken |
| `changes.list` mit `pageToken`; `nextPageToken` bis Ende; am Ende `newStartPageToken`, das für den nächsten Lauf gespeichert wird | belegt (Snippet, Originalquelle) | developers.google.com/workspace/drive/api/guides/manage-changes |
| Änderungen enthalten `removed`-Flag und `file`-Ressource | nicht belegt | |

Konsequenz: Inkrementeller Spiegel der Dokumentmetadaten über Changes API mit persistentem `newStartPageToken` je Connection; Vollabgleich nur initial. Passt zum Muster des WebDAV-Spiegels (Mark-and-Sweep, `deleted_at`).

---

## 4. Übergreifende Festlegungen für die Implementierung

1. Alle drei Integrationen ohne Zugangsdaten sichtbar "Nicht eingerichtet"; kein Mock-Erfolg in der Oberfläche.
2. Jeder HTTP-Aufruf wird protokolliert (Statuscode, Dauer, Request-ID ohne Secrets); ein 2xx ist nur "Aufruf erfolgreich", das Geschäftsergebnis wird gesondert verifiziert (z. B. GET nach PUT bei Lexware und Versionsvergleich).
3. Basis-URLs, Modell-IDs, Scopes sind Konfigurationswerte, damit Abweichungen zwischen Snippet und Original ohne Codeänderung korrigierbar sind.
4. Tests ausschließlich mit `Http::fake`; Testnamen und Doku kennzeichnen sie als Vertragsannahme, nicht als Live-Test.

## 5. Offene Punkte (vor Implementierung am Original zu prüfen)

- Lexware: exakte JSON-Struktur `roles.customer.number`, vollständige Schlüssel von `emailAddresses`/`phoneNumbers`, "Regular error response"-Format, Retry-After bei 429, ob Kontakte mit mehreren Kontaktpersonen aktualisierbar sind.
- OpenAI: Anforderungen des Strict-Modus im Schema, aktueller Modellkatalog und Preise, Hostname des EU-Endpoints, Antragsweg ZDR, AV-Vertrag.
- Google Drive: Auth-Weg (Service-Account mit Domain-Delegation oder OAuth2), Parameter `corpora`/`driveId`, Verifizierungspflicht des Scopes, Felder der Change-Ressource.

## Quellen

- https://developers.lexware.io/docs/
- https://developers.lexoffice.io/docs/?shell=
- https://developers.lexware.io/partner/docs/
- https://www.chift.eu/blog/lexware-office-api-integration-best-practices-and-key-insights
- https://www.maesn.com/blogs/lexware-office-api-explained
- https://rollout.com/integration-guides/lexoffice/api-essentials
- https://hexdocs.pm/lexoffice/LexOffice.Model.Contact.Addresses.html
- https://pkg.go.dev/github.com/karitham/go-lexoffice
- https://learn.microsoft.com/en-us/connectors/lexoffice/
- https://community.zapier.com/troubleshooting-99/lexoffice-error-the-app-returned-the-request-is-missing-a-valid-api-key-33072
- https://developers.openai.com/api/docs/guides/structured-outputs
- https://developers.openai.com/api/docs/guides/your-data
- https://developers.openai.com/api/docs/models
- https://developers.openai.com/api/docs/models/gpt-5.4-mini
- https://developers.openai.com/api/docs/models/gpt-5.4-nano
- https://openai.com/index/introducing-structured-outputs-in-the-api/
- https://openai.com/index/introducing-data-residency-in-europe/
- https://openai.com/enterprise-privacy/
- https://openai.com/index/introducing-gpt-5-4-mini-and-nano/
- https://community.openai.com/t/introducing-gpt-5-4-mini-and-nano-our-most-capable-small-models-yet/1377015
- https://en.wikipedia.org/wiki/GPT-5.4
- https://en.wikipedia.org/wiki/GPT-5.5
- https://www.digitalapplied.com/blog/openai-structured-outputs-complete-guide
- https://www.iubenda.com/en/blog/openai-gdpr-compliance/
- https://developers.google.com/workspace/drive/api/guides/enable-shareddrives
- https://developers.google.com/workspace/drive/api/guides/api-specific-auth
- https://developers.google.com/workspace/drive/api/guides/manage-sharing
- https://developers.google.com/workspace/drive/api/guides/ref-export-formats
- https://developers.google.com/workspace/drive/api/guides/manage-changes
- https://developers.google.com/workspace/drive/api/reference/rest/v3/changes/getStartPageToken
- https://github.com/googleapis/google-api-python-client/issues/1837
- https://issuetracker.google.com/issues/308836075
