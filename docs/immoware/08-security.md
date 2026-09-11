# 08 Sicherheit, Datenschutz und Berechtigungen im Immoware Hub

Stand: 11.09.2026
Bezug: Architekturentscheidung Abschnitte 1, 3.3, 6 und 7; Datenmodell Tabellen users, api_keys, immoware_connections, capabilities, write_operations, audit_logs, audit_anchors, external_payloads, hub_decision_backups; Dokumente 04-authentication.md und 05-write-capabilities.md.

Regeln: Nur eigener autorisierter Zugang zum Immoware24-Mandanten der Hausverwaltung Müller GmbH. Keine Umgehung von Authentifizierung oder 2FA. Kein Scraping als Datenbankersatz. Jede Aussage über Immoware24 trägt ihren Belegstatus (VERIFIZIERT, DOKUMENTIERT, VERMUTET, NICHT VERFÜGBAR). Aussagen über den Hub selbst sind Designentscheidungen und tragen keinen Belegstatus.

## 0. Ergebnis

1. Der Hub besitzt genau zwei Klassen von Geheimnissen: die DAV-Zugangsdaten der technischen Immoware24-Nutzer und die Hub-eigenen Anmeldedaten (Passwörter, TOTP-Geheimnisse, API-Keys, Webhook-Secrets). Beide liegen nie im Klartext in Datenbank, Log oder Konfigurationsdatei.
2. Anmeldung am Hub nur mit Pflicht-2FA (TOTP). API-Zugriff nur mit gehashten, gescopten, befristeten Schlüsseln.
3. Vier Rollen, identisch mit users.role im Datenmodell und mit AP 1.3 des Implementierungsplans: release (Geschäftsführung bzw. deren Vertretung, in diesem Dokument auch Owner genannt), admin (Administrator), operator (Operator), viewer (Read Only). Der einzige Schreibpfad gegen Immoware24 erfordert zwei Personen (erste Person admin, zweite Person release, niemals dieselbe Person) und ein hinterlegtes Freigabedokument der Geschäftsführung. Eine Erweiterung um developer und api_client ist zurückgestellt (Entscheidung frühestens Phase 4); API-Keys sind eigene Akteure (audit_logs.actor_type = api_key) und keine Nutzerrolle.
4. Auditlog append-only mit Hash-Kette, wöchentlich verankert.
5. Datenschutz: Datensparsamkeit bei Kontaktdaten, Löschkonzept mit Pseudonymisierung, Verzeichnis der Verarbeitungstätigkeiten, AV-Verträge. Finanzdaten in einer eigenen Schutzstufe.
6. KI-Assistenten (MCP oder vergleichbar) erhalten in Phase 1 bis 3 keinen Zugriff. Sobald sie angebunden werden, gilt der Permission-Layer aus Abschnitt 8: lesen mit Scopes, jede Schreib- oder Freigabehandlung ausschließlich durch einen Menschen.

## 1. Belegstand zu Immoware24, soweit für Sicherheit relevant

| Aussage | Quelle | Status | Konsequenz |
|---|---|---|---|
| DAV-Adapter nutzt je Nutzer ein separates Freigabe-Passwort: "Das Passwort sollte von Ihrem Immoware24-Passwort abweichen und gilt für alle Ihre Freigaben." | Anleitung zum DAV-Adapter (© 2023 Immoware24 GmbH) und https://support.immoware24.de/hc/de/articles/360010768217-Ger%C3%A4te-f%C3%BCr-die-Freigabe-einrichten | VERIFIZIERT (Snippet gesehen) | Ein Geheimnis je technischem Nutzer, Rotation trifft alle Freigaben dieses Nutzers |
| Reset des Freigabe-Passworts erfordert Anpassung in allen Synchronisationen des Nutzers | https://support.immoware24.de/hc/de/articles/360010887358-Kalender-und-Kontakte-unter-Android-einbinden | DOKUMENTIERT (in der Gegenprüfung nicht gesichtet, logische Folge des Kernsatzes) | Rotationsprozess behandelt alle Connections eines technischen Nutzers gemeinsam |
| Kein App-Passwort-Konzept mit mehreren Token pro Nutzer oder Einzelwiderruf dokumentiert; kein 2FA-Bezug des DAV-Passworts erwähnt | Negativbefund, support.immoware24.de gesperrt | NICHT VERFÜGBAR | Hub behandelt das DAV-Passwort als geteiltes Geheimnis ohne Einzelwiderruf |
| Auth-Verfahren des DAV-Endpunkts (Basic oder Digest) | keine Quelle | NICHT VERFÜGBAR | Probe in Phase 0, Credential-Store verfahrensneutral |
| 2FA am Web-Login per Authenticator-App, 6-stelliger Code, Wiederherstellungscodes; Aktivierung durch Administrator unter Einstellungen/Sicherheit | https://support.immoware24.de/hc/de/articles/15389245891357-Zwei-Faktor-Authentifizierung-aktivieren | DOKUMENTIERT (Snippet) | Web-Login wird nie automatisiert, 2FA bleibt für alle Nutzer aktiv |
| Nutzerrollen SYS: Administrator, SYS: Standard, SYS: nur Lesezugriff, SYS: nur Lesezugriff Stammdaten; Nutzerzugänge legt nur Administrator an | https://support.immoware24.de/hc/de/articles/360010771138-Immoware24-Nutzerrollen | DOKUMENTIERT (Snippet) | Kleinste Rolle für technische Nutzer wählen; welche Rolle DAV-Freigaben tragen darf: NICHT VERFÜGBAR, zu verifizieren am eigenen Mandanten |
| AGB: "Der Kunde hat die für Identifizierung und Authentifizierung notwendigen Daten und Passwörter vor dem Zugriff durch Dritte zu schützen und nicht an unberechtigte Nutzer weiterzugeben." und Verpflichtung, Server, Onlinesoftware und Netzwerk nicht zu schädigen | https://www.immoware24.de/agb/ | DOKUMENTIERT (Snippet), Wortlaut nicht im Original geprüft | Technische Nutzer sind benannte Nutzer der HVM, keine Weitergabe an Dritte; Lastbegrenzung ist Pflicht |
| Explizite Regelung zu automatisiertem Zugriff in den AGB | Snippets ohne Text | NICHT VERFÜGBAR, WAITING_FOR_VENDOR_ACCESS | Schriftliche Bestätigung des Supports und Prüfung durch Rechtsanwalt vor Produktivbetrieb |
| Hosting Immoware24: "zertifizierte Hochsicherheitsrechenzentren in Deutschland", keine Daten verlassen die EU. Art der Zertifizierung (z. B. ISO 27001) und Transportverschlüsselung sind im Snippet nicht genannt (NICHT VERFÜGBAR) | https://www.immoware24.de/funktionen/sicherheit/ | DOKUMENTIERT (Snippet) | Hub-Hosting ebenfalls EU, bevorzugt Deutschland |
| DMS-Papierkorb wird nach 7 Tagen automatisiert geleert | https://www.immoware24.de/agb/ | DOKUMENTIERT (Snippet) | Fehluploads binnen 7 Tagen manuell bereinigen |
| Immoware24 ist Auftragsverarbeiter der HVM, AV-Vertrag | keine Quelle gesichtet | NICHT VERFÜGBAR | AV-Vertrag mit Immoware24 durch Rechtsanwalt prüfen lassen, insbesondere Weitergabe an den Hub-Hoster |

