# Data Ownership Immoware Hub

Stand: 11.09.2026
Gesellschaft: Hausverwaltung Müller GmbH
Dokumentstatus: Entwurf, gilt zusammen mit docs/architecture/01-architecture-decision.md und docs/architecture/02-data-model.md

## Zweck und Grundregel

Dieses Dokument legt je Datenobjekt fest, wer die fachliche Wahrheit hält (Source of Truth), in welche Richtung gelesen und geschrieben wird, wie Konflikte entschieden werden, wann ein Datensatz im Hub als gelöscht gilt und wie häufig synchronisiert wird.

Grundregel: Immoware24 ist für alle fachlichen Objekte das führende System. Der Hub ist ein versionierter Spiegel. Der Hub hält die Wahrheit ausschließlich für seine eigenen Steuer- und Entscheidungsdaten (Konflikte, Merges, Zuordnungen, Freigaben, Audit). Es gibt genau einen Schreibpfad nach Immoware24: das Anlegen einer neuen Datei im DMS-Ordner Posteingang per WebDAV. Alle anderen Änderungswünsche laufen als proposed_change über einen Mitarbeiter, der sie manuell in Immoware24 umsetzt.

Belegstatus der Zugangswege (Kurzfassung, Details in 01-architecture-decision.md, Abschnitt 0):

| Zugangsweg | Status | Hinweis |
|---|---|---|
| WebDAV auf DMS (Posteingang, Dokumente) | VERIFIZIERT | Lesen und Upload belegt, Overwrite und Delete wirken sofort im Livesystem |
| CardDAV (Kontaktfreigabe) | VERIFIZIERT für Existenz | Schreibrichtung unklar, kein Schreibpfad eingeplant |
| CalDAV (Kalenderfreigabe) | VERIFIZIERT für Existenz | Schreibrichtung unklar, kein Schreibpfad eingeplant |
| CSV-Export der Auswertungen | DOKUMENTIERT | manuell über Export-Button, Spaltenformat NICHT VERFÜGBAR |
| DATEV-CSV-Buchungsexport | DOKUMENTIERT | manuell, nur Miet- und WEG-Verwaltung |
| CAMT.053 / MT940 | DOKUMENTIERT | Dateiformate, Import und Export über Banking-Client und UI |
| REST-API, Webhooks | NICHT VERFÜGBAR | kein Baustein darf darauf bauen |

Begriffe:
- Read Direction: Richtung, in der der Hub Daten übernimmt.
- Write Direction: Richtung, in der Änderungen zurückfließen. "keine" bedeutet, dass der Hub nie schreibt; "proposed_change" bedeutet manueller Rückweg über einen Mitarbeiter.
- Conflict Rule: Regel, wenn Hub-Zustand und Immoware24-Zustand voneinander abweichen.
- Delete Rule: Regel, wann ein Datensatz im Hub als gelöscht gilt (immer Soft Delete, nie Hard Delete des Datensatzes; Hard Delete betrifft nur personenbezogene Felder nach DSGVO-Frist).
- Sync Frequency: Startwerte, werden erst nach Sichtung des Lastprofils angepasst. Verbindliche Intervalltabelle in 07-sync-strategy.md Abschnitt 1.5; Angaben hier sind Kurzfassungen. Rate Limits seitens Immoware24 sind NICHT VERFÜGBAR.

## A. Stammdaten

### properties (Objekte)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (Objektdaten) |
| Read Direction | Immoware24 -> Hub über CSV-Auswertung (DOKUMENTIERT, manueller Export), Schlüssel laut import_formats.key_schema (zu verifizieren am eigenen Mandanten) |
| Write Direction | keine; Änderungswünsche als proposed_change |
| Conflict Rule | Remote gewinnt bei allen aus Immoware24 stammenden Feldern. Hub-eigene Annotationen (management_type bei UNKNOWN, Zuordnungen) bleiben erhalten und werden nicht überschrieben. Unsichere Identität (identity_confidence uncertain) landet als uncertain_identity in der Konfliktqueue, kein automatisches Anlegen |
| Delete Rule | missing_since beim ersten Fehlen in einem als Vollexport gekennzeichneten Import; Soft Delete erst nach dem zweiten Vollexport ohne Treffer. Teilexporte löschen nie |
| Sync Frequency | wöchentlicher Export durch verantwortlichen Mitarbeiter (export_schedules), Erinnerung bei Überfälligkeit, stale_since bei Überschreitung |

