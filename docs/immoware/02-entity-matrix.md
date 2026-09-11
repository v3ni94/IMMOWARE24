# 02 Entity-Matrix: Immoware24-Entitäten und Hub-Entitäten

Stand: 11.09.2026
Projekt: Immoware Hub (Laravel 12, PHP 8.4, MariaDB 10.11+), Auftraggeber Hausverwaltung Müller GmbH
Führendes System: Immoware24. Der Hub ist ein versionierter Spiegel mit genau einem Schreibpfad (WebDAV create-only in den Posteingang).

## 0. Lesehinweise

Jede Aussage über Immoware24 trägt einen Belegstatus:

| Status | Bedeutung |
|---|---|
| VERIFIZIERT | Offizielle Immoware24-Quelle (immoware24.de, support.immoware24.de, content.immoware24.de, config.dav.immoware24.de), Wortlaut in einem WebSearch-Snippet oder per Fetch tatsächlich gesehen. Ein nahezu wörtlich gesehener Kernsatz zählt, eine sinngemäße Zusammenfassung nicht. |
| DOKUMENTIERT | Quelle mit URL, die die Aussage trägt: offizielle Quelle, deren Wortlaut nur einmal gesehen, in der Gegenprüfung nicht reproduziert oder nur als Snippet-Paraphrase vorliegt, oder glaubwürdige Drittquelle. |
| VERMUTET | Plausibel, aber nicht durch gesichtete Quelle gedeckt, oder Interpretation über den Quellentext hinaus. Vor Nutzung am eigenen Mandanten zu verifizieren. |
| NICHT VERFÜGBAR | Negativbefund: kein Beleg gefunden. Keine offizielle Aussage, dass die Funktion fehlt. Gilt auch für alle Aussagen der Form "ohne API", "nicht dokumentiert", "keine Angabe". |

Diese Definition ist für alle Dokumente des Repositories verbindlich (Leitdefinition in README.md). Abweichende Kurzfassungen in Einzeldokumenten sind durch diese Tabelle ersetzt.

Hinweis zur Prüflage: Die Hosts www.immoware24.de, support.immoware24.de, content.immoware24.de und config.dav.immoware24.de sind aus der Recherche-Umgebung per Fetch gesperrt (EGRESS_BLOCKED). Alle Belege stammen aus WebSearch-Snippets. Wo ein Zitat nur als Paraphrase gesehen wurde, ist dies vermerkt. Alle Punkte mit Kennzeichnung "zu verifizieren am eigenen Mandanten" sind Phase-0-Testpunkte. Punkte mit WAITING_FOR_VENDOR_ACCESS erfordern eine Antwort des Immoware24-Supports oder Vertriebs.

Belegte Zugangswege (Kurzfassung, Details in 00-Belegstand der Architekturentscheidung):

| Zugangsweg | Status | Richtung |
|---|---|---|
| WebDAV auf DMS (mindestens Ordner Posteingang und Dokumente) | VERIFIZIERT | lesen, Upload belegt, Overwrite und Delete wirken im Livesystem |
| CardDAV Kontaktfreigabe | VERIFIZIERT (Existenz), Schreibrichtung unklar | lesen (plausibel), schreiben nicht eingeplant |
| CalDAV Kalenderfreigabe | VERIFIZIERT (Existenz), Schreibrichtung unklar | lesen (plausibel), schreiben nicht eingeplant |
| CSV-Export der Auswertungen (Export-Button) | DOKUMENTIERT, Spaltenformat NICHT VERFÜGBAR | lesen, manuell ausgelöst |
| DATEV-CSV-Buchungsexport | DOKUMENTIERT | lesen, manuell ausgelöst |
| CAMT.053 v02/v08, MT940 STA (Kontoumsatzdateien) | DOKUMENTIERT | Datei, nur Anzeige im Hub |
| HeiWaKo/bved DTA (B/K, L/M, D, E898) | DOKUMENTIERT | Datei, optional Phase 3 |
| REST-API, Webhooks, API-Keys | NICHT VERFÜGBAR | kein Baustein darf darauf bauen |
| UI-Funktionen (Ticketsystem, Portal24, E-Post, craftware24, KI-Anrufbeantworter, Serienbriefe) | DOKUMENTIERT als UI; Fremd-API NICHT VERFÜGBAR (Negativbefund) | nicht anbindbar |