## 2. Credential-Management

### 2.1 Inventar der Geheimnisse

| Geheimnis | Speicherort | Schutz | Rotation | Eigentümer |
|---|---|---|---|---|
| DAV-Freigabe-Passwort technischer Lesenutzer | immoware_technical_users.secret_encrypted (ein Datensatz je Nutzer, gilt für alle seine Connections) | App-Level-Encryption (2.2) | halbjährlich und bei Verdacht | Administrator |
| DAV-Freigabe-Passwort technischer Schreibnutzer | immoware_technical_users.secret_encrypted | App-Level-Encryption | halbjährlich und bei Verdacht | Administrator, Freigabe Owner |
| Freigabe-Link (enthält ggf. nutzerspezifische Pfadanteile) | immoware_connections.base_url_encrypted, base_url_hash | App-Level-Encryption | bei Neuanlage der Freigabe | Administrator |
| Hub-Passwörter | users.password_hash | Argon2id | Nutzerentscheidung, Zwang bei Verdacht | Nutzer |
| TOTP-Geheimnisse | users.totp_secret_encrypted | App-Level-Encryption | bei Geräteverlust | Nutzer |
| Wiederherstellungscodes | users.recovery_codes_hashed | Argon2id je Code, einmalig | bei Verbrauch | Nutzer |
| API-Keys | api_keys.key_hash, key_prefix | Argon2id, Klartext nur einmal bei Erzeugung sichtbar | expires_at Pflicht, maximal 12 Monate | Administrator |
| Webhook-Secrets | webhook_endpoints.secret_encrypted | App-Level-Encryption | jährlich | Administrator |
| Datenbank-, Redis-, Blob-Zugangsdaten | Secret-Store der Plattform (Vault oder KMS-Secret), nicht in .env im Repository | Plattform | jährlich | Administrator |
| Master-Schlüssel der App-Level-Encryption | KMS oder HashiCorp Vault Transit, nicht auf dem Anwendungsserver | HSM oder KMS | jährlich, Re-Encryption-Job | Owner |

Verbot: Kein Geheimnis in Git, in Logs, in Fehlermeldungen, in Audit-Einträgen, in Webhook-Payloads oder in API-Antworten. Logging-Middleware maskiert Authorization-Header, Query-Parameter mit Schlüsseln und JSON-Felder mit den Namen password, secret, token, key.

### 2.2 App-Level-Encryption