### buildings (Gebäude)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 |
| Read Direction | Immoware24 -> Hub über CSV-Auswertung, nur soweit der Export Gebäude ausweist (zu verifizieren am eigenen Mandanten) |
| Write Direction | keine; proposed_change |
| Conflict Rule | Remote gewinnt; fehlt eine Gebäudeebene im Export, werden Einheiten direkt am Objekt geführt (building_id NULL), kein Konflikt |
| Delete Rule | wie properties (Snapshot-Semantik, zwei Vollexporte) |
| Sync Frequency | wie properties |

### units (Verwaltungseinheiten)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 |
| Read Direction | Immoware24 -> Hub über CSV-Auswertung Mieter- und VE-Stammdaten (DOKUMENTIERT, Artikel 360018128817) |
| Write Direction | keine; proposed_change |
| Conflict Rule | Remote gewinnt. Doppelter externer Schlüssel (duplicate_external) blockiert nur die betroffene Zeile. Änderung von Fläche oder MEA erzeugt neue Version, kein Konflikt |
| Delete Rule | Snapshot-Semantik: missing_since nach erstem Vollexport ohne Treffer für dieses Objekt, Soft Delete nach dem zweiten |
| Sync Frequency | wöchentlich (Export), Bootstrap einmalig mit dry_run und accept_all_exact |

### contacts (Kontakte, Personen)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (Adressbuch) |
| Read Direction | Immoware24 -> Hub über CardDAV (VERIFIZIERT für Existenz, hash-basiert) und ergänzend über CSV-Export Kontakte (DOKUMENTIERT). Jede contacts-Zeile hat genau eine Quelle (connection_id, checksum, sync_version). CardDAV ist die führende Quelle für Kontaktfelder. Der CSV-Import schreibt keine Felder in CardDAV-Kontakte; er legt ein external_mapping (entity_type contact, CSV-Connection) auf den CardDAV-Kontakt an, wenn die Verknüpfung exakt ist (Bootstrap-Regel), und verwendet diesen Kontakt für contact_roles, contracts und ownerships. Ohne exakte Verknüpfung entsteht eine eigene contacts-Zeile auf der CSV-Connection mit Duplikatsvorschlag (duplicate_candidate) |
| Write Direction | keine über CardDAV (Schreibrichtung unklar, carddav.write hard_locked); Änderungen als proposed_change, manuelle Umsetzung in Immoware24, Bestätigung über Hash beim nächsten Sync |
| Conflict Rule | Remote gewinnt für alle Felder aus Immoware24. Hub-eigene Felder (merged_into_id, similarity_hash, Zuordnungen) bleiben. Widersprechen CardDAV und CSV in einem Feld eines verknüpften Kontakts, bleibt die CardDAV-Zeile unverändert (führende Quelle), die Abweichung wird als Konflikt vom Typ source_mismatch protokolliert (Quelle gegen Quelle, nicht local_change_vs_remote, das ausschließlich Hub-Annotation gegen Remote bezeichnet). Der Operator prüft in Immoware24, welcher Wert gilt; eine Korrektur läuft über proposed_change. Duplikatskandidaten (duplicate_candidate) sind nur Vorschläge, Zusammenführung ausschließlich manuell als merged_into_id mit Rückgängig |
| Delete Rule | CardDAV: missing_since beim ersten Fehlen, Soft Delete nach zwei aufeinanderfolgenden Läufen mit erfolgreichem Health-Check. CSV: Snapshot-Semantik. Personenbezogene Felder werden nach DSGVO-Frist (Default 12 Monate nach Ende der letzten Rolle, mit Rechtsanwalt abstimmen) mit NULL überschrieben, personal_data_erased_at gesetzt, Audit-Vermerk |
| Sync Frequency | CardDAV alle 60 Minuten inkrementell (sync-token oder CTag, sofern Probe dies feststellt), Full Reconcile wöchentlich im Nachtfenster (07-sync-strategy.md Abschnitt 1.5); CSV wöchentlich |

