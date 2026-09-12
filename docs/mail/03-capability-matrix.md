# 03 Capability-Matrix je Zielsystem

Stand: 12.09.2026. Statuswerte: **verfügbar**, **nur lesend**, **eingeschränkt**, **nicht verfügbar**, **ungeprüft**. Ein Status ist nur dann besser als "ungeprüft", wenn er aus der realen Capability-Registry des Hubs (`config/hub/connector.php`, `CapabilityRegistry`) oder aus einer im Repository belegten Aussage folgt. Aussagen zu Gmail, Lexware, Drive und OpenAI stammen aus WebSearch-Snippets (`docs/mail/research/`) und gelten als "aus Snippets, vor Implementierung am Original zu prüfen".

Grundsatz: Die Matrix ist zur Laufzeit ebenfalls sichtbar (Mail-Oberfläche, Bereich Integrationen). Für Immoware24 wird sie je Connection aus `CapabilityRegistry::all()` abgeleitet, nicht aus dieser Tabelle. Für die übrigen Systeme zeigt die Oberfläche "Nicht eingerichtet", solange keine Zugangsdaten hinterlegt und kein Probe-Aufruf erfolgreich war.

## 1. Immoware24 (Fachsystem, je Connection)

Ableitung aus der Registry: Eine Fähigkeit ist verfügbar, wenn `evidence_status` verified oder tested, `enabled` true, `config_allowed` true und `hard_locked` false. Die folgende Tabelle zeigt den heute maximal erreichbaren Status je Zugangsweg. Da Phase 0 (Probe am Mandanten) nicht begonnen hat, ist der reale Status aller Zeilen aktuell **ungeprüft** (`evidence_status` assumed oder waiting_for_vendor_access). Die Spalte "nach Probe" gibt den Zielstatus an.

| Funktion | Capability-Key (Registry) | Zugangsweg | Heute | Nach erfolgreicher Probe | Verhalten im Mail-Modul |
|---|---|---|---|---|---|
| Kontakt lesen/suchen | `contacts.read` | CardDAV-Spiegel (`contacts`) | ungeprüft | nur lesend | Suche im Spiegel über `contact_identifiers` (E-Mail, Telefon) als Vorschlag; Zuordnung nur nach Bestätigung oder eindeutiger externer Kennung |
| Objekt lesen | `properties.read`, `units.read` | CSV-Import (`properties`, `units`) | ungeprüft | nur lesend | Objekt- und Einheitensuche im Spiegel, Datenalter sichtbar (`stale_since`) |
| Vertragsbezug lesen | `contracts.read` | CSV-Import (`contracts`, `contract_parties`) | ungeprüft | nur lesend | Vertrag zur Einheit anzeigen, Mieter/Eigentümer-Rolle aus `contract_parties` |
| Adresse ändern | kein Key; `contacts.write` ist hard_locked | CardDAV schreiben gesperrt, keine REST-API | **nicht verfügbar** | nicht verfügbar | Manuelle Aufgabe `manual_change_immoware` mit Alt/Neu, Übergabe als `proposed_change` (bestehender Rückweg), Abschlussstatus "Manuell bestätigt" (`done_manual_confirmed`), optional `done_verified` nach nächstem Spiegellauf |
| Bankdaten ändern | kein Key | wie Adresse | **nicht verfügbar** | nicht verfügbar | Manuelle Aufgabe mit Alt/Neu (verschlüsselt), Vier-Augen-Ansicht, Status "Manuell bestätigt"; Verifikation über `bank_accounts`-Spiegel (iban_hash) nach Import |
| Dokument verknüpfen (Datei in Immoware ablegen) | `documents.write` | WebDAV create-only PUT in `/Posteingang/` | ungeprüft, Flags false | eingeschränkt (nur Posteingang, keine Zuordnung zu Objekt oder Kontakt möglich) | Nur über `PosteingangUploadService::submit()` mit `MAIL_IMMOWARE_WRITE_ENABLED` und bestehender Freigabe; Verifikation per PROPFIND/ETag durch den bestehenden Dienst |
| Dokument lesen/verknüpfen (Referenz) | `documents.read` | WebDAV-Spiegel (`documents`) | ungeprüft | nur lesend | Referenz `mail_document_references.immoware_document_id` |
| Aufgabe/Notiz schreiben | `cases.write` (config_flag null), Adapter `rest_api_slot` WAITING_FOR_VENDOR_ACCESS | keine | **nicht verfügbar** | nicht verfügbar | Aufgaben leben im Hub (`mail_tasks`); Notiz kann als Textdatei in den Posteingang gelegt werden (wie Dokument, eingeschränkt) |
| Ergebnis nachlesen | `documents.read`, `contacts.read` | Spiegel nach nächstem Lauf | ungeprüft | nur lesend | Verifikation `reread_get` gegen Spiegel; bei Posteingang PROPFIND durch bestehenden Dienst |
| Termin lesen | `calendar.read` | CalDAV-Spiegel | ungeprüft | nur lesend | Anzeige, niedrige Priorität |
| Termin schreiben | `calendar.write` | hard_locked | nicht verfügbar | nicht verfügbar | Kein Schreibpfad |

