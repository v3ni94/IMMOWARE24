# Immoware24 Schnittstellenrecherche (Interface Discovery)

Projekt: Immoware Hub (Laravel 12, PHP 8.4), Integrationsschicht um den Immoware24-Mandanten der Hausverwaltung Müller GmbH.
Stand: 11.09.2026. Immoware24 bleibt führendes System.

## 0. Leseanleitung und Statusbegriffe

Jede Aussage über Immoware24 in diesem Dokument trägt einen der folgenden Status:

| Status | Bedeutung |
|---|---|
| VERIFIZIERT | Offizielle Immoware24-Quelle (immoware24.de, support.immoware24.de, content.immoware24.de, config.dav.immoware24.de), Wortlaut in einem WebSearch-Snippet oder per Fetch tatsächlich gesehen. Ein nahezu wörtlich gesehener Kernsatz zählt, eine sinngemäße Zusammenfassung nicht. |
| DOKUMENTIERT | Quelle mit URL, die die Aussage trägt: offizielle Quelle, deren Wortlaut nur einmal gesehen, in der Gegenprüfung nicht reproduziert oder nur als Snippet-Paraphrase vorliegt, oder glaubwürdige Drittquelle. |
| VERMUTET | Plausibel, aber nicht durch gesichtete Quelle gedeckt, oder Interpretation über den Quellentext hinaus. Vor Nutzung am eigenen Mandanten zu verifizieren. |
| NICHT VERFÜGBAR | Negativbefund: kein Beleg gefunden. Keine offizielle Aussage, dass die Funktion fehlt. Gilt auch für alle Aussagen der Form "ohne API", "nicht dokumentiert", "keine Angabe". |

Diese Definition ist für alle Dokumente des Repositories verbindlich (Leitdefinition in README.md). Abweichende Kurzfassungen in Einzeldokumenten sind durch diese Tabelle ersetzt.

Wichtige Einschränkung der Recherche: Aus der Rechercheumgebung waren www.immoware24.de, support.immoware24.de, content.immoware24.de, config.dav.immoware24.de und praktisch alle Mirror- und Drittseiten per WebFetch gesperrt (EGRESS_BLOCKED), docplayer.org war nicht auflösbar (DNS ENOTFOUND). Alle Belege stammen aus WebSearch-Snippets. Snippet-Zitate sind teilweise leicht paraphrasiert. Die vollständige Liste blockierter URLs steht in Abschnitt 9. Jeder Punkt mit Status VERMUTET oder mit dem Vermerk "zu verifizieren am eigenen Mandanten" ist vor der jeweiligen Bauphase im eigenen, autorisierten Mandanten zu prüfen und als Testprotokoll im Repository abzulegen.

Grundregeln des Projekts: Nur eigener autorisierter Zugang, keine Umgehung von Authentifizierung oder 2FA, kein Scraping der Web-Oberfläche als Datenbankersatz, nichts erfinden.

## 1. Ergebnis in Kürze

1. Eine öffentliche, dokumentierte REST-/HTTP-API, Webhooks, API-Key-Verwaltung oder ein Entwicklerportal existieren nach Stand der Recherche nicht. Status NICHT VERFÜGBAR (Negativbefund, keine offizielle Aussage). Weder Packagist noch npm noch GitHub enthalten ein SDK oder eine Client-Bibliothek; die einzigen GitHub-Treffer sind Drittprojekte (Browser-Extension, Scraper-Skizze, Platzhalter) und das eigene Hub-Repository.
2. Der einzige belegte, programmatisch nutzbare Standardzugang ist der DAV-Adapter: WebDAV auf das DMS, CardDAV für Kontakte, CalDAV für Kalender. Status VERIFIZIERT für Existenz und Grundfunktion. Er ist ein buchbares Zusatzmodul, das über Support oder Vertrieb aktiviert wird (VERIFIZIERT, Wortlaut "buchen" im Snippet des Artikels 360010876038 gesehen; ob und in welcher Höhe Kosten anfallen, ist Ableitung, VERMUTET, WAITING_FOR_VENDOR_ACCESS). Schreibrichtung ist nur für WebDAV belegt (Upload, Überschreiben, Löschen wirken direkt im Livesystem).
3. Alle übrigen Datenwege sind Dateiexporte oder Dateiimporte über die UI: CSV-Export der Auswertungen, DATEV-CSV-Buchungsexport, SEPA pain.001/pain.008, CAMT.053/MT940-Import, HeiWaKo/bved-Datenaustausch, OpenImmo-Export per FTP. Spaltenformate sind öffentlich nicht dokumentiert (NICHT VERFÜGBAR).
4. Konsequenz für den Hub: Lesen über WebDAV, CardDAV, CalDAV und manuell ausgelöste Exporte. Genau ein Schreibpfad, nämlich create-only Upload in den WebDAV-Ordner Posteingang. Kein Schreiben über CardDAV oder CalDAV, kein Overwrite, kein Delete.

## 2. Capability-Matrix

Spalte "Verifiziert" nennt den höchsten Status, der für den jeweiligen Zugangsweg vorliegt. Spalte "Risiko" bewertet das Integrationsrisiko für den Hub (Datenverlust, Duplikate, Abhängigkeit von manuellen Schritten, fehlende Belege).