### companies (Firmen)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (Adressbuch, Kontakttyp Firma) |
| Read Direction | wie contacts |
| Write Direction | keine; proposed_change |
| Conflict Rule | wie contacts |
| Delete Rule | wie contacts, ohne DSGVO-Feldlöschung, sofern keine personenbezogenen Daten enthalten sind |
| Sync Frequency | wie contacts |

### contact_roles (Rollen: Mieter, Eigentümer, Beirat, Dienstleister)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (Belegungen, Eigentümerzuordnung) |
| Read Direction | Immoware24 -> Hub über CSV-Auswertung (Belegungsliste, Eigentümerliste), abgeleitet vom key_schema (zu verifizieren am eigenen Mandanten); die CardDAV-Aufteilung nach Kontakttypen ist VERMUTET und wird nur als Hinweis genutzt |
| Write Direction | keine; proposed_change |
| Conflict Rule | Remote gewinnt. Eine Rolle mit valid_to in der Vergangenheit wird nicht gelöscht, sondern als historisch geführt |
| Delete Rule | Snapshot-Semantik über zwei Vollexporte; endende Rollen erhalten valid_to statt Soft Delete |
| Sync Frequency | wöchentlich (Export) |

### contracts und contract_parties (Mietverträge)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 |
| Read Direction | Immoware24 -> Hub über CSV-Auswertung (Belegungsliste mit Mietbeginn, Mietende, Beträgen; DOKUMENTIERT als Auswertung, Feldumfang zu verifizieren) |
| Write Direction | keine; proposed_change |
| Conflict Rule | Remote gewinnt. Betragsänderungen erzeugen neue Version. Fehlende Vertragsnummer führt zu identity_confidence derived und Bericht, nicht zu Konflikt, sofern Schlüssel aus Einheit plus Mietbeginn eindeutig ist |
| Delete Rule | Snapshot-Semantik; beendete Verträge bleiben mit end_date erhalten |
| Sync Frequency | wöchentlich (Export) |

### ownerships (Eigentumsverhältnisse WEG)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 |
| Read Direction | Immoware24 -> Hub über CSV-Auswertung |
| Write Direction | keine; proposed_change |
| Conflict Rule | Remote gewinnt; Eigentümerwechsel erzeugt neue ownership mit valid_from, alte erhält valid_to |
| Delete Rule | Snapshot-Semantik; historische Eigentümer bleiben |
| Sync Frequency | wöchentlich (Export) |

### bank_accounts (Bankverbindungen)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (SEPA-Mandate, Kontodaten) |
| Read Direction | Immoware24 -> Hub nur, soweit eine Auswertung IBAN ausweist (zu verifizieren am eigenen Mandanten); sonst leer. IBAN verschlüsselt, Anzeige nur iban_last4; vollständige IBAN nur mit Scope finance:read_iban (Rolle operator mit Zweckangabe im Header X-Purpose, auditiert, nie API Client), siehe 08-security.md Abschnitt 5 und 09-api-documentation.md Abschnitt 3.3 |
| Write Direction | keine; proposed_change. Keine Lastschriftmandate im Hub |
| Conflict Rule | Remote gewinnt; abweichende IBAN bei identischem Mandat erzeugt local_change_vs_remote zur manuellen Prüfung |
| Delete Rule | Snapshot-Semantik; nach DSGVO-Frist Löschung von iban_encrypted und account_holder, iban_hash bleibt |
| Sync Frequency | wie Quelle des Exports, Minimum monatlich |