## 1. Übersichtsmatrix

Spalten: Immoware24-Entität (Bezeichnung laut Quelle), Hub-Entität (Tabelle aus dem Datenmodell), Zugangsweg, Richtung, Belegstatus, Phase, offene Punkte.

| Nr. | Immoware24-Entität | Hub-Entität | Zugangsweg | Richtung | Status | Phase | Offene Punkte |
|---|---|---|---|---|---|---|---|
| E01 | Objekt (Miet, WEG, SEV) | properties | CSV-Export Auswertungen | lesen | DOKUMENTIERT (Export-Button), Spalten NICHT VERFÜGBAR | 1 | Spaltenformat und Objektnummer als Schlüssel zu verifizieren am eigenen Mandanten |
| E02 | Gebäude | buildings | CSV-Export Auswertungen | lesen | VERMUTET (Gebäude als Ebene im UI belegt, Export nicht) | 1 | Ob Auswertungen die Gebäudeebene enthalten, zu verifizieren am eigenen Mandanten |
| E03 | Verwaltungseinheit (VE) | units | CSV-Export Auswertungen Mieter- und VE-Stammdaten | lesen | DOKUMENTIERT | 1 | VE-Nummer als stabiler Schlüssel VERMUTET, Spalten für Fläche und MEA NICHT VERFÜGBAR |
| E04 | Kontakt im Adressbuch (Person, Firma) | contacts, companies | CardDAV Kontaktfreigabe | lesen | VERIFIZIERT (Existenz), Schreiben unklar | 1 | vCard-Version, UID-Stabilität, Aufteilung nach Kontakt-Typen zu verifizieren am eigenen Mandanten |
| E05 | Kontakt im Adressbuch | contacts, companies | CSV-Export Kontakte (Export-Button) | lesen | DOKUMENTIERT (Snippet: "Die Exportfunktion CSV steht für Kontakte zur Verfügung") | 1 | Feldumfang (Name, Adresse, Telefon, E-Mail) VERMUTET, Spaltenliste NICHT VERFÜGBAR |
| E06 | Belegung, Mieter je VE | contact_roles (role tenant), contracts, contract_parties | CSV-Export Belegungsliste | lesen | DOKUMENTIERT (Belegungsliste mit Mietbeginn, Mietende, Kontaktinformationen laut Snippet) | 1 | Vertragsnummer als Schlüssel VERMUTET, Mietbeträge im Export NICHT VERFÜGBAR |
| E07 | Eigentümer je VE, MEA, Hausgeld | contact_roles (role owner), ownerships | CSV-Export Auswertungen | lesen | VERMUTET | 1 | Ob Eigentümerlisten mit MEA und Hausgeld exportierbar sind, zu verifizieren am eigenen Mandanten |
| E08 | Verwaltungsbeirat | contact_roles (role board_member) | CSV-Export | lesen | VERMUTET | 3 | Kein Export belegt |
| E09 | Dienstleister, Handwerker | contact_roles (role service_provider) | CardDAV, CSV-Export | lesen | VERMUTET (Kontakt-Typen laut Snippet, konkrete Typen NICHT VERFÜGBAR) | 1 | Rollenableitung aus Kontakt-Typ zu verifizieren am eigenen Mandanten |
| E10 | Bankverbindung (Kontakt, Objektkonto) | bank_accounts | CSV-Export, CAMT.053 (Kontobezug) | lesen | VERMUTET | 3 | IBAN in Auswertungen nicht belegt, Datenschutz: nur verschlüsselt speichern |
| E11 | SEPA-Lastschriftmandat | bank_accounts.mandate_reference | UI | keine | DOKUMENTIERT als UI-Funktion (Checkliste Lastschrifteinzug) | nicht geplant | Kein Exportweg belegt |
| E12 | DMS-Dokument | documents | WebDAV Dateifreigabe | lesen | VERIFIZIERT | 1 | Tatsächlicher Ordnerumfang (nur Posteingang und Dokumente oder gesamte Objektstruktur) zu verifizieren am eigenen Mandanten |
| E13 | DMS-Ordner | document_folders | WebDAV PROPFIND | lesen | VERIFIZIERT (Netzlaufwerk, "Ordner aus dem Immoware24-DMS") | 1 | Ordnerhierarchie, automatisch angelegte Abrechnungsordner VERMUTET |
| E14 | DMS-Posteingang (Eingangsdokument) | documents (origin hub_upload), write_operations | WebDAV PUT | schreiben (create-only) | VERIFIZIERT (Upload vom Scanner, Ordner beschreibbar, Änderungen wirken im Livesystem) | 2 | Zulässigkeit automatisierter Uploads durch Serveranwendung: WAITING_FOR_VENDOR_ACCESS. If-None-Match-Verhalten zu verifizieren am eigenen Mandanten |
| E15 | Kalendereintrag, Termin | calendar_events (siehe 03-field-mapping, Tabelle in Phase 3 anzulegen) | CalDAV Kalenderfreigabe | lesen | VERIFIZIERT (Existenz), Schreiben unklar | 3 | Mehrere Kalender je Nutzer, Aufgaben (VTODO) vorhanden oder nicht: zu verifizieren am eigenen Mandanten |
| E16 | Ticket (Ticketsystem) | cases (nur manueller Textverweis immoware_ticket_reference) | keiner | keine | DOKUMENTIERT als UI-Funktion; technischer Zugang NICHT VERFÜGBAR | nicht geplant | Kein Sync. Hub-Vorgänge verweisen nur textuell auf Ticketnummer |
| E17 | Aufgabe im Ticket, Zeiterfassung | keine | keiner | keine | DOKUMENTIERT als UI-Funktion | nicht geplant | |
| E18 | Rechnung (Eingangsrechnung, Rechnungsplan) | invoices | DATEV-CSV (Buchungsebene), Belegdokument per WebDAV | lesen | DOKUMENTIERT (Buchungsexport inkl. Belegdokumente seit Update März 2026, Snippet) | 3 | Format des Belegexports NICHT VERFÜGBAR |
| E19 | Buchung (Sachkonto, Debitor, Kreditor) | transactions (kind ledger) | DATEV-CSV-Buchungsexport | lesen | DOKUMENTIERT (CSV, Kontenmapping, Festschreibung wählbar, nur Miet- und WEG-Verwaltung) | 3 | Genaues DATEV-Format (EXTF-Buchungsstapel oder anderes) zu verifizieren an echter Exportdatei |
| E20 | Bankumsatz | transactions (kind bank) | CAMT.053 v02/v08, MT940 STA (Datei) | lesen (nur Anzeige) | DOKUMENTIERT (Importformate laut Support-Artikel) | 3 | Hub spielt nichts in Immoware24 ein. Dateiquelle ist der Banking-Client oder die Bank, nicht Immoware24 |
| E21 | Offener Posten (Rechnungen, Mietforderungen) | open_items | CSV-Export der OP-Listen | lesen | DOKUMENTIERT ("Alle Auswertungen ... Export-Button", Liste offener Posten als Auswertung belegt) | 3 | Ob OP-Listen unter "Auswertungen" fallen und Spaltenformat: zu verifizieren am eigenen Mandanten |
| E22 | Zahlungsauftrag (pain.001, pain.008) | keine | Datei aus Immoware24 an Bank | keine | DOKUMENTIERT | nicht geplant | Der Hub erzeugt und liest keine Zahlungsaufträge |
| E23 | Heizkosten-Datensätze (B/K, L/M, D, E898) | transactions bzw. eigene Tabelle heating_exchange_files (Phase 3, optional) | HeiWaKo/bved DTA-Datei | lesen | DOKUMENTIERT | 3 optional | Nur lesend, Dateien aus Drop-Ordner |
| E24 | Exposé, Vermarktungsobjekt (OpenImmo) | keine | OpenImmo-XML per FTP an Portal | keine | VERMUTET (Drittquelle wohnglueck.de) | nicht geplant | Nicht Teil des Hubs |
| E25 | Portal24-Nutzer, Schadensmeldung, Freigabe | keine | keiner | keine | DOKUMENTIERT als UI; Fremd-API NICHT VERFÜGBAR | nicht geplant | |
| E26 | E-Post-Brief, Postausgang, Sammler | keine | keiner | keine | DOKUMENTIERT als UI (E-POST Business API nur zwischen Immoware24 und Deutscher Post) | nicht geplant | |
| E27 | Serienbrief, Vorlage | keine | keiner | keine | DOKUMENTIERT als UI | nicht geplant | Vom Hub erzeugte Dokumente gehen über E14 in den Posteingang |
| E28 | craftware24-Auftrag | keine | keiner | keine | DOKUMENTIERT als UI | nicht geplant | |
| E29 | Immoware24-Nutzer, Rolle | users (Hub-eigen, kein Spiegel) | keiner | keine | DOKUMENTIERT (Rollen SYS: Administrator, SYS: Standard, SYS: nur Lesezugriff, SYS: nur Lesezugriff Stammdaten) | 0 | Welche Rolle DAV-Freigaben tragen darf: zu verifizieren am eigenen Mandanten |
| E30 | DAV-Freigabe (Datei, Kalender, Kontakte) | immoware_connections, capabilities | Konfigurationsportal config.dav.immoware24.de (nur UI) | manuell | VERIFIZIERT (Anlage durch admin, nicht editierbar, nur löschen und neu anlegen, eigenes Freigabe-Passwort je Nutzer) | 0 | Buchung des DAV-Moduls: WAITING_FOR_VENDOR_ACCESS. Beschränkbarkeit einer Freigabe auf den Posteingang: VERMUTET |