Konsequenz: Für Immoware24 gibt es aus dem Mail-Modul genau einen automatisierbaren Schreibweg (Datei in den Posteingang) und dieser ist eingeschränkt. Alle Stammdatenänderungen sind manuelle Aufgaben mit dokumentiertem Alt/Neu und menschlicher Bestätigung.

## 2. Gmail (Mailsystem)

Status heute für alle Zeilen: **ungeprüft / nicht eingerichtet** (kein OAuth-Client, kein Token, kein Live-Aufruf möglich). Spalte "erwartet" aus Snippets.

| Funktion | Methode (Snippet) | Scope (07) | Heute | Erwartet nach Einrichtung | Flag |
|---|---|---|---|---|---|
| Nachrichten lesen | history.list, messages.get, threads.get | gmail.readonly | ungeprüft | verfügbar | MAIL_IMPORT_ENABLED |
| Push empfangen | users.watch, Pub/Sub | gmail.readonly plus Pub/Sub-Topic | ungeprüft | eingeschränkt (Watch-Erfolg heißt nicht Zustellung; Polling-Fallback Pflicht) | MAIL_IMPORT_ENABLED |
| Anhänge lesen | messages.attachments.get | gmail.readonly | ungeprüft | verfügbar | MAIL_IMPORT_ENABLED |
| Entwurf anlegen/ändern | drafts.create, drafts.update | gmail.compose | ungeprüft | verfügbar | MAIL_GMAIL_DRAFTS_ENABLED |
| Senden | drafts.send oder messages.send | gmail.send (oder compose) | ungeprüft | verfügbar, serverseitig durch Flag, Recht, Freigabe begrenzt | MAIL_GMAIL_SEND_ENABLED |
| Versand nachlesen | messages.get (Label SENT, Message-ID) | gmail.readonly | ungeprüft | verfügbar | MAIL_IMPORT_ENABLED |
| Aliasse lesen | settings.sendAs.list | gmail.settings.basic (oder readonly, zu prüfen) | ungeprüft | verfügbar | MAIL_IMPORT_ENABLED |
| Labels setzen | messages.modify | gmail.modify | nicht vorgesehen | nicht vorgesehen (Bearbeitungsstatus lebt im Hub) | keines |
| Löschen | messages.trash/delete | gmail.modify | **nicht vorgesehen, Allowlist sperrt** | nie | keines |

## 3. Lexware Office (Rechnungsprogramm)

Status heute: **ungeprüft / nicht eingerichtet** (kein API-Key).

| Funktion | Endpunkt (Snippet) | Heute | Erwartet | Flag / Bedingung |
|---|---|---|---|---|
| Kontakt lesen/suchen | GET /v1/contacts, /v1/contacts/{id} | ungeprüft | nur lesend | Key hinterlegt |
| Adresse ändern | GET, dann PUT /v1/contacts/{id} mit `version`, dann GET | ungeprüft | eingeschränkt (nur Kontakte mit höchstens einer Rechnungs- und einer Lieferadresse, Snippet belegt) | MAIL_LEXWARE_WRITE_ENABLED, Vier-Augen, Verifikation `lexware_version_compare` |
| Bankdaten ändern | Feld im Kontakt nicht in Snippets belegt | ungeprüft | ungeprüft (vor Implementierung am Original prüfen) | wie Adresse; bis Klärung manuelle Aufgabe |
| Dokument verknüpfen | Voucher-/Files-Endpunkte nicht recherchiert | ungeprüft | ungeprüft | nicht in Phase 1 |
| Aufgabe/Notiz schreiben | `note` im Kontakt (Snippet, Drittquelle) | ungeprüft | eingeschränkt | wie Adresse |
| Ergebnis nachlesen | GET nach PUT, Versionsvergleich | ungeprüft | verfügbar | Pflicht nach jedem PUT |
| Objekt, Vertrag | nicht vorhanden in Lexware | nicht verfügbar | nicht verfügbar | |