- Verfahren: AES-256-GCM mit zufälliger 96-Bit-Nonce je Datensatz. Ciphertext-Format: version (1 Byte), key_id (16 Byte), nonce (12 Byte), ciphertext, tag (16 Byte), Base64.
- Schlüsselhierarchie: Der Datenschlüssel (DEK) verschlüsselt die Spalten. Der DEK liegt selbst nur verschlüsselt (KEK) vor. Der KEK liegt im KMS oder in Vault Transit und verlässt es nicht. Entschlüsselung des DEK beim Anwendungsstart in den Prozessspeicher, nie auf Platte.
- Laravel: Eigene Cast-Klasse EncryptedString auf Basis von sodium_crypto_aead_aes256gcm bzw. libsodium xchacha20poly1305, nicht der Standard-Encrypter mit APP_KEY, weil APP_KEY in der Anwendungs-ENV liegt und damit dieselbe Vertrauenszone wie die Datenbankzugangsdaten teilt.
- key_id im Ciphertext erlaubt Rotation ohne Downtime: neuer DEK wird aktiv, ein Artisan-Kommando hub:reencrypt schreibt Datensätze mit altem key_id um, der alte DEK wird nach Abschluss aus dem KMS gelöscht.
- Hash-Spalten, Festlegung je Spalte: iban_hash und base_url_hash sind HMAC-SHA256 mit einem geheimen, im KMS liegenden Pepper (Schutz gegen Wörterbuchangriffe auf IBANs und Freigabe-Links; der Pepper wird nicht rotiert, ohne dass ein Re-Hash-Job hub:rehash die betroffenen Spalten neu berechnet). external_id_hash, path_hash, content_hash, row_hash, checksum und header_fingerprint sind reines SHA-256 ohne Pepper, weil sie Unique-Schlüssel der Spiegeltabellen und Grundlage der Replay-Reproduzierbarkeit sind und keine geheimen Inhalte tragen (Datenmodell Konventionen).
- Datenbank zusätzlich mit Transparent Data Encryption (MariaDB Encryption at Rest) und verschlüsselten Backups. Das ersetzt die Spaltenverschlüsselung nicht, sondern ergänzt sie.

### 2.3 Rotation der DAV-Zugangsdaten

Folge des belegten Verhaltens (VERIFIZIERT): Ein Reset des Freigabe-Passworts gilt für alle Freigaben des Nutzers. Ablauf:

1. Administrator setzt im Hub alle Connections des technischen Nutzers auf paused (alle Jobs laufen leer, keine Uploads).
2. Am Konfigurationsportal https://config.dav.immoware24.de/login meldet sich der technische Nutzer an und vergibt unter Meine Freigaben ein neues Passwort (Schaltfläche "Passwort generieren" laut Snippet, VERIFIZIERT). Der Klartext wird direkt in den Hub übertragen, nicht per E-Mail oder Chat.
3. Hub speichert das neue Geheimnis verschlüsselt in immoware_technical_users.secret_encrypted (eine Zeile, wirkt auf alle Connections des Nutzers), setzt secret_rotated_at, führt je Connection einen Health-Check aus.
4. Erst bei erfolgreichem Health-Check wechseln die Connections zurück auf active. Bei 401 bleiben sie paused (Rotationskontext); außerhalb einer Rotation gilt für 401 das Leitdokument 07-sync-strategy.md Abschnitt 6 (Breaker öffnet, status = error).
5. Audit-Eintrag mit action = connection.secret_rotated ohne Klartext.

Bei Verdacht auf Kompromittierung zusätzlich: Freigabe im Konfigurationsportal löschen und neu anlegen (Freigaben sind nicht editierbar, VERIFIZIERT), da so ein neuer Freigabe-Link entsteht.

### 2.4 Zwei technische Nutzer

Lesen und Schreiben erfolgen mit getrennten Immoware24-Nutzern der Hausverwaltung Müller GmbH (immoware_connections.purpose = read bzw. write). Die Nutzer sind benannte Konten der HVM, nicht Konten Dritter; damit ist die AGB-Anforderung zur Weitergabe an unberechtigte Nutzer aus Sicht der HVM eingehalten (rechtliche Prüfung durch Rechtsanwalt bleibt erforderlich). Ob der Schreibnutzer eine auf den Posteingang beschränkte Dateifreigabe erhalten kann, ist VERMUTET und Testpunkt in Phase 0. Für die technischen Nutzer bleibt 2FA am Web-Login aktiv; der TOTP-Faktor liegt bei einer benannten Person, nicht im Hub.

## 3. Authentifizierung am Hub

### 3.1 Passwort und 2FA

- Passwort: Argon2id (memory 64 MB, time 3, parallelism 1), Mindestlänge 12, Prüfung gegen bekannte kompromittierte Passwörter (lokale Liste, keine externe Abfrage mit Klartext).
- 2FA: TOTP nach RFC 6238, 30 Sekunden, 6 Stellen, Toleranz eine Periode. Pflicht für alle vier Rollen (API-Keys sind keine Nutzer und melden sich nicht an der UI an). Login ohne bestätigtes TOTP nur bis zur Einrichtung (users.totp_confirmed_at) und nur für die Einrichtungsseite.
- Wiederherstellungscodes: 10 Codes, einmalig, Argon2id-gehasht, Anzeige nur bei Erzeugung. Verbrauch wird auditiert.
- Sitzungen: HttpOnly, Secure, SameSite=Strict, Ablauf 8 Stunden absolut, 30 Minuten inaktiv. Re-Authentifizierung mit TOTP vor sicherheitskritischen Aktionen (write_enabled setzen, API-Key anlegen, Rolle ändern, degraded aufheben).
- Sperre: 10 Fehlversuche in 15 Minuten sperren das Konto für 15 Minuten, Audit-Eintrag, Benachrichtigung an Administratoren.
- WebAuthn/FIDO2 als zweiter Faktor ist vorgesehen, aber nicht Phase 1.

### 3.2 API-Keys