## 2. Detailblätter je Entitätsgruppe

### 2.1 Stammdaten Objekt, Gebäude, VE (E01 bis E03)

Zugangsweg: ausschließlich manueller CSV-Export der Auswertungen "Mieter- und Verwaltungseinheiten-Stammdaten" (DOKUMENTIERT, Quelle: support.immoware24.de/hc/de/articles/360018128817, Snippet: "Alle aufgeführten Auswertungen lassen sich über den 'Export'-Button in eine CSV-Datei exportieren."). Eingang in den Hub über Drop-Ordner oder Upload in der Hub-UI mit Pflichtmetadaten (Exporttyp, Objekt, Exportdatum, Mitarbeiter, Vollexport oder Teilexport).

Schlüssel: key_schema erst nach Sichtung einer echten Exportdatei festlegen (import_formats Version 1, Status draft). Bis dahin identity_confidence = uncertain. Kandidaten: Objektnummer, VE-Nummer. Beide sind VERMUTET als stabil.

Nicht abgebildet, weil kein Zugangsweg belegt: Umlageschlüssel, Heizflächen, Wirtschaftsjahr, Kontenrahmen je Objekt. Alle NICHT VERFÜGBAR.

### 2.2 Kontakte und Rollen (E04 bis E09)

Zwei Lesequellen, die im Hub zusammengeführt werden:

1. CardDAV-Kontaktfreigabe (VERIFIZIERT Existenz). Liefert vCards je Adressbuch. Laut Snippet der offiziellen Anleitung werden Kontakte "unterteilt nach Kontakt-Typen" repliziert (Status VERMUTET, da Snippet in der Prüfung nicht reproduzierbar war). Ob je Kontakt-Typ ein eigenes Adressbuch existiert, ist zu verifizieren am eigenen Mandanten.
2. CSV-Export Kontakte (DOKUMENTIERT). Liefert die Adressbuchliste ohne belegte Spaltenangabe.

Verknüpfung: Nach dem Bootstrap nur bei exakter Übereinstimmung von E-Mail, Nachname und Vorname mit genau einem Kandidaten (created_by = bootstrap). Danach ausschließlich Vorschläge (conflicts, Typ duplicate_candidate). Kein Auto-Merge, Zusammenführung nur als merged_into_id.

Rollen (tenant, owner, board_member, service_provider): Ableitung aus Belegungsliste (tenant) und Eigentümerlisten (owner) ist geplant, Ableitung aus vCard-Kategorien ist VERMUTET. Dienstleisterrolle aus Kontakt-Typ VERMUTET.

Schreiben: nicht eingeplant. Änderungswünsche laufen über proposed_change und manuelle Umsetzung in Immoware24.