## B. Dokumente

### document_folders (DMS-Ordnerstruktur)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 DMS |
| Read Direction | Immoware24 -> Hub über WebDAV PROPFIND (VERIFIZIERT). Ordnerumfang wird per Probe festgestellt, nicht angenommen |
| Write Direction | keine (MKCOL ist nicht vorgesehen, Guard blockiert) |
| Conflict Rule | Remote gewinnt. Hub-eigene Steuerfelder (scan_priority, scan_interval_seconds, content_policy, writable_by_hub) sind Hub-Wahrheit und werden nie aus Immoware24 überschrieben. writable_by_hub darf nur für den Posteingang 1 sein |
| Delete Rule | missing_since beim ersten Fehlen, Soft Delete nach zwei aufeinanderfolgenden Läufen mit Health-Check; verschwindet der Posteingang oder wechselt der Server-Fingerprint, geht die Connection auf degraded |
| Sync Frequency | Posteingang alle 30 Minuten, übrige Ordner täglich, Full Reconcile wöchentlich nachts ohne Fingerprint-Abkürzung |

### documents (Dateien im DMS, Metadaten)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 DMS |
| Read Direction | Immoware24 -> Hub über WebDAV PROPFIND (Metadaten), Inhalt nur bei content_policy hash_on_change oder store per GET |
| Write Direction | Hub -> Immoware24 ausschließlich als webdav_create in den Posteingang (create-only, If-None-Match: *, Precheck, Verifikation). Kein Overwrite, kein Delete, kein Move, kein Schreiben in Dokumente. Hub-Zuordnungen (property_id, unit_id, contact_id, case_id) sind Hub-Wahrheit und fließen nie nach Immoware24 |
| Conflict Rule | Remote gewinnt für Metadaten. ETag-Wechsel ohne Änderung von Größe und lastmodified ist unchanged_meta_noise. Move nur bei Hash-Gleichheit als moved, sonst moved_probable in Konfliktqueue. Existiert ein Schreibziel bereits: skipped_exists plus write_target_exists, nie Überschreiben. Verifikation fehlgeschlagen: failed_verify, kein Retry |
| Delete Rule | missing_since beim ersten Fehlen, Soft Delete nach zwei aufeinanderfolgenden Läufen mit erfolgreichem Health-Check und erreichbarem Ordner. Löschung in Immoware24 wird nie durch den Hub ausgelöst. Fehluploads werden manuell im DMS bereinigt, innerhalb von 7 Tagen wegen Papierkorbleerung (DOKUMENTIERT, AGB-Snippet) |
| Sync Frequency | Posteingang 30 Minuten, Dokumente täglich, Full Reconcile wöchentlich |

### Dokumentinhalte (Blob-Speicher, storage_key)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 DMS; Hub hält nur Referenz oder Kopie laut content_policy |
| Read Direction | Immoware24 -> Hub per GET, nur bei content_policy store oder zur Hash-Bildung |
| Write Direction | Hub-erzeugte Dateien werden bis status succeeded im Blob-Speicher gehalten, danach ist die Immoware24-Kopie die Wahrheit |
| Conflict Rule | Hash-Abweichung zwischen Blob und Remote nach Upload ist failed_verify, manuelle Entscheidung |
| Delete Rule | Kopien folgen dem Soft Delete des Dokuments; Blobs mit personenbezogenem Inhalt werden nach DSGVO-Frist gelöscht, Hash bleibt |
| Sync Frequency | ereignisgesteuert (bei erkannter Änderung), kein eigener Lauf |

## C. Vorgänge