- Format: Prefix (8 Zeichen, Klartext, Tabelle api_keys.key_prefix) plus 32 Byte Zufall, Base64url. Beispiel-Schema: hub_live_<prefix>_<secret>. Der Hub speichert nur Argon2id(secret).
- Übergabe: Header Authorization: Bearer hub_live_.... Kein Schlüssel in Query-Strings.
- Pflichtfelder: name, scopes, expires_at (maximal 12 Monate), erstellende Person. Optional allowed_ips (CIDR-Liste).
- Widerruf sofort wirksam (revoked_at). Letzte Nutzung wird protokolliert (last_used_at, gerundet auf Minute, um Schreiblast zu begrenzen).
- Jeder Key gehört genau einer Organisation und wird von einem Nutzer der Rolle admin angelegt (api_keys.created_by). Keys sind eigene Akteure (audit_logs.actor_type = api_key) und erhalten nur Scopes, die admin vergeben darf; Schreib- oder Freigabescopes für Immoware24 außer documents:create existieren nicht.
- Rate Limit je Key: 600 Requests pro Minute lesend, 60 pro Minute für documents:create. Bei Überschreitung 429 mit Retry-After.
- Ausgabe von Keys erst ab Phase 4 (Architekturentscheidung Abschnitt 9). In Phase 1 bis 3 existiert die Tabelle, aber kein aktiver Key.

## 4. Rollen

Verbindlich sind die vier Rollen viewer, operator, admin, release aus der Architekturentscheidung, dem Datenmodell (users.role) und dem Implementierungsplan (AP 1.3). Die Klarnamen in der ersten Spalte sind Anzeigenamen. Die Zeilen Developer und API Client beschreiben keine Rollen der Phase 1 bis 3, sondern zurückgestellte Erweiterungen; bis zu einer Entscheidung in Phase 4 gilt: Entwicklungszugriff erfolgt ausschließlich in einer Staging-Umgebung mit Rolle admin, API-Keys werden von admin angelegt und sind durch Scopes, Ablauf und IP-Allowlist begrenzt, ohne eigene Nutzerrolle.

| Rolle | Zweck | Darf | Darf nicht | users.role |
|---|---|---|---|---|
| Owner | Geschäftsführung bzw. deren Vertretung, zweite Person im Vier-Augen-Prinzip | Alles lesen; write_enabled als zweite Person bestätigen; degraded auf active zurücksetzen; Rollen admin und release vergeben (ausschließlich release darf diese beiden Rollen vergeben); Freigabedokument hinterlegen; Audit-Anker prüfen | Keine technische Konfiguration im Alltag; nicht dieselbe Person wie write_enabled_by; darf write_enabled nicht als erste Person beantragen | release |
| Administrator | Technischer Betrieb | Connections, Capabilities, Formate, Nutzer der Rollen viewer und operator, API-Keys, Webhooks verwalten; Rotation; write_enabled als erste Person beantragen; Replay und Restore auslösen | Freigabe des Schreibpfads allein; Rollen admin oder release vergeben oder eigene Rolle ändern; Audit-Einträge ändern (technisch unmöglich); Hard-Lock aufheben (technisch unmöglich) | admin |
| Developer (zurückgestellt, keine Rolle in Phase 1 bis 3) | Entwicklung und Integration | Bis zur Entscheidung in Phase 4: Rolle admin ausschließlich in Staging | Produktiv-Connections ändern; Produktiv-Keys anlegen; Konflikte fachlich entscheiden | keine eigene Ausprägung |
| Operator | Fachliche Sachbearbeitung | Importe hochladen, Konflikte und proposed_change bearbeiten, Merges (merged_into_id) durchführen und rückgängig machen, Uploads in den Posteingang beantragen, Export-Erinnerungen quittieren | Connections, Capabilities, Nutzer, Keys ändern; Formatversionen aktivieren | operator |
| Read Only | Einsicht | Spiegel lesen, Datenalter und Status sehen, Reports exportieren, Audit lesen (ohne before_json/after_json personenbezogener Felder) | Jede schreibende Aktion, Payload-Rohdaten | viewer |
| API Client (zurückgestellt, keine Nutzerrolle) | Technischer Akteur für Fremdsysteme | Nur, was die Scopes des Keys erlauben; Key wird von admin angelegt und ist kein users-Datensatz | Login in die UI; Aktionen ohne Scope | actor_type api_key im Audit, kein users.role |

Grundsätze:
- Rollen sind exklusiv, ein Nutzer hat genau eine Rolle. Bedarf an zwei Rollen wird über zwei Konten gelöst, damit das Vier-Augen-Prinzip nicht durch Rollenhäufung unterlaufen wird.
- Datenbank-Trigger und Anwendungslogik prüfen write_enabled_by <> write_confirmed_by, Rolle admin beim Beantragenden und Rolle release beim Bestätigenden. Zwei release-Nutzer ohne admin können den Schreibpfad nicht freigeben, ein admin kann ohne release nicht freigeben.
- Rollenvergabe: Die Rollen admin und release werden ausschließlich durch einen Nutzer der Rolle release vergeben oder entzogen (PATCH /api/v1/users/{id}/role, 09-api-documentation.md Abschnitt 3.10). admin vergibt nur viewer und operator. Damit kann ein admin nicht durch Anlage eines zweiten Kontos mit Rolle release die Schreibfreigabe allein herbeiführen.
- Rollenwechsel und Kontosperren werden auditiert und lösen einen Sitzungsabbruch aus.