| Bereich | Zugangsweg | Lesen | Schreiben | Verifiziert | Risiko |
|---|---|---|---|---|---|
| Kontakte | CardDAV über DAV-Adapter (Kontaktfreigabe) | ja (Replikation auf Geräte belegt) | unklar, nicht eingeplant; Aussage "nur lesend" ist VERMUTET | VERIFIZIERT (Existenz, Freigabetyp Kontakte), Schreibrichtung VERMUTET | mittel: vCard-UID-Stabilität unbelegt, Aufteilung nach Kontakttypen nur Snippet, Duplikate bei Mehrfachanlage einer Person |
| Kontakte | CSV-Export Adressbuch bzw. Auswertungen | ja, manuell über Export-Button | nein | DOKUMENTIERT (Export-Button), Feldumfang NICHT VERFÜGBAR | mittel: manueller Rhythmus, Spaltenformat unbekannt bis Sichtung echter Datei |
| Kontakte | Drittanbieter (sync.blue, XPhone Connect, casavi) | Anbieterangabe | Anbieterangabe (2-Wege laut sync.blue) | VERMUTET (Marketingseiten, nicht gesichtet) | hoch: Zugangsweg unbekannt, keine Immoware24-Bestätigung |
| Kalender | CalDAV über DAV-Adapter (Kalenderfreigabe) | ja (Replikation belegt) | unklar, nicht eingeplant; Android-Hinweis "Schreibschutz erzwingen" | VERIFIZIERT (Existenz), Schreibrichtung VERMUTET | niedrig bis mittel: nur lesend geplant, niedrige Priorität |
| Dokumente | WebDAV über DAV-Adapter (Dateifreigabe), mindestens Ordner Posteingang und Dokumente | ja (Netzlaufwerk, "Lesezugriff auf alle Dokumente" laut Snippet) | ja, Upload, Überschreiben und Löschen wirken direkt im Livesystem | VERIFIZIERT | hoch für Schreiben: Löschen und Überschreiben sofort wirksam, Papierkorb nach 7 Tagen geleert (DOKUMENTIERT, AGB); Ordnerumfang, ETag-Verhalten, Auth-Schema NICHT belegt |
| Dokumente | Scanner-Upload per WebDAV in den Posteingang | nein | ja (Upload) | VERIFIZIERT (Anleitung DAV-Adapter: "Dokumente direkt vom Scanner in das Immoware24 System hochladen"); Toshiba-Seite DOKUMENTIERT | mittel: Einrichtungssupport durch Immoware24 ausgeschlossen, automatische Objektzuordnung hochgeladener Dateien NICHT belegt |
| Dokumente | OCR/KI-Erkennung im DMS-Posteingang | nein | (Verarbeitung in Immoware24) | DOKUMENTIERT (Support-Artikel 5556851032733, Snippet; Toshiba-Seite nennt nur "Weiterverarbeitung") | mittel: Verhalten gegenüber Hub-Uploads nicht belegt, Test in Phase 2 |
| Dokumente | Massenexport/ZIP | nicht auffindbar | nein | NICHT VERFÜGBAR | mittel: Exit-Strategie nur über WebDAV |
| Objekte | CSV-Export Auswertungen (Objekt-, VE-Stammdaten) | ja, manuell | nein | DOKUMENTIERT | mittel: kein stabiler Schlüssel belegt, manueller Rhythmus |
| Objekte | Stammdatenimport (Datenübernahme per CSV) | nein | ja, nur Erstimport, Format nicht öffentlich | VERMUTET (Drittprojekt Berlussimo) | hoch: nicht für laufenden Betrieb, Format NICHT VERFÜGBAR |
| Objekte | OpenImmo-Export an Portale (FTP) | nein | Immoware24 sendet an Portal | VERMUTET (Drittquelle wohnglueck.de) | niedrig: nicht Teil des Hubs |
| Objekte | HeiWaKo/bved-Datenaustausch (B/K, L/M, D, E898) | Datei | Datei | DOKUMENTIERT | niedrig: optional, Phase 3, nur lesend |
| Einheiten (Verwaltungseinheiten) | CSV-Export Auswertungen Mieter- und VE-Stammdaten, Belegungsliste | ja, manuell | nein | DOKUMENTIERT | mittel: VE-Nummer als Schlüssel VERMUTET, Vollexport-Kennzeichnung nötig für Löscherkennung |
| Einheiten | UI (manuelle Anlage) | UI | UI | DOKUMENTIERT | keine programmatische Anbindung |
| Eigentümer | CSV-Export Auswertungen (WEG-Objekte), CardDAV-Kontakte | ja, manuell bzw. CardDAV | nein | DOKUMENTIERT (Export), Feldumfang NICHT VERFÜGBAR | mittel: MEA und Zeiträume nur, wenn in Auswertung enthalten, zu verifizieren am eigenen Mandanten |
| Mieter | CSV-Export Belegungsliste (Mieter mit VE, Mietbeginn, Mietende, Kontaktinformationen) | ja, manuell | nein | DOKUMENTIERT | mittel: manueller Rhythmus, personenbezogene Daten (DSGVO) |
| Verträge | CSV-Export Auswertungen (Mietverträge, Sollstellungen) | ja, manuell, Umfang unbekannt | nein | DOKUMENTIERT (Export allgemein), Vertragsfelder NICHT VERFÜGBAR | mittel bis hoch: Vertragsnummer als Schlüssel VERMUTET |
| Buchhaltung | DATEV-CSV-Buchungsexport (Kontenmapping, Zeitraum, Festschreibung wählbar, nur Miet- und WEG-Verwaltung, KOST2) | ja, manuell | nein | DOKUMENTIERT | mittel: Zeilenschlüssel nicht eindeutig, Duplikate werden als Duplikat gespeichert |
| Buchhaltung | Buchungsexport inklusive Belegdokumente (Update März 2026) | ja, manuell | nein | DOKUMENTIERT | niedrig: Format zu sichten |
| Buchhaltung | Banking-Client (FinTS, EBICS, lokal installiert), CAMT.053 v02/v08, MT940 STA Import | Datei (Umsatzexport aus Client) | Datei (Kontoauszug-Upload in Immoware24) | DOKUMENTIERT | niedrig für Hub: Hub liest CAMT nur zur Anzeige, spielt nichts ein |
| Buchhaltung | SEPA pain.001, pain.008 (PAIN-Version wählbar) | Datei | Immoware24 erzeugt | DOKUMENTIERT | nicht Teil des Hubs |
| OP (offene Posten) | UI-Listen (Rechnungen, Mietforderungen), CSV-Export der Auswertungen | ja, manuell, Stichtag | nein | DOKUMENTIERT (Listen), Exportformat NICHT VERFÜGBAR | mittel: Snapshot-Semantik je Stichtag nötig |
| Rechnungen (Eingang) | DMS-Posteingang, KI-Belegerkennung (Panakeia laut Drittquelle), E-Rechnung XML/ZUGFeRD, XRechnung laut Snippet | nein | ja, Upload per UI oder WebDAV-Posteingang | DOKUMENTIERT (Erkennung), Upload per WebDAV VERIFIZIERT | mittel: Zuordnung zu Objekt im DMS nicht belegt |
| Rechnungen (Ausgang) | Rechnungspläne, Verwalterhonorar, Format vermutlich PDF | UI | UI | VERMUTET (XRechnung-Ausgabe NICHT VERFÜGBAR) | keine Anbindung |
| Tickets/Vorgänge | Ticketsystem (Handbuch Kapitel 24), Tickets aus E-Mail (Ticketnummer im Betreff), Portal24, KI-Anrufbeantworter, craftware24 | UI | UI, indirekt per E-Mail mit Ticketnummer | DOKUMENTIERT | hoch für Automatisierung: keine API, E-Mail-Weg nicht als Schnittstelle dokumentiert; Hub verweist nur textuell auf Ticketnummern |
| Alle Bereiche | REST-API, Webhooks, API-Keys, Zapier, Make, n8n, Power Automate | nein | nein | NICHT VERFÜGBAR | kein Baustein darf darauf bauen; Adapter-Slot in der Registry bleibt vorgesehen |

