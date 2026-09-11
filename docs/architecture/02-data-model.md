# Datenmodell Immoware Hub

Stand: 11.09.2026
Zielsystem: MariaDB 10.11+, InnoDB, utf8mb4_bin für Hash- und Pfadspalten, utf8mb4_unicode_ci sonst
Gesellschaft: Hausverwaltung Müller GmbH
Dokumentstatus: Entwurf

Belegregel: Das Datenmodell ist Hub-intern und trifft keine eigenen Aussagen über Immoware24-Schnittstellen. Wo Spalten auf Eigenschaften des Immoware24-DAV-Servers oder der Exportdateien beruhen, ist der Belegstatus vermerkt. Insbesondere gilt: Spaltenformate der CSV-Exporte sind NICHT VERFÜGBAR und werden erst nach Sichtung echter Dateien in import_formats fixiert (zu verifizieren am eigenen Mandanten); Auth-Schema, ETag-Stabilität und sync-token-Unterstützung des DAV-Servers sind VERMUTET und werden per Probe gemessen; die einzige Schreiboperation webdav_create beruht auf dem VERIFIZIERTEN Upload in den Posteingang.

## Konventionen

- Primärschlüssel `id` BINARY(16), UUIDv7, sofern nicht anders angegeben.
- Zeitstempel `created_at`, `updated_at` DATETIME(6) NOT NULL, UTC.
- Hash-Verfahren: external_id_hash, path_hash, content_hash, row_hash, checksum, header_fingerprint sind reines SHA-256 (reproduzierbar, kein Pepper). iban_hash und base_url_hash sind HMAC-SHA256 mit geheimem Pepper (08-security.md Abschnitt 2.2).
- Herkunft-Block (H) auf allen Spiegel-Tabellen: `source_system` VARCHAR(32) NOT NULL (immoware24, hub), `connection_id` BINARY(16) FK immoware_connections, `external_id` VARCHAR(512) NOT NULL, `external_id_hash` CHAR(64) NOT NULL (SHA-256 von external_id), `checksum` CHAR(64) NOT NULL (SHA-256 des normalisierten Datensatzes), `sync_version` INT UNSIGNED NOT NULL DEFAULT 1, `identity_confidence` ENUM('exact','derived','uncertain') NOT NULL DEFAULT 'exact', `first_seen_at` DATETIME(6), `last_seen_at` DATETIME(6), `missing_since` DATETIME(6) NULL, `stale_since` DATETIME(6) NULL, `deleted_at` DATETIME(6) NULL, `deletion_reason` VARCHAR(64) NULL, `last_payload_id` BINARY(16) NULL FK external_payloads.
- Unique auf Spiegel-Tabellen immer (connection_id, external_id_hash), nie auf VARCHAR(512).
- Zeilen ohne vollständigen fachlichen Schlüssel (identity_confidence = uncertain) tragen external_id = "uncertain:" plus row_hash. Sie werden nicht versioniert; jeder Vollexport ersetzt die uncertain-Zeilen desselben Exporttyps und Objekts (deletion_reason = superseded_uncertain). Regel in 07-sync-strategy.md Abschnitt 7.
- Versionen: jede Spiegel-Tabelle hat eine Schattentabelle `<tabelle>_versions` mit allen fachlichen Spalten plus `version_id` BINARY(16) PK, `entity_id` BINARY(16), `sync_version` INT UNSIGNED, `valid_from` DATETIME(6), `valid_to` DATETIME(6) NULL, `payload_id` BINARY(16) NULL, `sync_event_id` BINARY(16) NULL. Unique (entity_id, sync_version). Index (entity_id, valid_from). Versionen entstehen nur bei Checksum-Änderung.
- Kein Hard Delete auf Spiegel-Tabellen. Hard Delete personenbezogener Felder erfolgt durch Überschreiben mit NULL und Vermerk in audit_logs. Dieselbe Regel gilt im selben Job für alle Zeilen der Schattentabelle `<tabelle>_versions` derselben entity_id (personenbezogene Spalten auf NULL, version_id, sync_version, valid_from, valid_to, payload_id bleiben, damit die Versionshistorie als Gerüst erhalten bleibt) sowie für conflicts.local_snapshot_json, conflicts.proposed_change_json, dlq_items.payload_json, webhook_outbox.payload_json und write_operations.original_filename, soweit sie auf dieselbe entity_id bzw. denselben Kontakt verweisen (Feldwerte durch Platzhalter "[erased]" ersetzt, Struktur bleibt). Löschkonzept in 08-security.md Abschnitt 7.3.
- Geldbeträge als BIGINT in Cent, Währung CHAR(3).

## A. Mandant, Verbindung, Fähigkeiten

### organizations
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| name | VARCHAR(200) NOT NULL | Hausverwaltung Müller GmbH |
| legal_entity_code | VARCHAR(32) NOT NULL | HVM, MHAG |
| settings | JSON | |
Unique: (legal_entity_code)

### immoware_technical_users
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| label | VARCHAR(120) NOT NULL | Arbeitsname, z. B. hub-read, hub-write |
| username | VARCHAR(200) NOT NULL | Immoware24-Benutzername (Benutzername = Immoware24-Benutzername, VERIFIZIERT, 04-authentication.md 2.3) |
| secret_encrypted | TEXT NULL | Freigabe-Passwort; gilt für alle Freigaben dieses Nutzers (VERIFIZIERT, Quellenzuordnung 04-authentication.md 2.3) |
| secret_rotated_at | DATETIME(6) NULL | |
| purpose | ENUM('read','write') NOT NULL | ein Nutzer trägt nur eine Richtung |
| breaker_state | ENUM('closed','open','half_open') NOT NULL DEFAULT 'closed' | Spiegel des Redis-Zustands; ein 401 auf einer Connection öffnet den Breaker für alle Connections dieses Nutzers |
| status | ENUM('active','paused','error') NOT NULL DEFAULT 'paused' | |
Unique: (organization_id, username). Index: (purpose, status)
Begründung: Das Freigabe-Passwort gilt je Nutzer, nicht je Freigabe. Rotation ändert genau eine Zeile; alle Connections des Nutzers folgen. Ein 401 auf der CardDAV-Connection sperrt damit auch die WebDAV-Connection desselben Nutzers (07-sync-strategy.md Abschnitt 6.2).