## 5. Scopes

Scopes gelten für API-Keys und, gespiegelt, für den KI/MCP-Permission-Layer. Schreibscopes existieren nur für den einzigen belegten Schreibpfad.

| Scope | Umfang | Standard für Rolle |
|---|---|---|
| properties:read | Objekte, Gebäude | Read Only, Operator, API Client (auf Antrag) |
| units:read | Verwaltungseinheiten | wie oben |
| contacts:read | Kontakte ohne Bankdaten, ohne Geburtsdatum | Operator, API Client (auf Antrag, DSGVO-Zweck dokumentieren) |
| contacts:read_sensitive | Geburtsdatum, vollständige Adresshistorie | nur Operator, nie API Client in Phase 4 |
| contracts:read | Verträge, Mieten | Operator, Read Only |
| ownerships:read | Eigentumsverhältnisse, Hausgeld | Operator, Read Only |
| documents:read | Metadaten der Dokumente | Operator, Read Only, API Client |
| documents:download | Inhalt on-demand über WebDAV bzw. Blob | Operator, API Client (auf Antrag) |
| documents:create | Upload-Antrag in den Posteingang über write_operations | API Client nur nach Freigabe des Schreibpfads, Operator |
| finance:read | Buchungen, Umsätze, offene Posten, Bankkonten (IBAN nur last4) | Schutzstufe hoch, siehe 7.5 |
| finance:read_iban | vollständige IBAN | nie API Client, Operator nur mit Zweckangabe |
| cases:read, cases:write | Hub-eigene Vorgänge | Operator |
| conflicts:read, conflicts:resolve | Konfliktqueue | Operator |
| proposals:create | proposed_change anlegen | Operator, API Client (auf Antrag) |
| sync:read | sync_runs, sync_events, Datenalter | Administrator, API Client |
| sync:trigger | Sync manuell starten | Administrator |
| admin:connections, admin:users, admin:keys, admin:formats | Verwaltung | Administrator |
| audit:read | Auditlog | Owner, Administrator, Read Only (eingeschränkt) |
| webhooks:manage | Endpunkte | Administrator |

Es gibt keine Scopes contacts:write, units:write, finance:write, calendar:write, documents:delete, documents:update. Sie werden weder angelegt noch reserviert; ein API-Aufruf mit unbekanntem Scope wird bei der Key-Anlage abgewiesen.

## 6. Auditlog

- Tabelle audit_logs, append-only. Anwendungs-DB-Nutzer hat INSERT und SELECT, kein UPDATE, kein DELETE. Trigger BEFORE UPDATE und BEFORE DELETE mit SIGNAL. Migrationen laufen mit einem separaten DB-Nutzer.
- Hash-Kette: row_hash = SHA-256(prev_hash || kanonisches JSON aller Felder ohne row_hash). prev_hash der ersten Zeile ist 64 Nullen. Kettenprüfung per Artisan-Kommando hub:audit:verify, täglich im Scheduler, Ergebnis im Health-Endpoint.
- Verankerung: Wöchentlich schreibt hub:audit:anchor die letzte id und den Kettenwert nach audit_anchors und exportiert ihn in einen Object-Lock-Speicher (WORM, Aufbewahrung 10 Jahre). Ab Phase 2 täglich.
- Pflichtereignisse: Login, Logout, fehlgeschlagener Login, 2FA-Einrichtung, Wiederherstellungscode verbraucht, Rollenänderung, Nutzer angelegt oder gesperrt, API-Key angelegt, genutzt (erste Nutzung je Tag), widerrufen, Connection angelegt oder geändert, Secret rotiert, write_enabled beantragt und bestätigt, degraded gesetzt und aufgehoben, Capability geändert, Formatversion aktiviert, jede write_operation mit jedem Statuswechsel, jede Konfliktentscheidung, jeder Merge und Undo, jeder Replay, jede Pseudonymisierung, jeder Download eines Dokuments oder Payloads, jeder Export.
- Inhalt: before_json und after_json ohne Geheimnisse; personenbezogene Felder werden im Audit nur als Feldname plus Hash gespeichert, nicht als Wert, damit das Löschkonzept (7.3) nicht am Audit scheitert. Die Quell-IP-Adresse ist ein personenbezogenes Datum und wird ausschließlich als HMAC-SHA256 mit Pepper gespeichert (audit_logs.ip_address_hash); Gleichheitsvergleiche bleiben möglich, eine Rückrechnung nicht.
- Zugriff: Rolle Read Only sieht Audit ohne before_json/after_json. Owner und Administrator sehen alles.
- Aufbewahrung: unbefristet (Architekturentscheidung Abschnitt G), mit Rechtsanwalt gegen Löschpflichten der DSGVO abzuwägen; deshalb Hash statt Wert bei personenbezogenen Feldern.

## 7. Datenschutz

### 7.1 Rollen nach DSGVO

Verantwortliche für die Kontaktdaten von Mietern, Eigentümern und Dienstleistern ist die Hausverwaltung Müller GmbH. Immoware24 GmbH ist Auftragsverarbeiter (AV-Vertrag vorhanden: NICHT VERFÜGBAR, zu prüfen). Der Hoster des Hubs ist ein weiterer Auftragsverarbeiter. Ob die Müller Holding AG Daten aus dem Hub erhält, ist fachlich festzulegen; ohne Festlegung erhält sie keinen Zugriff, weil der Hub keine mandantenübergreifende Sicht vorsieht (organizations.legal_entity_code).