## 3. DAV-Adapter im Detail

### 3.1 Existenz und Umfang

- Offizielle Anleitung "Anleitung zum DAV-Adapter (WebDAV, CalDAV, CardDAV) © 2023 Immoware24 GmbH" als PDF unter https://content.immoware24.de/content/manual/Anleitung_DAV-Adapter.pdf, auch erreichbar unter https://www.immoware24.de/wp-content/uploads/2020/06/Anleitung_DAV-Adapter.pdf. Status VERIFIZIERT (Titel, Herausgeber, Jahr, beide URLs im Snippet gesehen). Hinweis: "2020/06" ist der WordPress-Upload-Ordner, nicht der Dokumentstand. Volltext nicht gelesen (Host gesperrt), Mirror docplayer.org/190911131 und 123doku.com ebenfalls nicht erreichbar.
- Support-Artikel "Der Immoware24 DAV-Adapter" (https://support.immoware24.de/hc/de/articles/360010764437): "Der Immoware24 DAV-Adapter ermöglicht es, Daten aus dem Immoware24-Dokumentenmanagementsystem (DMS) sowie Kalendereinträge und Kontaktdaten auf verschiedene Geräte zu synchronisieren. Für die Bereitstellung der Daten wird das verbreitete WebDAV-Protokoll genutzt." Status VERIFIZIERT (zweiter Satz wörtlich, erster Satz im Snippet paraphrasiert und durch Nachbarartikel gestützt).
- Drei Freigabetypen: "Es gibt 3 verschiedene Freigabe Typen: Dateifreigabe, Kalender und Kontakte." (https://support.immoware24.de/hc/de/articles/360010876078). Status VERIFIZIERT. Zuordnung Dateifreigabe = WebDAV, Kalender = CalDAV, Kontakte = CardDAV ergibt sich aus dem Dokumenttitel der Anleitung.
- Buchung: "Um die Möglichkeiten der Datei-, Kalender und Kontaktfreigabe nutzen zu können, müssen Sie [...] den Support oder Ihren Ansprechpartner im Vertrieb kontaktieren, um die Funktionalitäten zu buchen." (https://support.immoware24.de/hc/de/articles/360010876038). Status VERIFIZIERT. Der DAV-Adapter ist damit ein buchbares Zusatzmodul, Kostenfrage klären. Blocker vor Phase 0.
- Drei Einrichtungsschritte nach Freischaltung: "Nutzer im DAV-Adapter freischalten, die gewünschten Freigaben anlegen und die zu replizierenden Geräte einrichten." Status VERIFIZIERT.

### 3.2 WebDAV (Dokumente)

- Netzlaufwerk: "Für die Einrichtung eines Netzlaufwerkes muss für jeden Nutzer eine Dateifreigabe im DAV-Adapter durch den 'admin' Benutzer des Mandanten angelegt worden sein." (https://support.immoware24.de/hc/de/articles/360010770277, Windows 10; macOS-Artikel 360010770777). Status VERIFIZIERT.
- Lesen und Schreiben: "Ihre Dateien direkt auf ihrem PC öffnen oder Dokumente direkt vom Scanner in das Immoware24 System hochladen." Status VERIFIZIERT.
- Livesystem-Wirkung: "Die Ordner 'Posteingang' und 'Dokumente' können überschrieben werden. Änderungen und Löschvorgänge wirken sich auf das eingebundene System aus." (Anleitung DAV-Adapter, Snippet nahezu wörtlich). Status VERIFIZIERT. Ob ausschließlich diese beiden Ordner exponiert sind oder weitere (ggf. nur lesbare) Ordner, geht aus den Snippets nicht hervor. Formulierung daher: mindestens die Ordner Posteingang und Dokumente sind beschreibbar exponiert. Ordnerumfang zu verifizieren am eigenen Mandanten.
- Scanner: "Mit der WebDAV-Schnittstelle können Sie Ihre Druck- und Scan-Geräte mit Ihrem Immoware24-DMS bzw. Posteingang verknüpfen. [...] Die gescannten Dokumente landen via WebDAV-Schnittstelle zur Weiterverarbeitung im sog. 'Posteingang' [...] Unterstützung bei der Einrichtung kann aufgrund der Vielzahl an Gerätetypen durch die Immoware24 GmbH nicht gewährleistet werden. Gemeinsam mit Toshiba stellen wir Ihnen eine fertige Lösung bereit" (https://www.immoware24.de/toshiba/). Status DOKUMENTIERT (Zitat aus der Erstsichtung, in der Gegenprüfung nicht reproduzierbar); der Zusatz "für alle Mitarbeiter verfügbar" ist VERMUTET. Der Upload vom Scanner als solcher bleibt über die Anleitung zum DAV-Adapter VERIFIZIERT.
- Freigaben sind nicht editierbar: "Dateifreigaben können nicht nachträglich bearbeitet werden. Einmal angelegte Freigaben müssen Sie erst löschen und dann neu anlegen." Status VERIFIZIERT.
- NICHT belegt (VERMUTET, zu verifizieren am eigenen Mandanten): Umfang der Schreibrechte je Ordner, Beschränkbarkeit einer Freigabe auf einzelne Ordner, Größen- oder Formatgrenzen, automatische Zuordnung hochgeladener Dateien zu Objekten, Verhalten bei ETag, sync-collection, If-None-Match, Auth-Schema (Basic oder Digest), Zulässigkeit einer serverseitigen Automatisierung nach AGB.
- Papierkorb: "Der Kunde trägt dafür Sorge, den Papierkorb innerhalb des DMS regelmäßig zu überprüfen, da die Immoware24 GmbH Dateien nach einer Aufbewahrungsfrist von 7 Tagen automatisiert löscht." (https://www.immoware24.de/agb/). Status DOKUMENTIERT (Snippet, Wortlaut nicht am Original geprüft). Fehluploads sind nach 7 Tagen nicht mehr wiederherstellbar.

### 3.3 CardDAV (Kontakte)

- Kontaktfreigabe: "Mit der Kontaktfreigabe können Sie Ihre Kontakte, unterteilt nach Kontakt-Typen, auf weitere Geräte replizieren, wie z.B. Ihr Smartphone, Tablet oder Laptop. [...] Es wird empfohlen, die Anzahl der zu synchronisierenden Kontakte und Termine durch eine Beschränkung der Freigabe zu limitieren" (Artikel 360010876078). Status VERMUTET (Gegenprüfung konnte das Snippet nicht reproduzieren). Welche Kontakttypen wählbar sind, ist NICHT VERFÜGBAR.
- Clients laut offiziellen Artikeln: iPhone (CardDAV-/CalDAV-Account), Android (Immoware24 empfiehlt die kostenpflichtige App DAVx5 aus dem Google Play Store), Outlook über das Drittanbieter-Plugin CalDav Synchronizer (Profil "Generic CalDAV/CardDAV"). Status DOKUMENTIERT (Snippets, in der Gegenprüfung nicht reproduzierbar). Thunderbird wird in den gesichteten Snippets nicht genannt (Aussage über Snippets, kein Beleg für Fehlen in der Doku).
- Schreibrichtung: Ein Snippet ohne eindeutig zuordenbare Artikelseite lautet "Bei der Synchronisation von Immoware24-Kalendern und -Kontakten kann nur lesend auf die Daten zugegriffen werden." Status VERMUTET (Quelle ist eine Zendesk-Sektionsübersicht, Protokoll nicht genannt). Für den Hub gilt: CardDAV nur lesend planen, Schreibversuche sind ausgeschlossen.
- Drittanbieter sync.blue bewirbt 1-Wege- und 2-Wege-Synchronisation (Thunderbird, laut weiteren Snippets auch Outlook, Office 365, DATEV). Status VERMUTET für alles außer der reinen Anbieteraussage; Zugangsweg unbekannt, keine Immoware24-Bestätigung.

### 3.4 CalDAV (Kalender)

- Android-Artikel (360010887358): "Setzen Sie bei jedem genutzten Kalender 'Schreibschutz erzwingen', um Änderungen in der Immoware24 Software zu verhindern." Status VERMUTET (in der Gegenprüfung nicht gesichtet). iPhone-Artikel (360010890878): "Alle Kalender- und Kontaktänderungen in Immoware24 werden regelmäßig auf dem iPhone aktualisiert." Status VERIFIZIERT (Snippet gesehen).
- Ableitung, dass der Server PUT über CalDAV annimmt, ist Interpretation und nicht belegt. Für den Hub gilt: CalDAV nur lesend, niedrige Priorität.

## 4. Datei-Exporte und -Importe (UI)

| Weg | Belegte Aussage (Quelle) | Status |
|---|---|---|
| CSV-Export Auswertungen | "Alle aufgeführten Auswertungen lassen sich über den 'Export'-Button in eine CSV-Datei exportieren." (support 360018128817) | DOKUMENTIERT |
| Kontakte CSV | "Die Exportfunktion CSV steht für Kontakte zur Verfügung. Bei der Eingabe von Telefonnummern werden nur Ziffern akzeptiert" (support 360018097757) | DOKUMENTIERT |
| Reporting | "Reports können als CSV-Datei heruntergeladen oder als PDF-Datei direkt im Dokumentenmanagement-System (DMS) gespeichert werden." (immoware24.de/funktionen/reporting/) | DOKUMENTIERT |
| DATEV | "Immoware24 ermöglicht den Export von Buchungsdaten im CSV-Format, das direkt in DATEV importiert werden kann." Kontenmapping, Zeitraum, Mandanten-/Beraternummer, Festschreibung beim Export wählbar, KOST2, "Der Buchungs-Export kann nur für Miet- und WEG-Verwaltung erfolgen." (immoware24.de/funktionen/datev/) | DOKUMENTIERT |
| DATEV mit Belegen | "Buchungsexport inklusive Belegdokumente" (Update März 2026, support 34741043041565) | DOKUMENTIERT |
| DATEV XML / Belegtransfer | keine Fundstelle | NICHT VERFÜGBAR |
| Kontoumsätze | "Unterstützt werden die Kontoauszugsformate Swift MT-940 STA, CAMT.053 v02, CAMT.053 v08, gültige Dateiendungen sind xml, txt, sta oder mt940." (support 4406428565009); Ablösung MT940 durch CAMT.053/052 bis November 2025 (support 30222978488477) | DOKUMENTIERT |
| Banking-Client | lokal installiert (Mac, Windows), FinTS/HBCI oder EBICS, täglicher automatischer Abruf, manueller Datenexport unter Datentresor > Werkzeuge > Datenexport (support 29576888539549, 4406438288913) | DOKUMENTIERT |
| SEPA | pain.001 und pain.008, PAIN-Version vor Export wählbar (support 28896190561053) | DOKUMENTIERT |
| HeiWaKo/bved | Modul Liegenschaften, Export B/K-Satz und L/M-Satz je in einer Datei, Import D-Satz und E898-Satz, optional Webservice für monatliche Verbrauchswerte, ggf. kostenpflichtig (support 6108954097309, 16615479078173, 6109432110109) | DOKUMENTIERT |
| OpenImmo | Einstellungen > Export > Schnittstellentyp OpenImmo mit Anbieter-ID, Hostname, Port 21, Pfad, Login, Passwort (wohnglueck.de) | VERMUTET (Drittquelle, nicht gesichtet). Port 21 bedeutet unverschlüsseltes FTP. |
| OpenImmo-Import | keine Fundstelle | NICHT VERFÜGBAR |
| E-Rechnung | Posteingang erkennt XML/ZUGFeRD, Buchungsvorschlag per KI; XRechnung laut Marketing-Snippet; Panakeia seit 01/2025 laut Drittquelle hausverwaltungschecker.de | DOKUMENTIERT (Erkennung), VERMUTET (Panakeia, Datum, Felder) |
| E-Post | E-POST Business API der Deutschen Post, Aktivierung unter Einstellungen > Integrationsprofile > E-Post, "Ein E-Post-Zugang mit API darf nur 1x in Immoware24 oder einer anderen kompatiblen Software eingerichtet werden", max. 97 Seiten, 15 MB (support 360020276297) | DOKUMENTIERT. Kein Zugang für den Hub. |
| Integrationsprofile | Einstellungen > Integrationsprofile mit Anrufbeantworter, Online-Banking, E-Mail, HeiWaKo-Profile/externe Abrechner, Immobilienportale, E-Post. Kein Menüpunkt "API" oder "Webhooks" genannt. | DOKUMENTIERT |

## 5. Drittanbieter und Community

| Anbieter/Projekt | Aussage | Status | Bewertung |
|---|---|---|---|
| legacy-use | "Immoware24 API Integration", technisch KI-gestützte UI-Automation über VPN/Remote Desktop, "REST API" ist die legacy-use-Schicht | VERMUTET | Kein API-Zugang. UI-Automation mit hinterlegten Zugangsdaten ist compliance- und datenschutzrechtlich gesondert zu prüfen; für den Hub nicht vorgesehen. |
| softwarefinder.com | "Immoware24 bietet API-Zugang an. Die Schnittstelle erfolgt über eine REST-API." | VERMUTET, als Verzeichnisangabe widerlegt | Generische Feature-Checkliste, keine Herstellerdokumentation. |
| hausverwaltungschecker.de | "Eine öffentliche REST-API bewirbt Immoware24 nicht. Schnittstellen sind dokumentiert für DATEV und EBICS-Banking, eine generische Anbindung an externe CRM- oder ERP-Systeme über eine offene API gibt es nicht im Selbst-Service." | VERMUTET (Quelle nicht gesichtet) | Konsistent mit dem Negativbefund. |
| sync.blue | Kontaktsynchronisation, 1-/2-Wege, zeitgesteuert | VERMUTET | Templatierte Landingpages, Zugangsweg unbekannt. |
| GetMyInvoices | Rechnungsdownload aus Immoware24, Übertragung an DATEV | VERMUTET | Kombinatorisch erzeugte SEO-Seiten, Mechanismus unbekannt. |
| BOMITO | "bietet Schnittstellen zu Immoware24" | VERMUTET | Marketingsatz ohne Technik. |
| casavi, Wohnungshelden, Immomio, Kiwi, Doorbird, Pixometer, Facilioo, Plentific, Doozer | casavi nennt Immoware24 im Integrations-Ökosystem (prop.id); Wohnungshelden nennt Kompatibilität; übrige ohne Treffer | VERMUTET bzw. NICHT VERFÜGBAR | Keine technische Beschreibung. |
| XPhone Connect (C4B) | Dashboard-Lösung, Anruferkennung, Sprung ins Immoware24-Portal, Datenquellen Outlook, CSV, ODBC, LDAP | VERMUTET | Keine native Immoware24-API. |
| hvb-tech/immoware-addons (GitHub) | Chrome-Extension "immoware24 PDF Downloader", Content-Scripts auf https://*.immoware24.de/* und https://*.awi-rems.de/* | VERMUTET | UI-Automation im Browser. Hinweis, dass die Web-App offenbar auch unter *.awi-rems.de läuft (VERMUTET). |
| BundW32/CRM (GitHub) | Platzhalter mit IMMOWARE24_API_URL und IMMOWARE24_API_KEY, "Der API-Zugang bei Immoware24 ist noch nicht freigeschaltet"; Konzeptpapier behauptet "REST-API vorhanden" | VERMUTET | Unbelegte Community-Aussage, kein Endpunkt. |
| invoice-collector (GitHub) | Immoware24-Collector nur als "sketch", version '0' | VERMUTET | Nicht implementiert. |
| BerlusGmbH/Berlussimo (GitHub, Laravel) | Artisan-Command "Generate CSV files that can be imported by Immoware24" mit Mappern für Liegenschaft, Haus, Einheit, Kontakte, Bankkonten, Verträge | VERMUTET | Hinweis auf einen CSV-Stammdatenimport bei Datenübernahme; Format nicht abrufbar (raw 404). |
| Zapier, Make, n8n, Power Automate | npm-Registry "immoware24": 0 Treffer, GitHub-Code-Suche "immoware24 n8n-nodes": 0 Treffer; App-Verzeichnisse aus der Umgebung nicht abrufbar | NICHT VERFÜGBAR (n8n belastbar), VERMUTET (Zapier, Make, Power Automate bis zur manuellen Prüfung) | Manuelle Prüfung im Browser erforderlich. |

## 6. Rahmenbedingungen aus offiziellen Quellen

- Hosting: "Alle Daten werden ausschließlich in zertifizierten Hochsicherheitsrechenzentren in Deutschland gespeichert, und es wird garantiert, dass keine Daten die Europäische Union verlassen." (immoware24.de/funktionen/sicherheit/). Status DOKUMENTIERT.
- AGB: "Der Kunde verpflichtet sich, jede Art von Tätigkeit zu unterlassen, die die Server, die Onlinesoftware und das Netzwerk schädigen könnten." und "Der Kunde hat die für Identifizierung und Authentifizierung notwendigen Daten und Passwörter vor dem Zugriff durch Dritte zu schützen und nicht an unberechtigte Nutzer weiterzugeben." Status DOKUMENTIERT (Snippets). Eine explizite Regelung zu automatisiertem Zugriff, Bots oder API wurde in den Snippets nicht gefunden (NICHT VERFÜGBAR). Eine Exportregelung bei Vertragsende ist laut Snippet nicht belegt (VERMUTET). AGB-Wortlaut vor Produktivbetrieb im Original sichern und durch Rechtsanwalt prüfen lassen.
- Nutzerrollen: SYS: Administrator, SYS: Standard, SYS: nur Lesezugriff, SYS: nur Lesezugriff Stammdaten sowie fachliche Rollen; Nutzerzugänge legt nur der Administrator an (support 360010771138). Status DOKUMENTIERT. Welche Rolle DAV-Freigaben tragen darf, ist NICHT belegt und wird in Phase 0 getestet.
- Handbuch: kapitelweise PDFs unter content.immoware24.de/content/manual/ (u. a. 7_Liegenschaften.pdf, 17_weitereAbrechnungen.pdf, 21_Postausgang.pdf, 24_Ticketsystem.pdf, Anleitung_Einrichten_Banking-Client_Immoware24.pdf), Titelzeile "©2026 Immoware24 GmbH Handbuch Update 08/2026 (v26) Revision 1.7". Status DOKUMENTIERT (Titel im Snippet), Inhalte nicht gelesen.

## 7. Offene Punkte

Kennzeichnung: WAITING_FOR_VENDOR_ACCESS = Antwort oder Freischaltung durch Immoware24 nötig; MANDANT = zu verifizieren am eigenen Mandanten mit autorisiertem Zugang.

| Nr. | Offener Punkt | Kennzeichnung |
|---|---|---|
| O1 | Buchung und Kosten des DAV-Moduls, schriftliche Bestätigung, dass automatisierter WebDAV-Zugriff durch eine Serveranwendung zulässig ist | WAITING_FOR_VENDOR_ACCESS |
| O2 | Existenz einer nicht öffentlichen Partner-API, Webhooks oder Exportmechanismen auf Anfrage | WAITING_FOR_VENDOR_ACCESS |
| O3 | Volltext der Anleitung zum DAV-Adapter, AGB-Wortlaut und alle Snippet-Zitate im Original sichern und im Repository ablegen | MANDANT |
| O4 | Ordnerumfang per WebDAV (nur Posteingang und Dokumente oder gesamte Objektstruktur), Schreibrechte je Ordner, Beschränkbarkeit einer Freigabe auf den Posteingang | MANDANT |
| O5 | Auth-Schema (Basic, Digest), ETag-Stabilität, sync-token, CTag, If-None-Match-Verhalten, Antwort auf gesperrte Methoden | MANDANT |
| O6 | Kleinste Nutzerrolle, die DAV-Freigaben tragen darf | MANDANT |
| O7 | Aufteilung der Kontaktfreigabe nach Kontakttypen, vCard-UID-Stabilität, CardDAV-Schreibverhalten (nur beobachten, nie testen durch Schreiben) | MANDANT |
| O8 | Spaltenformat, Trennzeichen, Zeichensatz und Schlüsselspalten aller CSV-Exporte (Mieter/VE, Kontakte, OP, DATEV) | MANDANT |
| O9 | Automatische Objektzuordnung hochgeladener Dateien im DMS, Dateinamensregeln, Größenlimits | MANDANT |
| O10 | Exportregelung bei Vertragsende, Zulässigkeit automatisierten Zugriffs nach AGB | WAITING_FOR_VENDOR_ACCESS, Prüfung durch Rechtsanwalt |
| O11 | Rate Limits, Quotas, Sitzungs-Timeouts (siehe 06-rate-limits.md) | WAITING_FOR_VENDOR_ACCESS |
| O12 | Manuelle Prüfung der App-Verzeichnisse von Zapier, Make, Power Automate im Browser | MANDANT |

## 8. Quellenliste

Offizielle Immoware24-Quellen (alle nur per Snippet gesehen):

- https://content.immoware24.de/content/manual/Anleitung_DAV-Adapter.pdf
- https://www.immoware24.de/wp-content/uploads/2020/06/Anleitung_DAV-Adapter.pdf
- https://config.dav.immoware24.de/login
- https://support.immoware24.de/hc/de/articles/360010764437-Der-Immoware24-DAV-Adapter
- https://support.immoware24.de/hc/de/articles/360010876038-Einrichten-der-DAV-Funktionalit%C3%A4ten
- https://support.immoware24.de/hc/de/articles/360010764417-Nutzer-f%C3%BCr-DAV-freischalten
- https://support.immoware24.de/hc/de/articles/360010876078-Freigaben-im-DAV-Adapter-einrichten
- https://support.immoware24.de/hc/de/articles/360010768217-Ger%C3%A4te-f%C3%BCr-die-Freigabe-einrichten
- https://support.immoware24.de/hc/de/articles/360010770277-Einrichten-der-Dateifreigabe-unter-Windows-10
- https://support.immoware24.de/hc/de/articles/360010770777-Einrichten-der-Dateifreigabe-unter-macOS
- https://support.immoware24.de/hc/de/articles/360010887358-Kalender-und-Kontakte-unter-Android-einbinden
- https://support.immoware24.de/hc/de/articles/360010890878-Kalender-und-Kontakte-auf-Ihrem-iPhone-einbinden
- https://support.immoware24.de/hc/de/articles/360010883978-Kalender-und-Kontakte-in-Outlook-einbinden
- https://support.immoware24.de/hc/de/sections/360003105797-Freigabe-von-Dateien-Kontakten-und-Termine
- https://www.immoware24.de/toshiba/
- https://www.immoware24.de/agb/
- https://www.immoware24.de/funktionen/ (datev, banking, dokumentenmanagement, portal24, craftware24, ki-anrufbeantworter, postausgang, reporting, sicherheit)
- https://support.immoware24.de/hc/de/articles/360018128817-Auswertungen-Mieter-und-Verwaltungseinheiten-Stammdaten
- https://support.immoware24.de/hc/de/articles/360018097757-Kontakte-im-Adressbuch-anlegen
- https://support.immoware24.de/hc/de/articles/4406428565009-Kontoumsatzdateien-in-Immoware24-importieren
- https://support.immoware24.de/hc/de/articles/30222978488477-Umstellung-auf-CAMT-V8-Abl%C3%B6sung-von-SWIFT-MT940-MT942-bis-November-2025
- https://support.immoware24.de/hc/de/articles/27328867369245-EBICS-Version-3-0-Abruf-von-E-Kontoausz%C3%BCgen
- https://support.immoware24.de/hc/de/articles/29576888539549-Immoware24-Banking-Client-Mac-Windows
- https://support.immoware24.de/hc/de/articles/4406438288913-Ums%C3%A4tze-manuell-aus-dem-Immoware24-Banking-Client-exportieren
- https://support.immoware24.de/hc/de/articles/28896190561053-SEPA-XML-Dateien-richtig-exportieren-Die-passende-PAIN-Version-ausw%C3%A4hlen
- https://support.immoware24.de/hc/de/articles/29058890776605-Checkliste-f%C3%BCr-Lastschrifteinzug-mit-Immoware24
- https://support.immoware24.de/hc/de/articles/360014122857-Auftr%C3%A4ge-im-Banking-Client-lassen-sich-nicht-senden
- https://support.immoware24.de/hc/de/articles/6108954097309-Heizkosten-Messdienstleister-einrichten
- https://support.immoware24.de/hc/de/articles/16615479078173-Datenaustausch-Export-B-K-Satz-L-M-Satz
- https://support.immoware24.de/hc/de/articles/6109432110109-Hauptfehlerquellen-beim-Datenaustausch-Heizkosten
- https://support.immoware24.de/hc/de/sections/6082871702685-Liegenschaft-Abrechner-Schnittstelle
- https://support.immoware24.de/hc/de/articles/360020276297-Registrierung-der-E-Post-Schnittstelle-der-Deutsche-Post
- https://support.immoware24.de/hc/de/articles/360013864198-Postausgang-Sammler-und-Reihenfolge-der-Dokumente
- https://support.immoware24.de/hc/de/articles/34741043041565-Update-M%C3%A4rz-2026-Neue-Funktionen-Verbesserungen
- https://support.immoware24.de/hc/de/articles/15389245891357-Zwei-Faktor-Authentifizierung-aktivieren
- https://support.immoware24.de/hc/de/articles/360010771138-Immoware24-Nutzerrollen
- https://support.immoware24.de/hc/de/articles/5152396034973-Gmail-mit-2-Faktor-Authentifizierung
- https://support.immoware24.de/hc/de/articles/4406394652305-Mandanten-Registrierung-Portal24-Mieter-und-Eigent%C3%BCmerportal
- https://support.immoware24.de/hc/de/articles/29277189808925-Shared-Mail-Account-freigegebenes-Postfach-anbinden
- https://support.immoware24.de/hc/de/articles/5556851032733-Konfiguration-Dateiname-f%C3%BCr-Rechnungsdokumente-im-Posteingang
- https://support.immoware24.de/hc/de/articles/24670590424349-Aktivierung-und-Nutzung-der-KI-Features
- https://support.immoware24.de/hc/de/articles/15739650394525-Zeiterfassung-f%C3%BCr-Tickets-konfigurieren
- https://support.immoware24.de/hc/de/articles/360018289618-Verwaltungseinheiten-und-Eigent%C3%BCmer-im-GdWE-WEG-Objekt-anlegen
- https://support.immoware24.de/hc/de/articles/360021131178-Buchen-von-Rechnungen
- https://support.immoware24.de/hc/de/articles/4415009631761-Rechnungspl%C3%A4ne-erstellen
- https://support.immoware24.de/hc/de/articles/29577020549405-Handb%C3%BCcher-Portal24
- https://support.immoware24.de/hc/de/articles/28194697915933-Immoware24-Benutzerhandbuch
- https://support.immoware24.de/hc/de/articles/29704524642845-Rechnungswesen
- https://support.immoware24.de/hc/de/sections/360003998537-Einstellungen
- https://support.immoware24.de/hc/de/sections/360004352798-Dokumentenmanagementsystem-DMS
- https://support.immoware24.de/hc/de/sections/4406388901521-Portal24
- https://support.immoware24.de/hc/de/categories/360001987558-Kommunikation-und-Dateimanagement
- https://content.immoware24.de/content/manual/7_Liegenschaften.pdf
- https://content.immoware24.de/content/manual/24_Ticketsystem.pdf

Drittquellen:

- https://www.hausverwaltungschecker.de/immoware24/
- https://www.legacy-use.com/solutions/immoware24/
- https://softwarefinder.com/property-management-software/immoware24
- https://www.sync.blue/de/app/immoware24/
- https://www.getmyinvoices.com/en/automatic/immoware24-for-datev-30021-35346
- https://www.getmyinvoices.com/en/online-portals/immoware24-bills-invoices-download-30021
- https://bomito.com/perfekt-fuer-ihre-branche/immobilien/
- https://wohnglueck.de/page/einrichtung-der-schnittstelle-zu-immoware24
- https://www.wohnungshelden.de/
- https://www.immomio.com/vermieter/partner-schnittstellen/
- https://www.prop.id/blog/crm-systeme-fuer-die-immobilienverwaltung
- https://trusted.de/immoware24
- https://www.frings-itshop.de/telekommunikation-ucc-wearables/pbx-loesungen/cti-uc-applications-independant/c4b/c4b-xphone-connect-dashboard-solution-fuer-immoware24.html
- https://github.com/hvb-tech/immoware-addons
- https://github.com/BundW32/CRM/blob/main/portal/src/lib/immoware24.ts
- https://github.com/BundW32/CRM/blob/main/docs/KONZEPT.md
- https://github.com/invoice-collector/invoice-collector/blob/main/src/collectors/sketch/immoware24/immoware24.ts
- https://github.com/BerlusGmbH/Berlussimo/blob/master/app/Console/Commands/Immoware24Export.php
- https://docplayer.org/190911131-Anleitung-zum-dav-adapter-webdav-caldav-carddav.html (Mirror)
- https://docplayer.org/44974581-Schnittstelle-buchungsexport-datev.html (Mirror, historisch)
- https://123doku.com/document/16c32_anleitung-zum-dav-adapter-webdav-caldav.html (Mirror)

Direkt geprüfte Registries (erreichbar, Negativbefund am 11.09.2026):

- https://packagist.org/search.json?q=immoware24 (Ergebnis: {"results":[],"total":0})
- https://registry.npmjs.org/-/v1/search?text=immoware24 (Ergebnis: "total":0)
- https://github.com/search?q=immoware24&type=repositories (einziger Treffer: v3ni94/IMMOWARE24, das eigene Projekt-Repository)

## 9. Blockierte URLs (aus der Rechercheumgebung nicht abrufbar, Stand 11.09.2026)

Ursache jeweils EGRESS_BLOCKED bzw. Proxy CONNECT 403, sofern nicht anders angegeben. Inhalte dieser Seiten wurden ausschließlich über Suchmaschinen-Snippets bewertet.

- https://content.immoware24.de/content/manual/Anleitung_DAV-Adapter.pdf
- https://www.immoware24.de/wp-content/uploads/2020/06/Anleitung_DAV-Adapter.pdf
- https://support.immoware24.de/
- https://support.immoware24.de/hc/de/articles/360010764437-Der-Immoware24-DAV-Adapter
- https://config.dav.immoware24.de/login
- https://docplayer.org/190911131-Anleitung-zum-dav-adapter-webdav-caldav-carddav.html
- https://123doku.com/document/16c32_anleitung-zum-dav-adapter-webdav-caldav.html
- https://info.sync.blue/app/immoware24/de/
- https://www.sync.blue/de/sync/mozilla-thunderbird/immoware24/
- https://support.immoware24.de/hc/de/articles/34741043041565-Update-M%C3%A4rz-2026-Neue-Funktionen-Verbesserungen
- https://support.immoware24.de/hc/de/articles/6108954097309-Heizkosten-Messdienstleister-einrichten
- https://support.immoware24.de/hc/de/articles/16615479078173-Datenaustausch-Export-B-K-Satz-L-M-Satz
- https://support.immoware24.de/hc/de/articles/29577020549405-Handb%C3%BCcher-Portal24
- https://content.immoware24.de/content/manual/7_Liegenschaften.pdf
- https://content.immoware24.de/content/manual/Produktbroschuere_Immoware24.pdf
- https://www.sync.blue/de/app/immoware24/
- https://info.sync.blue/sync/immoware24/carddav/en/
- https://www.legacy-use.com/solutions/immoware24/
- https://wohnglueck.de/page/einrichtung-der-schnittstelle-zu-immoware24
- https://docplayer.org/44974581-Schnittstelle-buchungsexport-datev.html (DNS ENOTFOUND)
- https://bomito.com/perfekt-fuer-ihre-branche/immobilien/
- https://www.getmyinvoices.com/en/automatic/immoware24-for-datev-30021-35346
- https://www.immomio.com/vermieter/partner-schnittstellen/
- https://www.wohnungshelden.de/
- https://trusted.de/immoware24
- https://suitapp.de/software/immoware24/
- https://softwarefinder.com/property-management-software/immoware24
- https://vdiv.de/partneruebersicht/immoware24
- https://support.software24.com/support/solutions/articles/101000538495
- https://casamanager.de/immoware24-alternative/
- https://www.prop.id/blog/crm-systeme-fuer-die-immobilienverwaltung
- https://www.pressebox.de/pressemitteilung/immoware-24-gmbh/...boxid/858297
- https://www.hausverwaltungschecker.de/immoware24/
- https://www.openpr.de/news/759810/
- https://www.softwareworld.co/software/immoware24-reviews/
- https://www.softwaresuggest.com/immoware24
- https://www.immobilienverwaltung-raatz.de/wp-content/uploads/Handbuch-Portal24-der-Immobilienverwaltung-Raatz.pdf
- https://huchel-medienagentur.de/wissen/immoscout24-objekte-eigene-website
- https://www.casavi.com/de/integrationen/
- https://connect-docs-de.locoia.com/partner-and-apps/casavi
- https://www.softguide.de/programm/immoware24-online-software-fuer-die-immobilienverwaltung
- https://softwarevergleich.de/detail/immobilien/immoware24
- https://www.pipedrive.com/en/marketplace/app/sync-blue/61f49722be5c76e8
- https://www.cbinsights.com/company/immoware24/alternatives-competitors
- https://www.immoware24.de/
- https://support.immoware24.de/hc/de/articles/27328867369245-EBICS-Version-3-0-Abruf-von-E-Kontoausz%C3%BCgen
- https://support.immoware24.de/hc/de/articles/4406438288913-Ums%C3%A4tze-manuell-aus-dem-Immoware24-Banking-Client-exportieren
- https://support.immoware24.de/hc/de/articles/360020276297-Registrierung-der-E-Post-Schnittstelle-der-Deutsche-Post
- https://support.immoware24.de/hc/de/articles/360018128817-Auswertungen-Mieter-und-Verwaltungseinheiten-Stammdaten
- https://content.immoware24.de/content/manual/17_weitereAbrechnungen.pdf
- https://content.immoware24.de/content/manual/Anleitung_Einrichten_Banking-Client_Immoware24.pdf
- https://www.openpr.de/news/759810/Immobilien-Software-Anbieter-Immoware24-integriert-Online-Banking-und-SEPA.html
- https://relay.immo/messdienstleister/
- https://hausverwaltung-reiner.de/warum-wir-immoware24-verwenden/
- https://www.softguide.de/alternativen/immoware24-online-software-fuer-die-immobilienverwaltung
- https://www.the-playbook.de/de/maerkte/immobilienverwaltung-dach/
- https://www.wp-immomakler.de/en/
- https://www.immobilienanzeigen24.com/content/openimmo-schnittstelle-partner.html
- https://support.immoware24.de/hc/de
- https://support.immoware24.de/hc/de/articles/28194697915933-Immoware24-Benutzerhandbuch
- https://support.immoware24.de/hc/de/sections/6082871702685-Liegenschaft-Abrechner-Schnittstelle
- https://support.immoware24.de/hc/de/categories/360001987558-Kommunikation-und-Dateimanagement
- https://support.immoware24.de/hc/de/categories/360001386158-Ihr-Immoware24-Mandant
- https://support.immoware24.de/hc/de/articles/360010887358-Kalender-und-Kontakte-unter-Android-einbinden
- https://support.immoware24.de/hc/de/articles/15389245891357-Zwei-Faktor-Authentifizierung-aktivieren
- https://support.immoware24.de/hc/de/articles/29577116294173-Allgemeine-Dokumente
- https://content.immoware24.de/content/manual/1_2_Benutzeranmeldung_Oberflaeche_Module.pdf
- https://silo.tips/download/1-prambel-2-vertragsgegenstand
- https://www.yumpu.com/de/document/view/41288039/immoware24/3
- https://manualzz.com/doc/4330447/
- https://www.vermieter-ratgeber.de/fachmagazin/advertorial/neue-zeiten-erfordern-ein-neues-denken.html
- https://portal.hausenimmo.de/portal/data-source/show-text-external/terms_of_use
- https://www.immoware24.de/ (alle Pfade, u. a. /toshiba/, /funktionen/datev/, /agb/, /karriere/)
- https://support.immoware24.de/ (alle Artikel)
- https://www.xphone-connect.com/en/integrations/software/crm/
- https://techinhalt.de/immoware24-erfahrungen/
- https://haus-8.de/software/erp/immoware24/
- https://verzeichnis.digital-affin.de/software/immoware24-erfahrungen/
- https://www.getmyinvoices.com/en/online-portals/immoware24-bills-invoices-download-30021
- https://www.frings-itshop.de/.../c4b-xphone-connect-dashboard-solution-fuer-immoware24.html
- https://www.software-journal.de/2020/06/15/...
- https://raw.githubusercontent.com/... (404 für Berlussimo, BundW32/CRM, invoice-collector; GitHub-MCP nur für v3ni94/IMMOWARE24 freigegeben; gh CLI nicht installiert)
- Archiv- und Umwegdienste: web.archive.org, archive.ph, archive.org/wayback/available, r.jina.ai, Google, Bing, DuckDuckGo, Startpage (alle Proxy 403 bzw. EGRESS_BLOCKED)

Das WebSearch-Budget der Recherchesitzung war am Ende erschöpft (200/200); mehrere Gegenprüfungen konnten daher keine Snippets mehr nachziehen und haben Aussagen im Zweifel herabgestuft.
