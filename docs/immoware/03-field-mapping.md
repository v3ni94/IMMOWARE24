# 03 Field-Mapping: Immoware24-Nutzlasten zu Hub-Tabellen

Stand: 11.09.2026
Mapping-Version: v1.0 (Status draft, wird nach Phase 0 mit Realdaten auf v1.1 gehoben)
Bezug: Datenmodell Immoware Hub (contacts, companies, documents, document_folders, calendar_events), Architekturentscheidung Abschnitt 3 und 4

## 0. Grundsätze

1. Mapping ist eine reine Funktion RawPayload zu DomainDTO ohne Datenbankzugriff. Jede Nutzlast liegt vorher unverändert mit SHA-256 in external_payloads.
2. Der Hub kennt kein einziges Immoware24-spezifisches Feld aus offizieller Dokumentation. Welche vCard-Version, welche Properties, welche X-Properties und welche PROPFIND-Properties der Immoware24-DAV-Server liefert, ist NICHT VERFÜGBAR und wird in Phase 0 durch die Probe und durch Sichtung echter Nutzlasten festgestellt. Dieses Dokument mappt daher die Standardfelder der RFCs (vCard 3.0 RFC 2426, vCard 4.0 RFC 6350, iCalendar RFC 5545, WebDAV RFC 4918, CalDAV RFC 4791, CardDAV RFC 6352, sync-collection RFC 6578) und führt alle Immoware24-Spezifika als offene Punkte.
3. Unbekannte Properties werden nie verworfen. Sie landen unverändert im JSON-Feld extra_properties des jeweiligen Datensatzes und werden in der Checksumme berücksichtigt, sofern sie nicht in der Normalisierungs-Ausschlussliste stehen.
4. Alle Belegstatus-Angaben beziehen sich auf Immoware24. Aussagen über RFC-Standardfelder brauchen keinen Belegstatus.
5. Checksummen werden über den normalisierten Datensatz gebildet (Abschnitt 5). Nur eine geänderte Checksumme erzeugt eine neue Version.

## 1. vCard (CardDAV) zu contacts und companies

Belegstand Zugangsweg: CardDAV-Kontaktfreigabe über den DAV-Adapter, VERIFIZIERT (Existenz), Schreibrichtung unklar, Hub liest nur. Quelle: Anleitung zum DAV-Adapter (WebDAV, CalDAV, CardDAV), © 2023 Immoware24 GmbH, content.immoware24.de/content/manual/Anleitung_DAV-Adapter.pdf; Support-Artikel 360010764437, 360010876078.

Offen (NICHT VERFÜGBAR, zu verifizieren am eigenen Mandanten): vCard-Version (3.0 oder 4.0), ob UID gesetzt und stabil ist, ob mehrere Adressbücher je Nutzer existieren ("unterteilt nach Kontakt-Typen", VERMUTET), ob Firmen als eigene vCards mit KIND:org (4.0) oder nur über ORG geliefert werden, ob X-Properties mit Fachbezug (Kontakt-Typ, Objektbezug, Debitorennummer) vorhanden sind.

### 1.1 Identität

| Quelle | Hub-Feld | Regel |
|---|---|---|
| UID | contacts.vcard_uid, external_id | Primär. Fehlt UID, wird der href (Pfad relativ zur Collection) als external_id verwendet und identity_confidence = derived gesetzt |
| href (Antwort PROPFIND oder multiget) | contacts.vcard_href | Zweitschlüssel |
| getetag | sync_states.etag | Nur zur Änderungserkennung, nicht Teil der Checksumme |
| REV | contacts.vcard_rev | Hinweis, keine Wahrheit, nicht Teil der Checksumme |

### 1.2 Feldmapping contacts (kind = person)