### cases (Vorgänge im Hub)
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub. Das Immoware24-Ticketsystem ist als UI-Funktion belegt (DOKUMENTIERT), eine Fremd-API dazu ist NICHT VERFÜGBAR (Negativbefund); es findet kein Sync statt |
| Read Direction | keine aus Immoware24. immoware_ticket_reference wird manuell durch den Mitarbeiter eingetragen |
| Write Direction | keine nach Immoware24. Dokumente eines Vorgangs können über den Schreibpfad in den Posteingang gelegt werden; die Zuordnung zum Ticket erfolgt manuell in Immoware24 |
| Conflict Rule | entfällt, nur Hub-Daten |
| Delete Rule | Soft Delete durch Operator, Audit-Eintrag |
| Sync Frequency | entfällt |

### proposed_change (Änderungsvorschläge an Immoware24)
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub (Vorschlag) bis zur manuellen Umsetzung; danach Immoware24 |
| Read Direction | Bestätigung Immoware24 -> Hub über den nächsten Sync (Hash-Vergleich, confirmed_by_sync_run_id) |
| Write Direction | manuell durch Mitarbeiter in der Immoware24-UI, nie automatisiert |
| Conflict Rule | Wird der vorgeschlagene Wert beim nächsten Sync nicht bestätigt, bleibt der Eintrag offen; nach 30 Tagen ohne Bestätigung Erinnerung an assigned_to |
| Delete Rule | Einträge werden nie gelöscht, nur auf resolved_applied_manually oder dismissed gesetzt |
| Sync Frequency | folgt dem Sync des betroffenen Objekts |

## D. Buchhaltung (nur lesend, Phase 3)

### invoices (Eingangsrechnungen)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (Rechnungsbuchung, Posteingang) |
| Read Direction | Immoware24 -> Hub nur über DATEV-Export oder OP-Liste, soweit Rechnungsmerkmale enthalten sind (zu verifizieren am eigenen Mandanten). Kein direkter Zugriff auf die Rechnungsbuchung |
| Write Direction | keine Buchung. Belegdateien können über den Schreibpfad in den Posteingang gelegt werden; Erkennung und Buchung erfolgen in Immoware24 (DOKUMENTIERT, KI-Belegerkennung) |
| Conflict Rule | Remote gewinnt; Betragsabweichung zwischen Exporten erzeugt neue Version |
| Delete Rule | Snapshot-Semantik über zwei Vollexporte; buchhaltungsrelevante Payloads 10 Jahre aufbewahren (mit Steuerberater abstimmen) |
| Sync Frequency | monatlich mit DATEV-Export |

### transactions, kind = ledger (Buchungen aus DATEV-Export)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 Buchhaltung |
| Read Direction | Immoware24 -> Hub über DATEV-CSV-Buchungsexport (DOKUMENTIERT, manuell, Kontenmapping, Festschreibung wählbar; Dateiformat zu verifizieren) |
| Write Direction | keine. Der Hub erzeugt keine Buchungen und keine DATEV-Dateien |
| Conflict Rule | Zeilen sind über row_hash plus occurrence_no (Vorkommenszähler innerhalb der Datei) identifiziert. Nicht eindeutige Schlüssel (Belegfeld, Datum, Konto, Gegenkonto, Betrag) werden bewusst als Duplikat gespeichert, nicht verschmolzen; Summen je Objekt müssen mit Immoware24 übereinstimmen. Korrekturbuchungen sind neue Zeilen |
| Delete Rule | keine Soft Delete-Logik über Snapshots; Buchungen werden nie als gelöscht markiert, ein festgeschriebener Stapel gilt als unveränderlich. Fehlt eine Zeile in einem späteren Export desselben Zeitraums, entsteht ein Konflikteintrag zur manuellen Prüfung |
| Sync Frequency | monatlich, gekoppelt an den Export für den Steuerberater |