### 7.2 Datensparsamkeit

- CardDAV: Nur die Adressbücher, die für den Hub freigegeben werden. Ob die Kontaktfreigabe nach Kontakt-Typen aufgeteilt werden kann, ist VERMUTET; wenn ja, werden nur die benötigten Typen freigegeben.
- vCard-Normalisierung entfernt Foto (PHOTO), Notizen (NOTE) und Geburtsdatum (BDAY), sofern kein Fachzweck besteht. Standard: BDAY wird nicht gespeichert.
- CSV-Importe: column_mapping in import_formats bestimmt, welche Spalten übernommen werden. Nicht gemappte Spalten bleiben nur im archivierten Payload (external_payloads) und unterliegen dort der Pseudonymisierung.
- Dokumente: Standard metadata_only. Inhalte werden nur gespeichert, wenn content_policy = store, und nur für definierte Ordner.
- Bankdaten: IBAN verschlüsselt, Anzeige nur last4, vollständige IBAN nur mit Scope finance:read_iban und Zweckangabe, auditiert.
- Keine Übernahme von Freitextfeldern aus Immoware24 mit potenziell besonderen Kategorien (Gesundheit, Religion) außer als Dokumentreferenz.

### 7.3 Löschkonzept

| Datenart | Auslöser | Frist (Default, mit Steuerberater und Rechtsanwalt abzustimmen) | Verfahren |
|---|---|---|---|
| Kontakt ohne aktive Rolle | contact_roles.valid_to überschritten und keine offene Forderung | 12 Monate | Soft Delete, danach Hard Delete personenbezogener Felder durch Überschreiben mit NULL, personal_data_erased_at, Audit mit Hash |
| Kontakt mit buchhalterischem Bezug | letzte Buchung | 10 Jahre ab Ende des Kalenderjahres (steuerliche Aufbewahrung, allgemein formuliert, Frist zu verifizieren) | Pseudonymisierung erst nach Ablauf |
| external_payloads mit contains_personal_data | Ablauf der Frist des zugehörigen Datensatzes | wie Datensatz | Inhalt gelöscht, content_hash bleibt, pseudonymized_at gesetzt; Replay dieses Datensatzes ist danach unmöglich und wird im Audit vermerkt |
| Dokumente mit content_stored | Löschung im DMS (Soft Delete im Spiegel) | 90 Tage nach deleted_at | Blob löschen, Metadaten bleiben |
| Schattentabellen <tabelle>_versions | gemeinsam mit dem Hauptdatensatz | wie Hauptdatensatz | Personenbezogene Spalten aller Versionen derselben entity_id auf NULL, Versionsgerüst (version_id, sync_version, valid_from, valid_to, payload_id) bleibt |
| conflicts.local_snapshot_json, conflicts.proposed_change_json | gemeinsam mit dem Kontakt bzw. Datensatz | wie Hauptdatensatz | Feldwerte durch "[erased]" ersetzt, Struktur und Entscheidung bleiben |
| dlq_items.payload_json | Alter oder Pseudonymisierung des Datensatzes | 90 Tage nach failed_at bzw. sofort bei Pseudonymisierung | payload_json auf Minimalform (job_class, IDs) reduziert |
| webhook_outbox.payload_json | dispatched_at | 30 Tage nach Zustellung an alle Endpunkte | Zeile löschen; Payloads enthalten ohnehin nur IDs, Typ, Link und Herkunft (09-api-documentation.md 5.3) |
| write_operations.original_filename | Pseudonymisierung des verknüpften Dokuments oder Kontakts | wie Hauptdatensatz | original_filename auf NULL, sanitized_filename bleibt (enthält nur ASCII-Nutzanteil und UUID) |
| audit_logs.ip_address_hash | nie (append-only) | unbefristet | Nur HMAC-Hash mit Pepper, keine Klartext-IP; Klartext-IP nur im Anwendungslog, 30 Tage |
| sync_events, sync_runs | Alter | 24 Monate | Partition droppen |
| audit_logs | nie | unbefristet | personenbezogene Werte nur als Hash |
| Hub-Nutzer | disabled_at | 6 Monate | Name durch Kennung ersetzen, E-Mail durch eindeutigen Pseudonymwert ersetzen (deleted-<uuid>@invalid), weil users.email NOT NULL und Unique ist; totp_secret_encrypted und recovery_codes_hashed auf NULL |
| Backups | Rotation | 35 Tage täglich, 12 Monate monatlich | Löschungen im Live-System propagieren sich mit Ablauf der Backup-Rotation; das ist im Verzeichnis der Verarbeitungstätigkeiten so zu dokumentieren |

Betroffenenrechte: Auskunft wird über den Hub als Export aller Datensätze eines contact_id einschließlich Versionen erzeugt (Scope contacts:read_sensitive, Operator). Löschverlangen werden zuerst in Immoware24 (Master) umgesetzt; der Hub folgt beim nächsten Sync mit Soft Delete und dann dem Löschkonzept. Eine Löschung nur im Hub ist fachlich sinnlos und wird nicht angeboten.