| vCard 3.0 | vCard 4.0 | Hub-Feld | Transformation | Anmerkung |
|---|---|---|---|---|
| N (Family;Given;Additional;Prefix;Suffix) | N | last_name, first_name | Family zu last_name, Given plus Additional (mit Leerzeichen) zu first_name, Prefix zu salutation, falls salutation leer | Suffix in extra_properties |
| FN | FN | keine eigene Spalte | Nur Fallback: Fehlt N, wird FN in last_name übernommen, first_name null, identity_confidence bleibt unverändert, Konflikttyp uncertain_identity nur bei leerem FN und N | FN in extra_properties |
| NICKNAME | NICKNAME | extra_properties | | |
| BDAY | BDAY | birth_date | ISO 8601 zu DATE. 4.0 erlaubt Teil-Datum (--MMDD), dann null und Vermerk in extra_properties | |
| GENDER (nicht in 3.0) | GENDER | extra_properties | | Datenschutz: nicht in Hub-Ausgaben anzeigen |
| TITLE, ROLE | TITLE, ROLE | extra_properties | | |
| ORG | ORG | company_id (Verknüpfung) | Erste Komponente von ORG wird gegen companies.name (normalisiert) gesucht. Genau ein Treffer: company_id setzen. Kein Treffer: Vorschlag zur Anlage einer company in der Konfliktqueue (Typ uncertain_identity, kein Auto-Create). Mehrere Treffer: Vorschlag | Vollständiger ORG-Wert in extra_properties |
| EMAIL;TYPE=... | EMAIL;TYPE=...;PREF=n | emails (JSON) | Liste von {type, value, pref}. type aus TYPE-Parameter (work, home, internet, other), Kleinschreibung. value: Trim, Kleinschreibung der Domain, lokaler Teil unverändert. PREF (4.0) oder TYPE=pref (3.0) zu pref = true | Reihenfolge: pref zuerst, dann sortiert nach value |
| TEL;TYPE=... | TEL;VALUE=uri:tel:... oder Text | phones (JSON) | Liste von {type, value, pref}. type aus TYPE (voice, cell, fax, work, home). value: alle Zeichen außer Ziffern und führendem Plus entfernen, führende 00 zu Plus; Ergebnis E.164-nah, ohne Anspruch auf Gültigkeit. Original in raw | Hinweis: Immoware24 akzeptiert laut Snippet nur Ziffern bei Telefonnummern (DOKUMENTIERT, Artikel 360018097757), daher ist Normalisierung verlustfrei zu erwarten |
| ADR;TYPE=... (PO Box;Extended;Street;Locality;Region;Postal;Country) | ADR mit LABEL | addresses (JSON) | Liste von {type, street, extended, postal_code, city, region, country, label}. Komponenten 1:1, Country als Text (keine ISO-Umsetzung in v1.0) | Trennung Straße und Hausnummer wird nicht versucht |
| LABEL (3.0) | LABEL-Parameter (4.0) | addresses[].label | | |
| NOTE | NOTE | extra_properties.note | | Datenschutz: kann Freitext mit personenbezogenen Daten enthalten, wird bei DSGVO-Löschung mitgelöscht |
| CATEGORIES | CATEGORIES | extra_properties.categories (Array) | Kommagetrennt zu Array, Trim | Kandidat für Kontakt-Typ und Rollenableitung, VERMUTET, in v1.0 keine Rollenableitung |
| URL | URL | extra_properties.urls | | |
| PHOTO | PHOTO | wird verworfen | Nicht gespeichert, Vermerk photo_present = true in extra_properties | Speicherplatz und Datenschutz |
| KEY, SOUND, LOGO, GEO, TZ | dito | wird verworfen | Vermerk in extra_properties.dropped | |
| KIND (nur 4.0) | KIND | contacts.kind | individual zu person, org zu company (dann Mapping 1.3), group und location zu Konflikt uncertain_identity | 3.0: X-ADDRESSBOOKSERVER-KIND als Fallback, sonst Heuristik: N leer und ORG gesetzt zu company (identity_confidence = derived) |
| MEMBER (nur 4.0) | MEMBER | extra_properties | Gruppenkontakte werden nicht aufgelöst | |
| RELATED (nur 4.0) | RELATED | extra_properties | | |
| PRODID, VERSION | dito | nicht gespeichert | Aus Checksumme ausgeschlossen | |
| X-* ohne Fachbezug | X-* | extra_properties.x, aus Checksumme ausgeschlossen, wenn in Ausschlussliste (Abschnitt 5) | | |
| X-* mit Fachbezug (Immoware24-spezifisch) | | offen | NICHT VERFÜGBAR. Werden in v1.0 vollständig in extra_properties.x gespeichert und in die Checksumme einbezogen. Nach Phase 0 Einzelentscheidung je Property in v1.1 | zu verifizieren am eigenen Mandanten |

### 1.3 Feldmapping companies (kind = company)