### 2.3 Dokumente (E12 bis E14)

Lesen: WebDAV PROPFIND auf die Dateifreigabe (VERIFIZIERT). Ordner Posteingang und Dokumente sind beschreibbar (VERIFIZIERT, Zitat aus Snippet: "Die Ordner 'Posteingang' und 'Dokumente' können überschrieben werden. Änderungen und Löschvorgänge wirken sich auf das eingebundene System aus."). Ob weitere, nur lesbare Ordner exponiert sind, zu verifizieren am eigenen Mandanten.

Schreiben: ausschließlich neue Datei in den Posteingang (E14), Details in 05-write-capabilities.md. Ob Immoware24 hochgeladene Dateien automatisch Objekten zuordnet: NICHT VERFÜGBAR. Die dokumentierte OCR- und KI-Erkennung im Posteingang (DOKUMENTIERT, Snippet) verarbeitet hochgeladene Dokumente weiter; der Hub liefert nur die Dateinamenskonvention.

DMS-Metadaten (Kategorie, Zuordnung zu Objekt, Einheit, Vorgang): über WebDAV nicht zugänglich, NICHT VERFÜGBAR. Der Hub führt eigene Zuordnungen (documents.property_id, unit_id, contact_id, case_id) als Hub-Wahrheit, nicht als Spiegel.

Papierkorb: Löschung nach 7 Tagen (DOKUMENTIERT, AGB-Snippet). Relevant für Fehluploads.

### 2.4 Termine (E15)

CalDAV-Kalenderfreigabe (VERIFIZIERT Existenz). Für Android empfiehlt Immoware24 laut Snippet "Schreibschutz erzwingen"; die daraus abgeleitete Aussage, dass der Server Schreibversuche technisch annimmt, ist VERMUTET und wird nicht genutzt. Hub liest nur. Niedrige Priorität, Phase 3. Tabelle calendar_events wird in Phase 3 angelegt; Feldmapping steht bereits in 03-field-mapping.md.

### 2.5 Buchhaltung (E18 bis E23)

Alle Wege sind Dateiwege, manuell ausgelöst, nur lesend:

- DATEV-CSV-Buchungsexport: DOKUMENTIERT (Quelle immoware24.de/funktionen/datev, Snippets zu Kontenmapping, Festschreibung, KOST2, Beschränkung auf Miet- und WEG-Verwaltung). Genaues Dateiformat: zu verifizieren an echter Exportdatei.
- Buchungsexport inklusive Belegdokumente (Update März 2026): DOKUMENTIERT (Snippet). Format NICHT VERFÜGBAR.
- CAMT.053 v02/v08, MT940 STA: DOKUMENTIERT als Importformate von Immoware24. Der Hub liest diese Dateien nur zur Anzeige, wenn sie ihm parallel zugeführt werden; er greift nicht auf den Banking-Client zu.
- OP-Listen: DOKUMENTIERT als Auswertung, Export per Export-Button plausibel, zu verifizieren am eigenen Mandanten.
- HeiWaKo/bved: DOKUMENTIERT, optional.

### 2.6 Nicht anbindbare UI-Funktionen (E16, E17, E22, E24 bis E28)