Rate Limit 2 rps (Snippet, Originalquelle), im Hub konservativ 1 rps. 409 wird als Konflikt geführt, nie als Erfolg.

## 4. Google Drive (Dokumentenquelle, nur lesend)

Status heute: **ungeprüft / nicht eingerichtet**.

| Funktion | Methode (Snippet) | Heute | Erwartet | Bemerkung |
|---|---|---|---|---|
| Dokument suchen/lesen | files.list mit `q`, files.get, files.export | ungeprüft | nur lesend | Scope drive.readonly, `supportsAllDrives=true`, `includeItemsFromAllDrives=true` |
| Dokument verknüpfen (Referenz im Vorgang) | files.get (Metadaten) | ungeprüft | nur lesend | Referenz in `mail_document_references`, Existenz nachgelesen |
| Berechtigungen anzeigen | permissions.list | ungeprüft | nur lesend | keine Freigabe aus dem Hub |
| Dokument anlegen/ändern/freigeben | files.create, permissions.create | **nicht vorgesehen** | nie | Allowlist sperrt |
| Änderungen verfolgen | changes.getStartPageToken, changes.list | ungeprüft | nur lesend | optional, Phase 2 |

## 5. OpenAI (KI-Adapter)

Status heute: **ungeprüft / nicht eingerichtet** (kein Key, kein AVV, kein EU-Projekt).

| Funktion | Methode (Snippet) | Heute | Erwartet | Bedingung |
|---|---|---|---|---|
| Klassifikation (Kategorie, Priorität, Objektbezug-Vorschlag) | POST /v1/responses, Structured Outputs `json_schema` strict | ungeprüft | eingeschränkt (nur Vorschlag, Schema-validiert, maskierte Eingabe) | MAIL_AI_ENABLED, AVV, `store: false` |
| Extraktion (Zählerstand, Datum, Alt/Neu-Adresse) | wie oben | ungeprüft | eingeschränkt (Vorschlag, Bestätigung Pflicht) | wie oben |
| Antwortentwurf | wie oben | ungeprüft | eingeschränkt (Entwurf lokal, nie automatischer Versand) | wie oben, MAIL_GMAIL_DRAFTS_ENABLED für Übergabe an Gmail |
| Zuordnung zu Kontakt/Objekt | | **nicht verfügbar als Entscheidung** | nie | Zuordnung nur über Kennung oder Mensch (CLAUDE.md Regel 4) |
| Aktionen auslösen | | **nicht verfügbar** | nie | KI erzeugt höchstens Action-Plan-Entwürfe im Status draft |

## 6. Allowlist der ausführbaren Aktionen (Modul Actions)

Nur diese `action_key` sind ausführbar; alles andere wird als `blocked_capability` abgewiesen:

| action_key | Zielsystem | Voraussetzungen |
|---|---|---|
| gmail.draft.create, gmail.draft.update | Gmail | MAIL_GMAIL_DRAFTS_ENABLED, Recht mail.drafts.write, Alias akzeptiert |
| gmail.draft.send | Gmail | MAIL_GMAIL_SEND_ENABLED, Recht mail.send, Freigabe (Vier-Augen bei Außenwirkung), 2fa.fresh, Versandabgleich |
| immoware.posteingang.put | Immoware24 | MAIL_IMMOWARE_WRITE_ENABLED, bestehende Immoware-Freigabe (`hasCompleteWriteApproval()`), Capability documents.write verfügbar |
| immoware.proposed_change.create | Hub (Rückweg) | Recht mail.tasks.write |
| lexware.contact.update | Lexware | MAIL_LEXWARE_WRITE_ENABLED, Recht mail.integrations.lexware.write, Freigabe, Adressanzahl geprüft, GET-Version-PUT-GET |
| task.manual.create | Hub | Recht mail.tasks.write |
| case.status.set, case.assign | Hub | Recht mail.cases.write |

## 7. Pflege

Die Matrix wird bei jeder Probe (Immoware) beziehungsweise bei jedem Integrationstest (Gmail, Lexware, Drive, OpenAI) aktualisiert. Ein erfolgreicher Http::fake-Test ändert den Status nie; nur ein protokollierter Aufruf gegen das Original mit nachgelesenem Ergebnis hebt "ungeprüft" auf.