### 7.4 Systeme mit AV-Vertrag (AVV)

| System | Rolle | Status |
|---|---|---|
| Immoware24 GmbH | Auftragsverarbeiter der HVM, Master-System | AVV vermutlich Bestandteil des Vertrags, NICHT VERFÜGBAR, prüfen lassen |
| Hoster des Hubs (Datenbank, Redis, Anwendungsserver) | Auftragsverarbeiter | AVV vor Inbetriebnahme abschließen, Standort EU, bevorzugt Deutschland |
| Blob-Speicher (S3-kompatibel) | Auftragsverarbeiter | AVV, Standort EU, Object Lock für Audit-Anker |
| KMS oder Vault-Betreiber, falls extern | Auftragsverarbeiter | AVV |
| E-Mail-Versand für Erinnerungen und Alarme | Auftragsverarbeiter, falls extern | AVV; Inhalte ohne personenbezogene Daten Dritter (nur Exporttyp, Fälligkeit) |
| Monitoring und Fehlertracking | Auftragsverarbeiter | AVV; PII-Scrubbing aktiv, keine Payloads in Traces |
| KI-Anbieter (Phase nach 4) | Auftragsverarbeiter | AVV, EU-Verarbeitung, kein Training mit HVM-Daten, siehe Abschnitt 8 |

Das Verzeichnis der Verarbeitungstätigkeiten wird um den Eintrag "Immoware Hub" ergänzt (Zwecke: Spiegelung zur Nachvollziehbarkeit, Dokumentenzuführung, Auswertung; Kategorien; Empfänger; Fristen; TOMs). Eine Datenschutz-Folgenabschätzung ist zu prüfen, weil systematisch Daten von mehreren tausend Personen zusammengeführt werden; Entscheidung durch Datenschutzbeauftragten oder Rechtsanwalt.

### 7.5 Finanzdaten, Schutzstufe hoch

Betroffene Tabellen: bank_accounts, transactions, open_items, invoices, contracts (Mieten), ownerships (Hausgeld), external_payloads vom Typ datev_file, camt_file.

- Zugriff nur mit Scope finance:read; API Client erhält ihn in Phase 4 nur für aggregierte Sichten (Summen je Objekt), nicht für Einzelumsätze, bis ein konkreter Konsument mit Zweck benannt ist.
- IBAN nur verschlüsselt, Anzeige last4, Volltext nur mit finance:read_iban.
- DATEV- und CAMT-Dateien liegen ausschließlich im Blob-Speicher mit eigener Bucket-Policy, nicht inline in der Datenbank.
- Export von Finanzdaten aus dem Hub (CSV, API) wird einzeln auditiert mit Zeilenanzahl.
- Kein Schreibpfad: Der Hub erzeugt keine SEPA-Dateien, keine Buchungen, keine Kontoauszüge für Immoware24. Zahlungsverkehr bleibt im Banking-Client und in der Immoware24-UI (DOKUMENTIERT).
- Trennung nach Gesellschaft: Finanzdaten tragen organization_id; eine Sicht über HVM und MHAG hinweg gibt es nicht.

## 8. KI/MCP-Permission-Layer

Stand: Kein KI-Assistent ist in Phase 1 bis 3 an den Hub angebunden. Der MCP-Layer aus dem Entwurf extensibility-first wird erst nach stabilem Schreibpfad gebaut (Architekturentscheidung, Einleitung). Dieser Abschnitt legt die Regeln vorab fest, damit keine spätere Lockerung ohne Dokumentänderung möglich ist.

Grundsätze:
1. Ein KI-Assistent ist technisch ein API Client mit eigenem Key und eigenen Scopes. Er hat nie mehr Rechte als der Mensch, der ihn steuert.
2. Jede Aktion, die Zustand in Immoware24 oder Freigabezustand im Hub verändert, wird vom Assistenten höchstens vorbereitet (Entwurf, proposed_change, write_operations in Status queued mit requested_via = api_key) und von einem Menschen mit TOTP-Re-Authentifizierung ausgelöst.
3. Der Assistent erhält Daten nur im Umfang seiner Scopes und mit data_age_seconds, stale_since, source_status und Belegstatus, damit er Datenalter und Herkunft in seine Ausgabe übernimmt.
4. Ausgaben des Assistenten an Dritte (E-Mail, Brief) sind Entwürfe. Der Hub versendet nichts.
5. Kein Training, keine Speicherung von HVM-Daten beim KI-Anbieter über die Anfrage hinaus; EU-Verarbeitung; AVV.