### transactions, kind = bank (Bankumsätze aus CAMT.053)
| Aspekt | Regel |
|---|---|
| Source of Truth | Bank bzw. Immoware24 Banking-Client; Hub liest nur zur Anzeige |
| Read Direction | Datei -> Hub (CAMT.053 v02/v08 oder MT940 STA, DOKUMENTIERT als Formate des Banking-Clients). Kein Abruf bei der Bank durch den Hub |
| Write Direction | keine. Der Hub spielt keine Umsätze in Immoware24 ein und erzeugt keine SEPA-Dateien |
| Conflict Rule | Identität über AcctSvcrRef bzw. EndToEndId plus Betrag und Buchungsdatum; Kollision wird als Duplikat gespeichert |
| Delete Rule | nie; Bankumsätze sind unveränderlich, Stornos sind neue Umsätze |
| Sync Frequency | bei Bereitstellung der Datei, geplant wöchentlich |

### open_items (Offene Posten)
| Aspekt | Regel |
|---|---|
| Source of Truth | Immoware24 (Liste offener Posten) |
| Read Direction | Immoware24 -> Hub über CSV-Export der OP-Liste (DOKUMENTIERT als Auswertung, Spalten zu verifizieren) |
| Write Direction | keine; Mahnungen und Sollstellungen bleiben in Immoware24 |
| Conflict Rule | Jeder Export ist ein Stichtagsbild (as_of_date). Kein Konflikt zwischen Stichtagen; Vergleich erfolgt nur innerhalb desselben Stichtags |
| Delete Rule | keine Soft Delete-Logik; ältere Stichtage bleiben als Historie im Spiegel (kein Hard Delete auf Spiegel-Tabellen, 02-data-model.md Konventionen); personenbezogene Felder folgen dem Löschkonzept in 08-security.md Abschnitt 7.3; Payload gemäß Buchhaltungsfrist |
| Sync Frequency | wöchentlich (Export), stale_since bei Überschreitung |

## E. Hub-eigene Daten (Source of Truth: Hub)

### external_mappings
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub |
| Read Direction | entfällt |
| Write Direction | entfällt |
| Conflict Rule | Ein externer Schlüssel zeigt auf genau eine Hub-Entität. Manuelle und Bootstrap-Zuordnungen (created_by manual, bootstrap) werden durch den Sync nie geändert, nur durch Operator |
| Delete Rule | nie physisch; Fehlzuordnung wird durch neue Zuordnung ersetzt, alte bleibt im Audit |
| Sync Frequency | entfällt; tägliches Backup in hub_decision_backups |

### conflicts und contact_merges
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub |
| Read Direction | entfällt |
| Write Direction | entfällt |
| Conflict Rule | Entscheidungen sind endgültig bis zum ausdrücklichen Rückgängig (contact_merges.undone_at) |
| Delete Rule | nie |
| Sync Frequency | entfällt; tägliches Backup |

### capabilities, import_formats, immoware_connections
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub (Konfiguration), gestützt auf Probe-Ergebnisse |
| Read Direction | Probe misst Serverfähigkeiten; Ergebnisse werden persistiert, nie angenommen |
| Write Direction | entfällt |
| Conflict Rule | Abweichender Server-Fingerprint oder Header-Fingerprint setzt die Connection auf degraded; Rückschaltung nur durch Rolle release. enabled für Capabilities nur bei evidence_status VERIFIZIERT oder DOKUMENTIERT, tested_at gesetzt und hard_locked = 0 |
| Delete Rule | import_formats werden nie gelöscht, nur retired; Connections werden auf paused gesetzt |
| Sync Frequency | Probe wöchentlich und vor jeder Aktivierung von write_enabled |

### write_operations
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub für den Zustand der Operation; Immoware24 für die resultierende Datei |
| Read Direction | Verifikation Immoware24 -> Hub über PROPFIND und GET |
| Write Direction | Hub -> Immoware24, einmaliges PUT mit If-None-Match: * (put_attempts maximal 1) |
| Conflict Rule | unknown wird nur lesend aufgelöst, nie durch zweites PUT; skipped_exists und failed_verify sind Endzustände mit manueller Entscheidung |
| Delete Rule | nie; Endzustände bleiben als Nachweis |
| Sync Frequency | ereignisgesteuert; Nachprüfung von unknown dreimal im Abstand von 5 Minuten |