| vCard | Hub-Feld | Transformation |
|---|---|---|
| ORG (erste Komponente) oder FN | companies.name | Trim, Mehrfach-Leerzeichen reduzieren |
| ORG (weitere Komponenten) | extra_properties.org_units | |
| ADR | companies.addresses | wie 1.2 |
| EMAIL, TEL, URL | extra_properties | In v1.0 keine eigenen Spalten auf companies |
| X-* Registernummer | companies.register_number | offen, NICHT VERFÜGBAR, in v1.0 nicht befüllt |
| UID oder href | external_id | wie 1.1 |

### 1.4 Nicht mappbare Hub-Felder (bleiben null oder Hub-Wahrheit)

contacts.similarity_hash (Hub-eigen, Abschnitt 5), contacts.merged_into_id (manuell), contact_roles (aus CSV-Belegungsliste, nicht aus vCard), bank_accounts (nicht aus vCard), personal_data_erased_at (Hub-eigen).

## 2. iCalendar VEVENT (CalDAV) zu calendar_events

Belegstand Zugangsweg: CalDAV-Kalenderfreigabe über den DAV-Adapter, VERIFIZIERT (Existenz), Schreibrichtung unklar, Hub liest nur, Phase 3. Ob VTODO (Aufgaben) oder VJOURNAL geliefert werden: NICHT VERFÜGBAR. Ob mehrere Kalender je Nutzer existieren: zu verifizieren am eigenen Mandanten.

### 2.1 Tabelle calendar_events (Ergänzung zum Datenmodell, wird in Phase 3 angelegt)

| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | UUIDv7 |
| organization_id | BINARY(16) FK NOT NULL | |
| calendar_collection_path_hash | CHAR(64) NOT NULL | Collection der Freigabe |
| ical_uid | VARCHAR(255) NOT NULL | |
| recurrence_id | VARCHAR(64) NULL | RECURRENCE-ID als ISO-String, leer für Master |
| ical_href | VARCHAR(1024) NULL | |
| summary | VARCHAR(500) NULL | |
| description | TEXT NULL | |
| location | VARCHAR(500) NULL | |
| starts_at | DATETIME(6) NULL | UTC |
| ends_at | DATETIME(6) NULL | UTC |
| is_all_day | TINYINT(1) NOT NULL DEFAULT 0 | |
| tzid_original | VARCHAR(64) NULL | |
| status | ENUM('TENTATIVE','CONFIRMED','CANCELLED','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN' | |
| rrule | TEXT NULL | roh |
| exdates | JSON NULL | |
| organizer | VARCHAR(320) NULL | mailto ohne Präfix |
| attendees | JSON NULL | |
| categories | JSON NULL | |
| sequence | INT UNSIGNED NULL | Hinweis, keine Wahrheit |
| dtstamp | DATETIME(6) NULL | Hinweis, keine Wahrheit |
| property_id, unit_id, contact_id, case_id | BINARY(16) FK NULL | Hub-Zuordnung, nicht aus iCal |
| extra_properties | JSON NULL | |
| Herkunft-Block (H) | | external_id = UID plus Trennzeichen plus RECURRENCE-ID |
Unique: (connection_id, external_id_hash). Index: (starts_at), (ical_uid), (property_id, starts_at)
Schattentabelle calendar_events_versions nach Konvention.

### 2.2 Feldmapping VEVENT

| iCalendar Property | Hub-Feld | Transformation | Anmerkung |
|---|---|---|---|
| UID | ical_uid, external_id (Teil 1) | Trim | Fehlt UID: href als external_id, identity_confidence = derived |
| RECURRENCE-ID | recurrence_id, external_id (Teil 2) | Normalisiert auf UTC-ISO-String bei DATE-TIME, sonst Datum | Master-Ereignis: leer |
| SUMMARY | summary | Unescaping nach RFC 5545 (\, \; \n) | Kürzung auf 500 Zeichen, Rest in extra_properties |
| DESCRIPTION | description | Unescaping | |
| LOCATION | location | Unescaping | |
| DTSTART | starts_at, is_all_day, tzid_original | VALUE=DATE zu is_all_day = 1, starts_at = Datum 00:00 UTC. DATE-TIME mit TZID: Umrechnung nach UTC über VTIMEZONE der Nutzlast, sonst IANA-Zone gleichen Namens; nicht auflösbare TZID: starts_at null, Konflikt uncertain_identity nicht nötig, Vermerk extra_properties.tz_unresolved | Floating time (ohne Z und TZID): als Europe/Berlin interpretiert, Vermerk |
| DTEND | ends_at | wie DTSTART. VALUE=DATE ist exklusiv (RFC 5545), wird unverändert gespeichert | |
| DURATION | ends_at | Wenn DTEND fehlt: starts_at plus DURATION | |
| STATUS | status | Wertebereich RFC 5545, sonst UNKNOWN | |
| RRULE | rrule | roh | Keine Expansion im Hub v1.0 |
| RDATE | extra_properties.rdates | | |
| EXDATE | exdates | Array ISO | |
| ORGANIZER | organizer | mailto: entfernen, Kleinschreibung | CN-Parameter in extra_properties |
| ATTENDEE | attendees | Array {email, cn, role, partstat, rsvp} | |
| CATEGORIES | categories | Array | |
| CLASS | extra_properties | PRIVATE oder CONFIDENTIAL: description und location werden nicht gespeichert, Vermerk | Datenschutz |
| PRIORITY, TRANSP, URL, GEO, RESOURCES, CONTACT, RELATED-TO | extra_properties | | RELATED-TO ist Kandidat für Ticketbezug, VERMUTET |
| ATTACH | extra_properties.attachments (nur URI, kein Binary) | Inline-Binary wird verworfen, Vermerk | |
| SEQUENCE | sequence | Hinweis | Aus Checksumme ausgeschlossen |
| DTSTAMP, LAST-MODIFIED, CREATED | dtstamp, extra_properties | Hinweis | Aus Checksumme ausgeschlossen |
| VALARM | wird verworfen | Vermerk alarm_count | |
| X-* | extra_properties.x | Immoware24-spezifische Bezüge (Objekt, Einheit, Ticket) NICHT VERFÜGBAR, zu verifizieren am eigenen Mandanten | In Checksumme enthalten, außer Ausschlussliste |
| VTODO, VJOURNAL, VFREEBUSY | nicht gemappt | Nutzlast wird archiviert, sync_event mit action conflict, Typ format_mismatch, sofern sie auftauchen | Entscheidung in v1.1 |

## 3. WebDAV PROPFIND zu documents und document_folders

Belegstand Zugangsweg: WebDAV-Dateifreigabe des DAV-Adapters, VERIFIZIERT. Welche DAV-Properties der Server tatsächlich liefert (insbesondere getetag, sync-token, quota, Immoware24-spezifische Properties im eigenen Namespace): NICHT VERFÜGBAR, wird durch die Probe festgestellt (Architekturentscheidung 3.2). DMS-Metadaten (Kategorie, Objektzuordnung, Vorgangsbezug) sind über WebDAV nach aktuellem Stand nicht zugänglich (NICHT VERFÜGBAR).

Angeforderte Properties je PROPFIND (Depth 1): DAV:resourcetype, DAV:getetag, DAV:getlastmodified, DAV:getcontentlength, DAV:getcontenttype, DAV:displayname, DAV:creationdate, DAV:supportedlock (nur in Probe), DAV:sync-token und DAV:supported-report-set (nur in Probe, Depth 0). Zusätzlich DAV:allprop einmalig in der Probe, um unbekannte Properties zu erfassen.

### 3.1 Identität und Pfad

| Quelle | Hub-Feld | Regel |
|---|---|---|
| href der Response | documents.path bzw. document_folders.path | URL-decodiert, relativ zum Freigabe-Root (Basis-URL der Connection wird abgeschnitten), führender Schrägstrich entfernt, Unicode NFC-normalisiert, Trailing Slash bei Collections entfernt |
| SHA-256(path) | path_hash = external_id_hash | Unique je Connection |
| letztes Pfadsegment | documents.filename | Unverändert (Original), keine Sanitisierung beim Lesen |
| Pfad ohne letztes Segment | folder_id (Verknüpfung) | Lookup über document_folders.path_hash; fehlender Ordner wird im selben Lauf angelegt |
| Anzahl Segmente | document_folders.depth | |

### 3.2 Feldmapping documents (Nicht-Collection)

| DAV-Property | Hub-Feld | Transformation | Anmerkung |
|---|---|---|---|
| DAV:resourcetype ohne DAV:collection | Entscheidung documents | | |
| DAV:getetag | remote_etag | Anführungszeichen und Schwach-Präfix W/ werden entfernt, Original in extra_properties.etag_raw | Nur zur Erkennung, wenn Probe ETag als stabil ausweist (sync_states.strategy) |
| DAV:getlastmodified | remote_last_modified | RFC 1123 zu UTC DATETIME(6). Nicht parsebar: null, Vermerk | |
| DAV:getcontentlength | size_bytes | Integer | |
| DAV:getcontenttype | content_type | Kleinschreibung, Parameter (charset) entfernt und in extra_properties | Server-Angabe, keine eigene Erkennung in v1.0 |
| DAV:displayname | extra_properties.displayname | Nur gespeichert, wenn abweichend vom Dateinamen | |
| DAV:creationdate | extra_properties.creationdate | ISO 8601 | |
| Inhalt (GET, nur bei content_policy hash_on_change oder store) | content_hash, content_stored, storage_key | SHA-256 über die Bytes; storage_key nur bei store | |
| Unbekannte Properties (allprop in Probe) | extra_properties.dav | Namespace-qualifiziert | Immoware24-eigener Namespace: NICHT VERFÜGBAR, zu verifizieren am eigenen Mandanten |

Nicht aus WebDAV befüllbar (Hub-Wahrheit): property_id, unit_id, contact_id, case_id, origin, write_operation_id.

Checksummen-Grundlage documents: content_hash, falls vorhanden; sonst Kombination aus size_bytes und remote_last_modified (Architekturentscheidung 4.1 Punkt 4). ETag-Wechsel ohne Änderung dieser Merkmale ist unchanged_meta_noise.

### 3.3 Feldmapping document_folders (Collection)

| DAV-Property | Hub-Feld | Transformation |
|---|---|---|
| DAV:resourcetype mit DAV:collection | Entscheidung document_folders | |
| href | path, path_hash, depth, parent_id | wie 3.1 |
| Kind-Liste (Name, Größe, lastmodified, ETag), sortiert nach Name | children_fingerprint | SHA-256 über die konkatenierten, normalisierten Einträge. Beschleuniger, nie alleinige Wahrheit |
| DAV:getlastmodified der Collection | extra_properties.lastmodified | Nicht als Änderungsmerkmal genutzt, weil WebDAV tiefe Änderungen nicht garantiert nach oben meldet |
| DAV:sync-token (Depth 0, Probe) | sync_states.sync_token | Nur bei Strategie sync_token |
| Pfadname gleich "Posteingang" (erste Ebene) | scan_priority = 1, content_policy = hash_on_change, writable_by_hub = 1 (nur auf der Schreib-Connection) | Der Ordnername "Posteingang" ist VERIFIZIERT (Snippet der Anleitung); exakte Schreibweise und Pfadtiefe zu verifizieren am eigenen Mandanten, danach in Konfiguration festschreiben, nicht raten |
| Pfadname gleich "Dokumente" (erste Ebene) | scan_priority = 3, content_policy = metadata_only, writable_by_hub = 0 | Ordner ist laut Quelle beschreibbar, wird vom Hub aber nie beschrieben |
| Alle übrigen Ordner | scan_priority = 3 bis 5, metadata_only, writable_by_hub = 0 | Existenz weiterer Ordner: VERMUTET |

### 3.4 Sanitisierung beim Schreiben (nur Posteingang, Referenz 05-write-capabilities.md)

Beim Lesen wird nichts sanitisiert. Beim Schreiben gilt: ASCII, keine Pfadtrenner, keine führenden Punkte, maximal 120 Zeichen Nutzanteil plus UUIDv7-Suffix, Gesamtlänge sanitized_filename maximal 160 Zeichen.

## 4. Übersicht Mapping-Version v1.0

| Mapping | Quelle | Ziel | Status v1.0 | Hochstufung auf v1.1 nach |
|---|---|---|---|---|
| M-CONTACT | vCard 3.0/4.0 | contacts, companies | draft, RFC-Standardfelder vollständig, Immoware24-X-Properties offen | Sichtung von mindestens 50 echten vCards aller Kontakt-Typen aus dem eigenen Mandanten |
| M-EVENT | VEVENT | calendar_events | draft, Phase 3 | Sichtung echter VEVENTs, Klärung VTODO |
| M-DOC | PROPFIND | documents | draft, Standard-Properties vollständig | Probe-Ergebnis (allprop, ETag-Stabilität, sync-token) |
| M-FOLDER | PROPFIND | document_folders | draft | Sichtung des tatsächlichen Ordnerumfangs |
| M-CSV-* | CSV-Auswertungen | properties, units, contacts, contact_roles, contracts, open_items | nicht Teil dieses Dokuments; key_schema und column_mapping werden erst nach Sichtung echter Exportdateien in import_formats angelegt (NICHT VERFÜGBAR) | Phase 0 |
| M-DATEV, M-CAMT | DATEV-CSV, CAMT.053 | transactions | nicht Teil dieses Dokuments, Phase 3 | Sichtung echter Dateien |

## 5. Normalisierung und Checksummen

### 5.1 vCard

1. Zeilenenden auf LF, Zeilenfaltung (RFC 6350 3.2) auflösen.
2. Property-Namen und Parameter-Namen in Großschreibung, Parameter-Werte unverändert (außer TYPE in Kleinschreibung).
3. Properties alphabetisch nach Name, dann nach Wert sortieren; Parameter innerhalb einer Property alphabetisch.
4. Ausschlussliste (nicht Teil der Checksumme): VERSION, PRODID, REV, UID (bereits Identität), X-ABUID, X-ABLABEL ohne Bezug, X-APPLE-*, X-MS-*, X-EVOLUTION-*, X-MOZILLA-*, X-THUNDERBIRD-*, X-RADICALE-*, X-SABREDAV-*. Alle sonstigen X-Properties bleiben in der Checksumme, bis Phase 0 sie einzeln bewertet hat.
5. PHOTO, LOGO, SOUND, KEY werden vor dem Hashen entfernt (Vermerk photo_present).
6. SHA-256 über das Ergebnis, UTF-8.

### 5.2 iCalendar

1. Zeilenenden auf LF, Faltung auflösen, VTIMEZONE-Block entfernen (Zeiten sind bereits nach UTC umgerechnet und werden als normalisierte DTSTART/DTEND-Werte in UTC eingesetzt).
2. Ausschlussliste: DTSTAMP, LAST-MODIFIED, CREATED, SEQUENCE, PRODID, VERSION, VALARM-Blöcke, X-APPLE-*, X-MS-*, X-MOZ-*, X-LIC-*.
3. Sortierung und Hash wie vCard.

### 5.3 documents

Checksumme = SHA-256 über content_hash, falls vorhanden, sonst über die Zeichenkette size_bytes plus Pipe plus remote_last_modified (ISO UTC). ETag ist nie Teil der Checksumme.

### 5.4 similarity_hash (contacts, nur Duplikatswarnung)

SHA-256 über: Nachname (Kleinschreibung, Unicode-Normalisierung NFKD, Diakritika entfernt, nur Buchstaben), erster Vorname gleich behandelt, Postleitzahl der ersten Adresse (nur Ziffern), erste E-Mail (Kleinschreibung). Fehlende Bestandteile als leerer String. Der Wert erzeugt ausschließlich Vorschläge vom Typ duplicate_candidate, nie eine Zusammenführung.

## 6. Offene Punkte Feldmapping

| Nr. | Punkt | Kennzeichnung |
|---|---|---|
| F1 | vCard-Version des Immoware24-CardDAV-Servers | NICHT VERFÜGBAR, zu verifizieren am eigenen Mandanten |
| F2 | Stabilität der vCard-UID über Änderungen hinweg | VERMUTET, zu verifizieren am eigenen Mandanten (zwei Läufe nach manueller Änderung eines Testkontakts) |
| F3 | Immoware24-spezifische X-Properties in vCard und VEVENT (Kontakt-Typ, Objekt, Einheit, Ticket, Debitorennummer) | NICHT VERFÜGBAR, Sichtung echter Nutzlasten |
| F4 | Aufteilung der Kontaktfreigabe in mehrere Adressbücher nach Kontakt-Typen | VERMUTET |
| F5 | Firmenkontakte als eigene vCard (KIND:org) oder nur über ORG | NICHT VERFÜGBAR |
| F6 | Vorhandensein von VTODO in der Kalenderfreigabe | NICHT VERFÜGBAR |
| F7 | Vom WebDAV-Server gelieferte Properties, insbesondere getetag und sync-token, sowie eigener Namespace | NICHT VERFÜGBAR, Probe |
| F8 | Exakte Schreibweise und Ebene der Ordner Posteingang und Dokumente, weitere Ordner | Namen VERIFIZIERT, Struktur zu verifizieren am eigenen Mandanten |
| F9 | Zeichensatz und Zeitzonenbehandlung des DAV-Servers (getlastmodified in GMT nach RFC 1123 erwartet) | zu verifizieren am eigenen Mandanten |
| F10 | Freigabe des Mappings v1.0 als Basis für den Bootstrap | Entscheidung Operator nach Phase 0, Testprotokoll im Repository |