| Aktion | Erlaubt für KI (mit Scope) | Nie autonom, immer Mensch | Begründung |
|---|---|---|---|
| Spiegel lesen (Objekte, Einheiten, Verträge, Dokument-Metadaten) | ja | | Lesen ohne Wirkung auf Immoware24 |
| Kontakte lesen | ja, ohne contacts:read_sensitive | | Datensparsamkeit |
| Finanzdaten lesen | nur Aggregate | Einzelumsätze, IBAN | Schutzstufe hoch |
| Dokumentinhalt herunterladen | nur mit documents:download und Zweckangabe im Request | | auditiert |
| Zusammenfassung, Auswertung, Berichtsentwurf | ja | | Ergebnis ist Text, keine Zustandsänderung |
| proposed_change anlegen | ja (proposals:create) | Umsetzung in Immoware24 | Human-in-the-Loop-Rückweg |
| Upload in den Posteingang | Entwurf anlegen (write_operations queued) | Freigabe des Uploads, sobald requested_via = api_key | einziger Schreibpfad, Livesystem |
| Konflikt entscheiden | Vorschlag mit Begründung | Entscheidung | fachliche Verantwortung |
| Kontakte zusammenführen | Duplikatswarnung | Merge und Undo | Identität ist fachliche Entscheidung |
| Sync starten oder Cursor verwerfen | nein | ja | Lastprofil, Vendor-Beziehung |
| write_enabled setzen, degraded aufheben | nein | ja, zwei Personen | Vier-Augen-Prinzip |
| Connections, Capabilities, Formate ändern | nein | ja | Konfiguration mit Wirkung auf Immoware24 |
| Nutzer, Rollen, API-Keys verwalten | nein | ja | Rechteverwaltung |
| Löschen oder Pseudonymisieren | nein | ja | DSGVO-Entscheidung |
| E-Mail oder Brief versenden | nein, nur Entwurf | ja | Unternehmensregel: keine selbstständige Versendung |
| Fristen berechnen | nur als Orientierung mit Kennzeichnung | Eintragung und Verifikation | Notfristen nie allein auf KI-Berechnung |
| Zugriff auf Immoware24 direkt (WebDAV, CardDAV, Web-UI) | nie | | KI sieht nur den Hub, nie die Zugangsdaten |

Technische Durchsetzung: MCP-Server als eigener Prozess mit eigenem API-Key, ohne Datenbankzugang, nur über die Hub-API (09-api-documentation.md). Tool-Definitionen des MCP-Servers enthalten keine Werkzeuge für die Spalte "nie autonom". Prompt-Injection-Schutz: Inhalte aus Immoware24 (Dokumentnamen, Kontaktfelder, Betreffzeilen) werden dem Assistenten als Daten markiert übergeben; der MCP-Server führt keine Anweisungen aus, die in Daten stehen.

## 9. Technische und organisatorische Maßnahmen (Kurzliste)

- Netzwerk: Hub nur über HTTPS (TLS 1.2 und höher, HSTS), ausgehend nur zu den Immoware24-DAV-Hosts, dem Blob-Speicher, dem KMS und den registrierten Webhook-Endpunkten (Egress-Allowlist). Admin-UI zusätzlich hinter VPN oder IP-Allowlist.
- Methoden-Guard im HTTP-Client (05-write-capabilities.md) als Code-Ebene unabhängig von Konfiguration.
- Abhängigkeiten: composer audit im CI, Renovate, keine unsignierten Pakete.
- Secrets-Scanning im CI (gitleaks), Pre-Commit-Hook.
- Backups verschlüsselt, Restore-Test quartalsweise, getrennt für Vollrestore und Replay-Restore; hub_decision_backups täglich.
- Incident-Prozess: Bei Verdacht auf Kompromittierung eines DAV-Passworts Connection paused, Rotation nach 2.3, Prüfung der write_operations seit dem Verdachtszeitpunkt, Meldung an Geschäftsführung, Prüfung der Meldepflicht nach DSGVO durch Rechtsanwalt oder Datenschutzbeauftragten binnen der gesetzlichen Frist (allgemein: kurzfristig, Frist zu verifizieren).
- Schulung: Operatoren werden auf die Wirkung des Posteingang-Uploads im Livesystem und die 7-Tage-Papierkorbfrist hingewiesen.

## 10. Offene Punkte

| Punkt | Kennzeichnung | Nächster Schritt |
|---|---|---|
| Auth-Verfahren des DAV-Endpunkts (Basic, Digest) | zu verifizieren am eigenen Mandanten | Probe Phase 0 |
| Welche Immoware24-Rolle darf DAV-Freigaben tragen | zu verifizieren am eigenen Mandanten | Test mit SYS: nur Lesezugriff |
| Freigabe auf Ordner Posteingang beschränkbar | zu verifizieren am eigenen Mandanten | Test durch admin im Konfigurationsportal |
| Verlangt das Konfigurationsportal 2FA für admin | zu verifizieren am eigenen Mandanten | Anmeldung durch berechtigten Mitarbeiter |
| Zulässigkeit automatisierter DAV-Nutzung durch Serveranwendung laut AGB und Support | WAITING_FOR_VENDOR_ACCESS | Schriftliche Anfrage über den eigenen Mandantenzugang, Antwort im Repository ablegen |
| AV-Vertrag mit Immoware24, Unterauftragsverarbeiter | WAITING_FOR_VENDOR_ACCESS | Vertragsunterlagen sichten, Rechtsanwalt |
| Exportregelung bei Vertragsende in den AGB | zu verifizieren (AGB-Original) | Wortlaut sichern, Exit-Strategie anpassen |
| Aufbewahrungsfristen Finanzdaten | mit Steuerberater abstimmen | Fristen im Löschkonzept fixieren |
| Datenschutz-Folgenabschätzung erforderlich | Entscheidung Datenschutzbeauftragter oder Rechtsanwalt | vor Phase 1 |
| Erweiterung von users.role um developer und api_client | zurückgestellt | Entscheidung frühestens Phase 4; bis dahin vier Rollen verbindlich |