### immoware_connections
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| name | VARCHAR(120) NOT NULL | |
| connector_type | ENUM('webdav_documents','carddav_contacts','caldav_calendar','csv_export','datev_export','bank_file') NOT NULL | |
| base_url_encrypted | TEXT NOT NULL | Freigabe-Link aus dem Konfigurationsportal, verschlüsselt (Pfadschema nicht öffentlich belegt, VERMUTET) |
| base_url_hash | CHAR(64) NOT NULL | HMAC-SHA256 mit Pepper |
| auth_scheme | ENUM('unknown','basic','digest') NOT NULL DEFAULT 'unknown' | per Probe, nicht belegt |
| server_fingerprint | CHAR(64) NULL | Hash aus DAV-Header, Report-Set, Server-Header, Auth |
| probe_result | JSON NULL | etag_stable, sync_token, ctag, if_none_match, folders |
| last_probe_at | DATETIME(6) NULL | |
| technical_user_id | BINARY(16) FK immoware_technical_users NOT NULL | Zugangsdaten liegen am technischen Nutzer, nicht an der Connection |
| purpose | ENUM('read','write') NOT NULL DEFAULT 'read' | getrennte Nutzer |
| paired_read_connection_id | BINARY(16) FK immoware_connections NULL | Pflicht bei purpose = write: die Lese-Connection, in deren Spiegel (documents) Uploads geführt werden |
| write_enabled | TINYINT(1) NOT NULL DEFAULT 0 | |
| write_enabled_by | BINARY(16) NULL FK users | erste Person, Rolle admin |
| write_confirmed_by | BINARY(16) NULL FK users | zweite Person, Rolle release |
| write_approval_document_id | BINARY(16) NULL FK documents | GF-Freigabe |
| write_enabled_at | DATETIME(6) NULL | |
| allowed_write_prefix | VARCHAR(512) NULL | /Posteingang/ |
| max_upload_bytes | BIGINT UNSIGNED NOT NULL DEFAULT 26214400 | 25 MB, keine Immoware24-Angabe belegt |
| verify_with_hash | TINYINT(1) NOT NULL DEFAULT 1 | |
| poll_interval_seconds | INT UNSIGNED NOT NULL | |
| rate_limit_rps | DECIMAL(5,2) NOT NULL DEFAULT 2.00 | konservativ, Rate Limits NICHT VERFÜGBAR |
| max_concurrency_read | TINYINT UNSIGNED NOT NULL DEFAULT 2 | |
| max_concurrency_write | TINYINT UNSIGNED NOT NULL DEFAULT 1 | |
| status | ENUM('active','paused','degraded','error') NOT NULL DEFAULT 'paused' | |
| degraded_reason | VARCHAR(200) NULL | |
| degraded_cleared_by | BINARY(16) NULL FK users | |
| last_health_at | DATETIME(6) NULL | |
| last_health_ok | TINYINT(1) NULL | |
| last_health_result | JSON NULL | |
Unique: (organization_id, name), (base_url_hash, technical_user_id). Index: (connector_type, status), (technical_user_id)
Check (Trigger): purpose der Connection = purpose des technischen Nutzers; paired_read_connection_id NOT NULL, wenn purpose = write.
Check (Anwendung und Trigger): write_enabled = 1 nur wenn write_enabled_by (Rolle admin), write_confirmed_by (Rolle release), write_approval_document_id gesetzt und write_enabled_by <> write_confirmed_by. Rollenmodell mit genau diesen vier Werten von users.role ist verbindlich (08-security.md Abschnitt 4).

### capabilities
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| capability_key | VARCHAR(64) NOT NULL | webdav.list, webdav.read, webdav.create, webdav.overwrite, webdav.delete, webdav.move, carddav.read, carddav.write, caldav.read, caldav.write, csv.import.units, csv.import.contacts, csv.import.open_items, datev.import, camt.import |
| evidence_status | ENUM('VERIFIZIERT','DOKUMENTIERT','VERMUTET','NICHT_VERFUEGBAR') NOT NULL | |
| source_url | VARCHAR(1024) NULL | |
| enabled | TINYINT(1) NOT NULL DEFAULT 0 | |
| hard_locked | TINYINT(1) NOT NULL DEFAULT 0 | 1 für overwrite, delete, move, carddav.write, caldav.write |
| tested_at | DATETIME(6) NULL | |
| tested_by | BINARY(16) NULL FK users | |
| test_protocol | TEXT NULL | |
Unique: (connection_id, capability_key)
Trigger BEFORE UPDATE: enabled = 1 nur wenn evidence_status IN ('VERIFIZIERT','DOKUMENTIERT') AND tested_at IS NOT NULL AND hard_locked = 0, sonst SIGNAL.

Initialbelegung (Stand 11.09.2026): webdav.list, webdav.read, webdav.create = VERIFIZIERT; carddav.read, caldav.read = VERIFIZIERT für Existenz, Lesefähigkeit zu verifizieren am eigenen Mandanten; webdav.overwrite, webdav.delete, webdav.move = VERIFIZIERT als serverseitig möglich, hard_locked; carddav.write, caldav.write = VERMUTET, hard_locked; csv.import.*, datev.import, camt.import = DOKUMENTIERT (Dateiformat je nach Sichtung).