### audit_logs, sync_runs, sync_events, external_payloads
| Aspekt | Regel |
|---|---|
| Source of Truth | Hub |
| Read Direction | entfällt |
| Write Direction | entfällt |
| Conflict Rule | audit_logs append-only mit Hash-Kette; kein UPDATE, kein DELETE |
| Delete Rule | audit_logs unbefristet; sync_runs und sync_events 24 Monate; external_payloads bis Pseudonymisierung (personenbezogen) bzw. 10 Jahre (buchhaltungsrelevant, mit Steuerberater abstimmen) |
| Sync Frequency | entfällt; Kettenwurzel wöchentlich an Object-Lock-Speicher |

## F. Nicht angebundene Immoware24-Bereiche

Für folgende Bereiche gibt es keinen belegten technischen Zugang. Sie bleiben vollständig in Immoware24; der Hub führt keine Spiegeldaten und verweist höchstens textuell (Ticketnummer, Aktenzeichen).

| Bereich | Status | Konsequenz |
|---|---|---|
| Ticketsystem, Aufgaben, Zeiterfassung | DOKUMENTIERT als UI; Fremd-API NICHT VERFÜGBAR | nur immoware_ticket_reference im Hub |
| Portal24 (Mieter- und Eigentümerportal) | DOKUMENTIERT als UI; Fremd-API NICHT VERFÜGBAR | kein Sync |
| E-Post-Versand, Postausgang, Serienbriefe | DOKUMENTIERT als UI (E-POST API ist die der Deutschen Post, nicht von Immoware24) | kein Sync |
| craftware24, KI-Anrufbeantworter | DOKUMENTIERT als UI | kein Sync |
| SEPA-Zahlungsverkehr, Lastschriftmandate | DOKUMENTIERT (Erzeugung in Immoware24 und Banking-Client) | Hub erzeugt und importiert keine Zahlungsdateien |
| OpenImmo-Export an Portale | VERMUTET (Drittquelle) | nicht Teil des Hubs |
| HeiWaKo/bved-Datenaustausch | DOKUMENTIERT | optional Phase 3, nur lesend, keine Ownership-Regel bis zur Entscheidung |
| Kalender (CalDAV) | VERIFIZIERT für Existenz, Schreibrichtung unklar | optional Phase 3, lesend; Regeln analog contacts (Identität iCalendar UID plus RECURRENCE-ID, Soft Delete nach zwei Läufen, Sync 60 Minuten) |

## G. Offene Punkte

| Punkt | Kennzeichnung |
|---|---|
| Spaltenumfang und Schlüssel aller CSV-Auswertungen (Objekte, Einheiten, Belegungen, Eigentümer, Kontakte, OP-Liste) | zu verifizieren am eigenen Mandanten, Phase 0 |
| Ob Bankverbindungen in einer Auswertung exportierbar sind | zu verifizieren am eigenen Mandanten |
| Schreibrichtung CardDAV und CalDAV (bleibt ohne Bedeutung für den Hub, da kein Schreibpfad geplant) | zu verifizieren am eigenen Mandanten, nur zur Dokumentation |
| Aufteilung der Kontaktfreigabe nach Kontakttypen | VERMUTET, zu verifizieren am eigenen Mandanten |
| Zulässigkeit automatisierter WebDAV-Nutzung | WAITING_FOR_VENDOR_ACCESS |
| Ordnerumfang per WebDAV und Beschränkbarkeit der Schreibfreigabe auf den Posteingang | zu verifizieren per Probe |
| DSGVO-Löschfristen je Rolle (Mieter, Eigentümer, Dienstleister) | mit Rechtsanwalt und Steuerberater abstimmen |
| Aufbewahrungsfrist buchhaltungsrelevanter Payloads | mit Steuerberater abstimmen |