Ticketsystem, Portal24, E-Post, craftware24, KI-Anrufbeantworter, Serienbriefe, Zahlungsverkehr und OpenImmo sind DOKUMENTIERT als Funktionen der Immoware24-UI ohne dokumentierte Fremdschnittstelle. Der Hub bildet sie nicht ab. Einziger Berührungspunkt: Hub-Vorgänge (cases) können eine Ticketnummer als Text tragen; die Zuordnung ist manuell und wird nicht synchronisiert.

## 3. Nicht vorhandene Zugangswege (Negativbefunde)

| Gesuchter Weg | Status | Prüfstand 11.09.2026 |
|---|---|---|
| Öffentliche REST-API, Developer-Portal, API-Key-Verwaltung | NICHT VERFÜGBAR | Keine offizielle Aussage gefunden. Packagist 0 Pakete, npm 0 Pakete, GitHub nur Drittprojekte. Drittquellen (hausverwaltungschecker.de, legacy-use, softwarefinder) widersprechen sich und sind VERMUTET. Schriftliche Anfrage an Support: WAITING_FOR_VENDOR_ACCESS |
| Webhooks aus Immoware24 | NICHT VERFÜGBAR | Keine Fundstelle |
| Zapier, Make, n8n, Power Automate | NICHT VERFÜGBAR (n8n per npm belegt), Zapier und Make VERMUTET nicht vorhanden | Verzeichnisse gesperrt, manuelle Prüfung im Browser offen |
| Strukturierter Stammdatenimport (CSV) in Immoware24 | VERMUTET (Drittprojekt Berlussimo erzeugt "CSV files that can be imported by Immoware24") | Für den Hub nicht relevant, kein Schreibpfad geplant |
| Massenexport oder ZIP-Export des DMS | NICHT VERFÜGBAR | Exportweg bleibt WebDAV |
| Rate Limits, Quotas, SLA, Sitzungs-Timeouts | NICHT VERFÜGBAR | Feste konservative Limits im Hub (2 Requests pro Sekunde, Concurrency 2 lesend, 1 schreibend) |

## 4. Offene Punkte (konsolidiert)

| Nr. | Punkt | Kennzeichnung | Phase |
|---|---|---|---|
| O1 | Buchung und Kosten des DAV-Moduls | WAITING_FOR_VENDOR_ACCESS | 0 |
| O2 | Schriftliche Bestätigung, dass automatisierter WebDAV-Upload durch eine Serveranwendung zulässig ist | WAITING_FOR_VENDOR_ACCESS | 0, Blocker für Phase 2 |
| O3 | Existenz einer nicht öffentlichen Partner-API oder eines Exportmechanismus | WAITING_FOR_VENDOR_ACCESS | 0 |
| O4 | Welche Nutzerrolle DAV-Freigaben tragen darf | zu verifizieren am eigenen Mandanten | 0 |
| O5 | Ordnerumfang der WebDAV-Freigabe, Beschränkbarkeit auf Posteingang | zu verifizieren am eigenen Mandanten | 0 |
| O6 | Auth-Schema (Basic oder Digest), ETag-Stabilität, sync-token, CTag, If-None-Match | zu verifizieren am eigenen Mandanten (Probe) | 0 |
| O7 | vCard-Version, UID-Stabilität, Anzahl Adressbücher je Nutzer, Kontakt-Typen | zu verifizieren am eigenen Mandanten | 0 |
| O8 | Spaltenformat aller CSV-Auswertungen (Objekte, VE, Kontakte, Belegung, OP) | zu verifizieren am eigenen Mandanten | 0 |
| O9 | Dateiformat des DATEV-Buchungsexports und des Belegexports | zu verifizieren an echter Datei | 3 |
| O10 | Automatische Objektzuordnung hochgeladener Dateien im DMS | NICHT VERFÜGBAR, Test im Mandanten | 2 |
| O11 | AGB-Wortlaut zu automatisiertem Zugriff und zur Datenherausgabe bei Vertragsende | Original sichern, Prüfung durch Rechtsanwalt | 0 |
| O12 | Zitate, die nur als Snippet-Paraphrase vorliegen, gegen Originaltexte abgleichen und im Repository ablegen | zu verifizieren | 0 |