### export_schedules
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | csv_export oder datev_export |
| export_type | VARCHAR(64) NOT NULL | auswertung_mieter_ve, kontakte, op_liste, datev_buchungsstapel |
| responsible_user_id | BINARY(16) FK users NOT NULL | |
| interval_days | SMALLINT UNSIGNED NOT NULL | |
| last_import_at | DATETIME(6) NULL | |
| next_due_at | DATETIME(6) NULL | |
| reminder_sent_at | DATETIME(6) NULL | |
Unique: (connection_id, export_type). Index: (next_due_at)

## B. Stammdaten (Spiegel)

### properties
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| immoware_object_number | VARCHAR(64) NULL | |
| name | VARCHAR(200) NOT NULL | |
| management_type | ENUM('WEG','MIET','SEV','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN' | |
| street | VARCHAR(200) NULL | |
| house_number | VARCHAR(20) NULL | |
| postal_code | VARCHAR(10) NULL | |
| city | VARCHAR(120) NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (organization_id, immoware_object_number), (checksum), (deleted_at)

### buildings
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| property_id | BINARY(16) FK NOT NULL | |
| label | VARCHAR(200) NOT NULL | |
| address_json | JSON NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (property_id)

### units
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| property_id | BINARY(16) FK NOT NULL | |
| building_id | BINARY(16) FK NULL | |
| unit_number | VARCHAR(64) NOT NULL | VE-Nummer |
| unit_type | VARCHAR(40) NULL | Wohnung, Gewerbe, Stellplatz, Sonstiges |
| floor | VARCHAR(40) NULL | |
| living_area_sqm | DECIMAL(10,2) NULL | |
| co_ownership_share_numerator | DECIMAL(14,4) NULL | MEA |
| co_ownership_share_denominator | DECIMAL(14,4) NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (property_id, unit_number), (checksum)

### contacts
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| kind | ENUM('person','company') NOT NULL | |
| salutation | VARCHAR(40) NULL | |
| first_name | VARCHAR(120) NULL | |
| last_name | VARCHAR(120) NULL | |
| company_id | BINARY(16) FK NULL | |
| birth_date | DATE NULL | |
| emails | JSON NULL | Liste mit type, value |
| phones | JSON NULL | nur Ziffern normalisiert (Immoware24 akzeptiert nur Ziffern, DOKUMENTIERT) |
| addresses | JSON NULL | |
| vcard_uid | VARCHAR(255) NULL | |
| vcard_href | VARCHAR(1024) NULL | |
| vcard_rev | VARCHAR(40) NULL | Hinweis, keine Wahrheit |
| similarity_hash | CHAR(64) NULL | nur Warnung |
| merged_into_id | BINARY(16) NULL FK contacts | logischer Merge |
| personal_data_erased_at | DATETIME(6) NULL | DSGVO Hard Delete der Felder |
| H | | |
Unique: (connection_id, external_id_hash). Index: (organization_id, last_name, first_name), (vcard_uid), (similarity_hash), (merged_into_id), (checksum)

### contact_identifiers
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| contact_id | BINARY(16) FK NOT NULL | |
| kind | ENUM('email','phone') NOT NULL | |
| value_normalized | VARCHAR(254) NOT NULL | E-Mail: Kleinschreibung der Domain, lokaler Teil unverändert; Telefon: nur Ziffern und führendes Plus |
| is_preferred | TINYINT(1) NOT NULL DEFAULT 0 | |
Unique: (contact_id, kind, value_normalized). Index: (kind, value_normalized)
Zweck: normalisierte Nachschlagetabelle für die Filter email und phone auf /api/v1/contacts (09-api-documentation.md 3.3), damit bei 250.000 Kontakten kein Full Scan über die JSON-Spalten emails und phones nötig ist. Wird vom Mapper aus contacts.emails und contacts.phones abgeleitet und bei jeder Version neu geschrieben; unterliegt dem Löschkonzept wie contacts (Zeilen werden bei Pseudonymisierung gelöscht).

### contact_merges
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| source_contact_id | BINARY(16) FK NOT NULL | |
| target_contact_id | BINARY(16) FK NOT NULL | |
| merged_by | BINARY(16) FK users NOT NULL | |
| merged_at | DATETIME(6) NOT NULL | |
| reason | TEXT NULL | |
| undone_by | BINARY(16) FK users NULL | |
| undone_at | DATETIME(6) NULL | |
| is_active | TINYINT(1) GENERATED ALWAYS AS (IF(undone_at IS NULL, 1, NULL)) STORED | NULL bei rückgängig gemachtem Merge |
Unique: (source_contact_id, is_active) erzwingt genau einen aktiven Merge je Quellkontakt (NULL-Werte in Unique-Indizes gelten in InnoDB als verschieden, deshalb die generierte Spalte). Index: (target_contact_id)
Trigger AFTER INSERT/UPDATE: contacts.merged_into_id des Quellkontakts wird auf target_contact_id bzw. NULL gesetzt, damit beide Tabellen nicht auseinanderlaufen.

### companies
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| name | VARCHAR(200) NOT NULL | |
| register_number | VARCHAR(64) NULL | |
| addresses | JSON NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (organization_id, name)

### contact_roles
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| contact_id | BINARY(16) FK NOT NULL | |
| role | ENUM('tenant','owner','board_member','service_provider','bank','authority','other') NOT NULL | |
| property_id | BINARY(16) FK NULL | |
| unit_id | BINARY(16) FK NULL | |
| valid_from | DATE NULL | |
| valid_to | DATE NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (contact_id, role), (property_id, role), (unit_id, role, valid_from)

### contracts
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| unit_id | BINARY(16) FK NOT NULL | |
| contract_number | VARCHAR(64) NULL | |
| start_date | DATE NULL | |
| end_date | DATE NULL | |
| net_rent_cents | BIGINT NULL | |
| ancillary_cents | BIGINT NULL | |
| heating_cents | BIGINT NULL | |
| total_cents | BIGINT NULL | |
| currency | CHAR(3) NOT NULL DEFAULT 'EUR' | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (unit_id, start_date), (contract_number)

### contract_parties
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| contract_id | BINARY(16) FK NOT NULL | |
| contact_id | BINARY(16) FK NOT NULL | |
| party_role | ENUM('tenant','guarantor','landlord') NOT NULL | |
Unique: (contract_id, contact_id, party_role). Index: (contact_id)

### ownerships
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| unit_id | BINARY(16) FK NOT NULL | |
| contact_id | BINARY(16) FK NOT NULL | |
| share_numerator | DECIMAL(14,4) NULL | |
| share_denominator | DECIMAL(14,4) NULL | |
| valid_from | DATE NULL | |
| valid_to | DATE NULL | |
| house_money_cents | BIGINT NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (unit_id, valid_from), (contact_id)

### bank_accounts
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| owner_type | ENUM('contact','property','organization') NOT NULL | |
| owner_id | BINARY(16) NOT NULL | |
| iban_encrypted | TEXT NOT NULL | |
| iban_hash | CHAR(64) NOT NULL | HMAC-SHA256 mit Pepper |
| iban_last4 | CHAR(4) NOT NULL | |
| bic | VARCHAR(11) NULL | |
| account_holder | VARCHAR(200) NULL | |
| mandate_reference | VARCHAR(35) NULL | |
| H | | |
Unique: (owner_type, owner_id, iban_hash), (connection_id, external_id_hash). Index: (iban_hash)

## C. Dokumente

### document_folders
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| path | VARCHAR(2048) NOT NULL | NFC-normalisiert |
| path_hash | CHAR(64) NOT NULL | |
| parent_id | BINARY(16) FK NULL | |
| depth | SMALLINT UNSIGNED NOT NULL | |
| scan_priority | TINYINT UNSIGNED NOT NULL DEFAULT 3 | 1 Posteingang, 5 statisch |
| scan_interval_seconds | INT UNSIGNED NOT NULL | |
| content_policy | ENUM('metadata_only','hash_on_change','store') NOT NULL DEFAULT 'metadata_only' | Posteingang: hash_on_change |
| children_fingerprint | CHAR(64) NULL | Hash sortierte Kind-Liste |
| last_scanned_at | DATETIME(6) NULL | |
| last_change_seen_at | DATETIME(6) NULL | |
| writable_by_hub | TINYINT(1) NOT NULL DEFAULT 0 | nur Posteingang |
| missing_since | DATETIME(6) NULL | |
| deleted_at | DATETIME(6) NULL | |
Unique: (connection_id, path_hash). Index: (connection_id, scan_priority, last_scanned_at), (parent_id)

Hinweis: Welche Ordner der DAV-Server tatsächlich exponiert (mindestens Posteingang und Dokumente, VERIFIZIERT; weitere Ordner VERMUTET), ergibt sich erst aus der Probe. Die Tabelle wird durch den Scan befüllt, nicht vorbelegt.

### documents
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| folder_id | BINARY(16) FK NULL | |
| path | VARCHAR(2048) NOT NULL | relativ zur Freigabe |
| path_hash | CHAR(64) NOT NULL | = external_id_hash |
| filename | VARCHAR(512) NOT NULL | |
| content_type | VARCHAR(120) NULL | |
| size_bytes | BIGINT UNSIGNED NULL | |
| remote_etag | VARCHAR(255) NULL | |
| remote_last_modified | DATETIME(6) NULL | |
| content_hash | CHAR(64) NULL | null, wenn nie geladen |
| content_stored | TINYINT(1) NOT NULL DEFAULT 0 | |
| storage_key | VARCHAR(512) NULL | Blob-Speicher |
| property_id | BINARY(16) FK NULL | Hub-Zuordnung |
| unit_id | BINARY(16) FK NULL | |
| contact_id | BINARY(16) FK NULL | |
| case_id | BINARY(16) FK NULL | |
| origin | ENUM('remote','hub_upload') NOT NULL DEFAULT 'remote' | |
| write_operation_id | BINARY(16) FK NULL | bei hub_upload |
| H | external_id = path | |
Unique: (connection_id, path_hash).
Regel gegen Duplikate zwischen Lese- und Schreib-Connection: documents-Zeilen existieren ausschließlich auf Connections mit purpose = read. Ein erfolgreicher Upload (write_operations.status = succeeded) legt seine documents-Zeile mit connection_id = paired_read_connection_id der Schreib-Connection an (origin = hub_upload, write_operation_id gesetzt, path relativ zur Lese-Freigabe). Findet der nächste Posteingang-Scan der Lese-Connection dieselbe (connection_id, path_hash), aktualisiert der Reconciler diese Zeile (Metadaten, remote_etag) statt eine zweite anzulegen; origin und write_operation_id bleiben erhalten, es entsteht kein document.created, sondern höchstens document.updated. Findet der Scan eine Datei, deren (content_hash oder target_path_hash) zu einer write_operation in status verifying oder unknown passt, wird diese Operation über den Scan abgeschlossen. Voraussetzung ist, dass Lese- und Schreibfreigabe denselben Pfadraum abbilden; ist die Schreibfreigabe auf den Posteingang beschränkt, wird der Pfad über allowed_write_prefix auf die Lese-Freigabe abgebildet (Abbildung in Phase 0 zu verifizieren, sonst degraded). Index: (content_hash), (connection_id, folder_id), (remote_last_modified), (missing_since), (property_id), (unit_id), (contact_id), (case_id)
Partitionierung: keine, Zielgröße Millionen Zeilen ist mit den Indizes tragbar.

## D. Vorgänge und Buchhaltung (lesend)

### cases
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| property_id | BINARY(16) FK NULL | |
| unit_id | BINARY(16) FK NULL | |
| contact_id | BINARY(16) FK NULL | |
| title | VARCHAR(300) NOT NULL | |
| status | VARCHAR(40) NOT NULL | |
| immoware_ticket_reference | VARCHAR(64) NULL | manuell, kein Sync belegt (Ticketsystem als UI DOKUMENTIERT, Fremd-API NICHT VERFÜGBAR) |
| source_system | VARCHAR(32) NOT NULL DEFAULT 'hub' | |
| created_by | BINARY(16) FK users NULL | |
| deleted_at | DATETIME(6) NULL | |
Index: (property_id, status), (immoware_ticket_reference), (contact_id)

### invoices
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| property_id | BINARY(16) FK NULL | |
| creditor_contact_id | BINARY(16) FK NULL | |
| invoice_number | VARCHAR(64) NULL | |
| invoice_date | DATE NULL | |
| due_date | DATE NULL | |
| gross_cents | BIGINT NULL | |
| net_cents | BIGINT NULL | |
| vat_cents | BIGINT NULL | |
| currency | CHAR(3) NOT NULL DEFAULT 'EUR' | |
| document_id | BINARY(16) FK NULL | |
| H | | |
Unique: (connection_id, external_id_hash). Index: (property_id, invoice_date), (invoice_number)

### transactions
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| property_id | BINARY(16) FK NULL | |
| bank_account_id | BINARY(16) FK NULL | |
| kind | ENUM('ledger','bank') NOT NULL | DATEV bzw. CAMT |
| booking_date | DATE NULL | |
| value_date | DATE NULL | |
| amount_cents | BIGINT NOT NULL | |
| currency | CHAR(3) NOT NULL DEFAULT 'EUR' | |
| debit_account | VARCHAR(20) NULL | |
| credit_account | VARCHAR(20) NULL | |
| cost_center | VARCHAR(40) NULL | KOST2 (DOKUMENTIERT, immoware24.de/funktionen/datev) |
| document_field | VARCHAR(64) NULL | Belegfeld 1 |
| text | VARCHAR(500) NULL | |
| end_to_end_id | VARCHAR(35) NULL | |
| acct_svcr_ref | VARCHAR(64) NULL | CAMT |
| row_hash | CHAR(64) NOT NULL | Teil der external_id |
| occurrence_no | SMALLINT UNSIGNED NOT NULL DEFAULT 1 | Vorkommenszähler identischer row_hash innerhalb derselben Importdatei (1, 2, 3 ...); Teil der external_id, damit fachlich identische Zeilen (z. B. Sammelbuchungen) als Duplikat gespeichert werden und nicht am Unique scheitern |
| import_payload_id | BINARY(16) FK NOT NULL | external_payloads |
| H | | external_id = row_hash plus Trennzeichen plus occurrence_no |
Unique: (connection_id, external_id_hash). Index: (property_id, booking_date), (row_hash), (end_to_end_id), (import_payload_id)
Partitionierung: RANGE nach YEAR(booking_date) ab 100 Mio. Zeilen vorgesehen, nicht initial.

### open_items
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| property_id | BINARY(16) FK NULL | |
| unit_id | BINARY(16) FK NULL | |
| contact_id | BINARY(16) FK NULL | |
| kind | ENUM('receivable_rent','receivable_other','payable') NOT NULL | |
| due_date | DATE NULL | |
| amount_cents | BIGINT NOT NULL | |
| open_cents | BIGINT NOT NULL | |
| currency | CHAR(3) NOT NULL DEFAULT 'EUR' | |
| as_of_date | DATE NOT NULL | Stichtag des Exports |
| H | | |
Unique: (connection_id, external_id_hash, as_of_date). Index: (property_id, as_of_date), (contact_id, due_date)

## E. Synchronisation, Archiv, Nachvollziehbarkeit

### external_mappings
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| source_system | VARCHAR(32) NOT NULL | |
| connection_id | BINARY(16) FK NOT NULL | |
| entity_type | VARCHAR(40) NOT NULL | |
| external_id | VARCHAR(512) NOT NULL | |
| external_id_hash | CHAR(64) NOT NULL | |
| entity_id | BINARY(16) NOT NULL | Hub-PK |
| identity_confidence | ENUM('exact','derived','uncertain') NOT NULL | |
| created_by | ENUM('sync','bootstrap','manual') NOT NULL | |
| created_by_user_id | BINARY(16) FK users NULL | |
| confirmed_at | DATETIME(6) NULL | |
Unique: (source_system, connection_id, entity_type, external_id_hash). Index: (entity_type, entity_id), (created_by)

### external_payloads
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| sync_run_id | BINARY(16) FK NULL | |
| payload_type | ENUM('vcard','ical','propfind_xml','options_response','csv_file','csv_row','datev_file','camt_file','http_response','probe') NOT NULL | |
| external_id_hash | CHAR(64) NULL | |
| content_hash | CHAR(64) NOT NULL | |
| content_inline | MEDIUMBLOB NULL | bis 64 KB |
| storage_key | VARCHAR(512) NULL | Blob-Speicher, EU |
| size_bytes | BIGINT UNSIGNED NOT NULL | |
| http_status | SMALLINT NULL | |
| remote_etag | VARCHAR(255) NULL | |
| remote_last_modified | DATETIME(6) NULL | |
| import_metadata | JSON NULL | Exporttyp, Objekt, Vollexport, Mitarbeiter |
| received_at | DATETIME(6) NOT NULL | |
| contains_personal_data | TINYINT(1) NOT NULL DEFAULT 0 | |
| pseudonymized_at | DATETIME(6) NULL | Inhalt gelöscht, Hash bleibt |
| dedup_hash | CHAR(64) GENERATED ALWAYS AS (IF(payload_type IN ('csv_file','datev_file','camt_file') AND pseudonymized_at IS NULL, content_hash, HEX(id))) STORED | Duplikatsperre nur für Importdateien |
Unique: (connection_id, payload_type, dedup_hash). Byteidentische PROPFIND-, OPTIONS-, Probe- und vCard-Antworten werden je Lauf neu archiviert, damit sync_run_id und last_payload_id auf den aktuellen Lauf zeigen. Für Importdateien gilt die Duplikatsperre nur, solange der Altdatensatz nicht pseudonymisiert ist; nach Pseudonymisierung darf eine identische Datei erneut aufgenommen werden. Index: (external_id_hash, received_at), (sync_run_id), (received_at), (contains_personal_data, pseudonymized_at)
Partitionierung: RANGE COLUMNS(received_at) monatlich.

### sync_states
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| scope | ENUM('collection','resource') NOT NULL | |
| collection_path_hash | CHAR(64) NOT NULL | |
| resource_external_id_hash | CHAR(64) NOT NULL DEFAULT '' | leer bei collection |
| strategy | ENUM('sync_token','ctag_etag','etag_only','lastmodified_size_hash','full_hash','file_snapshot') NULL | nur bei collection, aus Probe |
| sync_token | VARCHAR(1024) NULL | |
| ctag | VARCHAR(255) NULL | |
| etag | VARCHAR(255) NULL | |
| last_modified | DATETIME(6) NULL | |
| size_bytes | BIGINT UNSIGNED NULL | |
| content_hash | CHAR(64) NULL | |
| last_seen_run_id | BINARY(16) NULL | |
| consecutive_missing | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| cursor_json | JSON NULL | opaque |
Unique: (connection_id, scope, collection_path_hash, resource_external_id_hash). Index: (connection_id, last_seen_run_id), (connection_id, consecutive_missing)

### sync_runs
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| run_type | ENUM('incremental','full_reconcile','probe','bootstrap_dry_run','bootstrap_accept','replay') NOT NULL | |
| trigger_source | ENUM('schedule','manual','recovery') NOT NULL | |
| phase | ENUM('discover','fetch','archive','map','reconcile','finalize','done','failed','aborted') NOT NULL | |
| started_at | DATETIME(6) NOT NULL | |
| finished_at | DATETIME(6) NULL | |
| health_ok_before | TINYINT(1) NULL | Bedingung für Soft Delete |
| counters | JSON NOT NULL | requests, unchanged, meta_noise, created, updated, moved, soft_deleted, conflicts, errors, bytes |
| cursor_before | JSON NULL | |
| cursor_after | JSON NULL | |
| error_summary | TEXT NULL | |
| started_by | BINARY(16) FK users NULL | |
Index: (connection_id, started_at), (phase)

### sync_events
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| sync_run_id | BINARY(16) FK NOT NULL | |
| connection_id | BINARY(16) FK NOT NULL | |
| entity_type | VARCHAR(40) NOT NULL | |
| entity_id | BINARY(16) NOT NULL | |
| action | ENUM('created','updated','unchanged_meta_noise','soft_deleted','moved','moved_probable','conflict','restored') NOT NULL | |
| detected_by | ENUM('etag','ctag','sync_token','lastmodified','size','hash','missing','header_fingerprint','folder_fingerprint','manual') NOT NULL | |
| old_checksum | CHAR(64) NULL | |
| new_checksum | CHAR(64) NULL | |
| payload_id | BINARY(16) FK NULL | |
| occurred_at | DATETIME(6) NOT NULL | |
Index: (entity_type, entity_id, occurred_at), (sync_run_id), (connection_id, occurred_at)
Partitionierung: RANGE COLUMNS(occurred_at) monatlich.

### import_formats
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| format_key | VARCHAR(64) NOT NULL | auswertung_mieter_ve, kontakte, op_liste, datev_buchungsstapel, camt053 |
| version | INT UNSIGNED NOT NULL | |
| status | ENUM('draft','active','retired') NOT NULL DEFAULT 'draft' | |
| header_fingerprint | CHAR(64) NOT NULL | |
| header_columns | JSON NOT NULL | Originalspalten |
| delimiter | CHAR(1) NULL | |
| charset | VARCHAR(20) NULL | |
| column_mapping | JSON NOT NULL | |
| key_schema | JSON NOT NULL | Spalten der external_id |
| sample_payload_id | BINARY(16) FK NULL | |
| confirmed_by | BINARY(16) FK users NULL | |
| confirmed_at | DATETIME(6) NULL | |
Unique: (format_key, version), (header_fingerprint). Index: (format_key, status)

Hinweis: Es gibt keine Vorbelegung. Spaltenformate der Immoware24-Exporte sind NICHT VERFÜGBAR; jede Formatversion entsteht aus einer real gesichteten Datei (Phase 0).

### conflicts
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| sync_run_id | BINARY(16) FK NULL | |
| entity_type | VARCHAR(40) NOT NULL | |
| entity_id | BINARY(16) NULL | |
| conflict_type | ENUM('uncertain_identity','duplicate_external','duplicate_candidate','moved_probable','local_change_vs_remote','source_mismatch','format_mismatch','write_verify_failed','write_target_exists','write_unknown_unresolved','proposed_change') NOT NULL | source_mismatch: zwei Immoware24-Quellen widersprechen sich (z. B. CardDAV gegen CSV) |
| remote_payload_id | BINARY(16) FK NULL | |
| local_snapshot_json | JSON NULL | |
| proposed_change_json | JSON NULL | target, field, old_value, new_value, reason |
| status | ENUM('open','in_progress','resolved_keep_remote','resolved_keep_local','resolved_merge','resolved_applied_manually','dismissed') NOT NULL DEFAULT 'open' | |
| assigned_to | BINARY(16) FK users NULL | |
| resolved_by | BINARY(16) FK users NULL | |
| resolved_at | DATETIME(6) NULL | |
| resolution_note | TEXT NULL | |
| confirmed_by_sync_run_id | BINARY(16) FK NULL | Hash-Bestätigung nach manueller Umsetzung |
| occurrences | INT UNSIGNED NOT NULL DEFAULT 1 | Anzahl der Läufe, die denselben offenen Konflikt erneut festgestellt haben |
| last_seen_run_id | BINARY(16) FK NULL | letzter Lauf, der den Konflikt erneut festgestellt hat |
| last_seen_at | DATETIME(6) NULL | |
| open_key | TINYINT(1) GENERATED ALWAYS AS (IF(status IN ('open','in_progress'), 1, NULL)) STORED | NULL bei abgeschlossenen Konflikten |
Unique: (entity_type, entity_id, conflict_type, open_key) erzwingt höchstens einen offenen Konflikt je Datensatz und Typ. Index: (status, created_at), (entity_type, entity_id), (conflict_type, status), (assigned_to, status)
Regel: Stellt ein Lauf einen Konflikt fest, für den bereits ein offener Eintrag mit gleichem (entity_type, entity_id, conflict_type) existiert, wird dieser aktualisiert (occurrences + 1, last_seen_run_id, last_seen_at, remote_payload_id auf die aktuelle Nutzlast), es wird kein neuer Eintrag angelegt. proposed_change ist davon ausgenommen (je Vorschlag ein Eintrag, entity_id plus field im proposed_change_json).

### write_operations
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| connection_id | BINARY(16) FK NOT NULL | |
| idempotency_key | CHAR(64) NOT NULL | SHA-256(connection_id, content_hash, intent_key); target_path ist nicht Bestandteil (05-write-capabilities.md 3.1) |
| intent_key | CHAR(64) NOT NULL | Idempotency-Key des API-Aufrufers bzw. SHA-256 des fachlichen Bezugs (source_document_id, case_id) oder Hub-UUID je UI-Antrag |
| operation | ENUM('webdav_create') NOT NULL | einziger erlaubter Wert |
| target_path | VARCHAR(2048) NOT NULL | |
| target_path_hash | CHAR(64) NOT NULL | |
| original_filename | VARCHAR(512) NULL | |
| sanitized_filename | VARCHAR(160) NOT NULL | |
| content_hash | CHAR(64) NOT NULL | |
| size_bytes | BIGINT UNSIGNED NOT NULL | |
| source_storage_key | VARCHAR(512) NOT NULL | Inhalt im Blob-Speicher bis succeeded |
| source_document_id | BINARY(16) FK NULL | |
| case_id | BINARY(16) FK NULL | |
| status | ENUM('queued','precheck','sent','unknown','verifying','succeeded','skipped_exists','failed','failed_verify') NOT NULL DEFAULT 'queued' | |
| precheck_result | JSON NULL | |
| http_status | SMALLINT NULL | |
| verify_result | JSON NULL | length, hash_match |
| precheck_attempts | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| verify_attempts | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| put_attempts | TINYINT UNSIGNED NOT NULL DEFAULT 0 | maximal 1, Trigger prüft |
| requested_by | BINARY(16) FK users NOT NULL | |
| requested_via | ENUM('ui','api_key','system') NOT NULL | |
| sent_at | DATETIME(6) NULL | |
| verified_at | DATETIME(6) NULL | |
| failed_at | DATETIME(6) NULL | |
Unique: (idempotency_key). Index: (connection_id, status), (target_path_hash), (status, sent_at), (connection_id, content_hash) zur Duplikatswarnung
Trigger BEFORE UPDATE: put_attempts darf 1 nicht überschreiten; Wechsel von sent oder unknown zurück auf queued oder precheck ist verboten.

### dlq_items
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| job_class | VARCHAR(200) NOT NULL | |
| connection_id | BINARY(16) FK NULL | |
| payload_json | JSON NOT NULL | |
| exception | TEXT NOT NULL | |
| failed_at | DATETIME(6) NOT NULL | |
| replayed_at | DATETIME(6) NULL | |
| replayed_by | BINARY(16) FK users NULL | |
| replay_result | VARCHAR(40) NULL | |
Index: (connection_id, failed_at), (replayed_at)

## F. Sicherheit, Audit, Ausgang

### users
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| name | VARCHAR(200) NOT NULL | |
| email | VARCHAR(254) NOT NULL | |
| password_hash | VARCHAR(255) NOT NULL | Argon2id |
| role | ENUM('viewer','operator','admin','release') NOT NULL | |
| totp_secret_encrypted | TEXT NULL | |
| totp_confirmed_at | DATETIME(6) NULL | Login ohne 2FA nur bis Einrichtung |
| recovery_codes_hashed | JSON NULL | |
| last_login_at | DATETIME(6) NULL | |
| disabled_at | DATETIME(6) NULL | |
Unique: (email). Index: (organization_id, role)

### api_keys
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| name | VARCHAR(120) NOT NULL | |
| key_prefix | CHAR(8) NOT NULL | |
| key_hash | VARCHAR(255) NOT NULL | Argon2id |
| scopes | JSON NOT NULL | documents:read, contacts:read, units:read, documents:create |
| allowed_ips | JSON NULL | |
| expires_at | DATETIME(6) NULL | |
| last_used_at | DATETIME(6) NULL | |
| revoked_at | DATETIME(6) NULL | |
| created_by | BINARY(16) FK users NOT NULL | |
Unique: (key_prefix). Index: (organization_id, revoked_at)

### webhook_endpoints
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| organization_id | BINARY(16) FK NOT NULL | |
| name | VARCHAR(120) NOT NULL | |
| url | VARCHAR(1024) NOT NULL | |
| secret_encrypted | TEXT NOT NULL | |
| events | JSON NOT NULL | |
| active | TINYINT(1) NOT NULL DEFAULT 0 | |
Unique: (organization_id, name)

### webhook_outbox
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| event_type | VARCHAR(80) NOT NULL | |
| entity_type | VARCHAR(40) NULL | |
| entity_id | BINARY(16) NULL | |
| payload_json | JSON NOT NULL | |
| occurred_at | DATETIME(6) NOT NULL | |
| dispatched_at | DATETIME(6) NULL | |
Index: (dispatched_at, occurred_at)

### webhook_deliveries
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| endpoint_id | BINARY(16) FK NOT NULL | |
| outbox_id | BINARY(16) FK NOT NULL | |
| signature | CHAR(64) NOT NULL | HMAC-SHA256 |
| attempts | TINYINT UNSIGNED NOT NULL DEFAULT 0 | |
| status | ENUM('pending','delivered','failed') NOT NULL DEFAULT 'pending' | |
| next_attempt_at | DATETIME(6) NULL | |
| delivered_at | DATETIME(6) NULL | |
| last_response_code | SMALLINT NULL | |
Unique: (endpoint_id, outbox_id). Index: (status, next_attempt_at)

### audit_logs (append-only)
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | monotone Reihenfolge |
| occurred_at | DATETIME(6) NOT NULL | |
| actor_type | ENUM('user','api_key','system') NOT NULL | |
| actor_id | BINARY(16) NULL | |
| action | VARCHAR(80) NOT NULL | |
| entity_type | VARCHAR(40) NULL | |
| entity_id | BINARY(16) NULL | |
| connection_id | BINARY(16) NULL | |
| before_json | JSON NULL | |
| after_json | JSON NULL | |
| request_id | CHAR(36) NULL | |
| ip_address_hash | CHAR(64) NULL | HMAC-SHA256 der Quell-IP mit geheimem Pepper; keine Klartext-IP im Audit, weil audit_logs append-only und unbefristet ist. Klartext-IP nur im Anwendungslog mit 30 Tagen Aufbewahrung |
| prev_hash | CHAR(64) NOT NULL | |
| row_hash | CHAR(64) NOT NULL | SHA-256(prev_hash, alle Felder) |
Index: (entity_type, entity_id), (actor_type, actor_id, occurred_at), (occurred_at), (connection_id, occurred_at)
Rechte: Anwendungs-DB-Nutzer hat INSERT und SELECT, kein UPDATE, kein DELETE. Trigger BEFORE UPDATE und BEFORE DELETE mit SIGNAL.

### audit_anchors
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| last_audit_id | BIGINT UNSIGNED NOT NULL | |
| root_hash | CHAR(64) NOT NULL | |
| exported_at | DATETIME(6) NOT NULL | |
| external_location | VARCHAR(512) NOT NULL | Object-Lock-Speicher |
Unique: (last_audit_id)

### hub_decision_backups
| Spalte | Typ | Hinweis |
|---|---|---|
| id | BINARY(16) PK | |
| taken_at | DATETIME(6) NOT NULL | |
| tables_included | JSON NOT NULL | conflicts, contact_merges, external_mappings (manual, bootstrap), import_formats, capabilities, users, api_keys, webhook_endpoints |
| storage_key | VARCHAR(512) NOT NULL | |
| content_hash | CHAR(64) NOT NULL | |
| restore_tested_at | DATETIME(6) NULL | |
| restore_tested_by | BINARY(16) FK users NULL | |
Index: (taken_at)

## G. Kapazität und Betrieb

- 50.000 units, 250.000 contacts: unkritisch, alle Unique-Indizes auf Hash-Spalten fester Länge.
- documents im Millionenbereich: Unique auf (connection_id, path_hash), keine Indizes auf VARCHAR(2048).
- external_payloads und sync_events monatlich partitioniert, Blobs über 64 KB außerhalb der Datenbank.
- Versionstabellen wachsen nur bei tatsächlicher Checksum-Änderung.
- Aufbewahrung: sync_events und sync_runs 24 Monate, external_payloads bis Pseudonymisierung bzw. 10 Jahre für buchhaltungsrelevante Dateien (mit Steuerberater abstimmen), audit_logs unbefristet.
- Lastannahme Phase 1 bei 2 Requests pro Sekunde: Posteingang-Scan unter 1 Minute, Kontakt-Vollabgleich 4.600 Kontakte unter 2 Minuten, wöchentlicher Full Reconcile des DMS abhängig von der Ordneranzahl, geplant für das Nachtfenster 22:00 bis 05:00 Europe/Berlin (7 Stunden, 07-sync-strategy.md Abschnitt 1.5). Rate Limits seitens Immoware24 sind NICHT VERFÜGBAR; die Annahmen sind eigene konservative Setzungen und werden gegen sync_runs.counters nachgewiesen.

## Offene Punkte

| Punkt | Kennzeichnung |
|---|---|
| Spaltenformat, Trennzeichen, Zeichensatz der CSV-Auswertungen und DATEV-Dateien | zu verifizieren am eigenen Mandanten (Phase 0), danach import_formats Version 1 |
| Stabilität der vCard UID und der iCalendar UID über den DAV-Adapter | zu verifizieren am eigenen Mandanten |
| Auth-Schema des DAV-Servers (auth_scheme) | zu verifizieren per Probe |
| Ordnerumfang per WebDAV (document_folders) | zu verifizieren per Probe |
| Maximale Uploadgröße seitens Immoware24 (max_upload_bytes) | WAITING_FOR_VENDOR_ACCESS, bis dahin 25 MB als eigene Grenze |
