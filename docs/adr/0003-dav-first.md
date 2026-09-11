# ADR 0003: DAV-Adapter als primärer technischer Zugangsweg (DAV first)

Status: Angenommen, unter Vorbehalt der Phase-0-Ergebnisse
Datum: 11.09.2026
Entscheider: Projektleitung Immoware Hub

## Kontext

Für die technische Anbindung des Immoware24-Mandanten wurden alle recherchierten Zugangswege bewertet. Ergebnis der Schnittstellenrecherche (Details in `docs/immoware/`):

| Zugangsweg | Status | Bewertung |
|---|---|---|
| WebDAV, CalDAV, CardDAV über den DAV-Adapter | VERIFIZIERT (Existenz, Protokolle, Konfigurationsportal, Freigabe-Passwort je Nutzer, Aktivierung für den Account erforderlich) | einziger standardisierter, offiziell dokumentierter Nicht-Browser-Zugang |
| CSV-Export der Auswertungen, DATEV-CSV, CAMT.053 | DOKUMENTIERT, manuell über UI und Banking-Client | Datei-Import, kein Onlinezugang |
| REST-API, Webhooks, API-Keys | NICHT VERFÜGBAR (kein Developer-Portal, keine SDKs in Packagist, npm, GitHub) | ausgeschlossen |
| UI-Automation (Browser, RPA, legacy-use, Scraper-Skizzen) | VERMUTET als Drittlösungen | ausgeschlossen: Umgehung von 2FA, Scraping als Datenbankersatz, AGB-Risiko |
| OpenImmo (FTP zu Portalen) | VERMUTET (Drittquelle) | nicht Teil des Hubs |
| E-Post, Portal24, craftware24, KI-Anrufbeantworter, Ticketsystem | DOKUMENTIERT als UI-Funktionen; Fremd-API NICHT VERFÜGBAR (Negativbefund) | nicht anbindbar |

Zum DAV-Adapter ist zusätzlich belegt:

- Web-UI unter https://config.dav.immoware24.de/login, Freigaben legt der Benutzer admin des Mandanten an, Freigaben sind nicht nachträglich editierbar (VERIFIZIERT, Snippets der offiziellen Anleitung und Support-Artikel).
- Endnutzer setzen ein separates Freigabe-Passwort, das für alle Freigaben des Nutzers gilt; Benutzername ist der Immoware24-Benutzername (VERIFIZIERT).
- Die DAV-Funktionalitäten müssen über Support oder Vertrieb gebucht werden (VERIFIZIERT, Wortlaut "buchen", Artikel 360010876038). Ob und in welcher Höhe Kosten anfallen, ist Ableitung (VERMUTET, WAITING_FOR_VENDOR_ACCESS).
- Zwei-Faktor-Authentifizierung des Web-Logins ist mandantenweit aktivierbar (DOKUMENTIERT). Der DAV-Adapter nutzt davon getrennte Zugangsdaten (VERIFIZIERT für das separate Passwort; das Authentifizierungsverfahren des DAV-Endpunkts, Basic oder Digest, ist VERMUTET).

## Entscheidung

Der Hub baut primär auf dem DAV-Adapter auf:

1. WebDAV (Dateifreigabe) für den Dokumentenspiegel und für den einzigen Schreibpfad (create-only in den Posteingang).
2. CardDAV (Kontaktfreigabe) als lesende Kontaktquelle.
3. CalDAV (Kalenderfreigabe) als lesende Terminquelle mit niedriger Priorität.

Manuelle Datei-Exporte (CSV, DATEV, CAMT) ergänzen den DAV-Zugang für Stammdaten und Buchhaltung, weil diese Daten nicht über DAV verfügbar sind.

UI-Automation, Scraping und jede Form der Umgehung von Anmeldung oder 2FA sind ausgeschlossen.

## Begründung

- DAV ist der einzige Zugang, für den Immoware24 selbst eine Nutzung durch Fremdclients (Windows Explorer, Finder, DAVx5, CalDav Synchronizer, Scanner) dokumentiert. Ein Standard-DAV-Client des Hubs ist damit fachlich derselbe Nutzungstyp.
- DAV liefert ETag, getlastmodified, getcontentlength und ggf. sync-token oder CTag als Änderungsmerkmale. Das ersetzt kein updated_at, erlaubt aber hash-basierte Änderungserkennung mit vertretbarer Last.
- Das getrennte Freigabe-Passwort erlaubt einen dedizierten technischen Nutzer ohne Berührung des 2FA-geschützten Web-Logins.

## Vorbehalte und Bedingungen

Die Entscheidung gilt nur, wenn Phase 0 folgende Punkte bestätigt (alle derzeit VERMUTET oder NICHT VERFÜGBAR):

- Auth-Schema des DAV-Endpunkts und dessen Verhalten gegenüber einem Serverclient.
- ETag-Stabilität, Unterstützung von sync-collection (RFC 6578), CTag, If-None-Match.
- Tatsächlicher Ordnerumfang per WebDAV (mindestens Posteingang und Dokumente sind belegt).
- Ob eine Dateifreigabe auf den Posteingang beschränkbar ist.
- Welche Immoware24-Nutzerrolle DAV-Freigaben tragen darf.
- Schriftliche Bestätigung des Immoware24-Supports, dass ein automatisierter WebDAV-Zugriff durch eine Serveranwendung zulässig ist. Die AGB enthalten laut Snippet nur allgemeine Pflichten (Server nicht schädigen, Zugangsdaten schützen), der Wortlaut ist nicht geprüft (WAITING_FOR_VENDOR_ACCESS, Prüfung durch Rechtsanwalt).

## Rückfallentscheidung

Verweigert Immoware24 die automatisierte DAV-Nutzung schriftlich oder scheitert die Probe grundlegend, wird der Hub ein reiner Datei-Import-Spiegel (CSV, DATEV, CAMT) ohne DAV, und der Schreibpfad entfällt vollständig. Liefert der Server keine stabilen ETags und keinen sync-token, werden Intervalle verlängert (Posteingang 60 Minuten, Dokumente wöchentlich), nicht die Rate erhöht.

## Konsequenzen

- Vor Baubeginn müssen das DAV-Modul gebucht, technische Nutzer angelegt und Freigaben durch admin eingerichtet sein (Blocker Phase 0).
- Ein Reset des Freigabe-Passworts betrifft alle Synchronisationen des Nutzers (VERIFIZIERT); der Hub braucht einen dokumentierten Rotationsprozess und erkennt 401 im Health-Check.
- Der Hub darf niemals DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK oder PUT ohne If-None-Match: * senden, weil Änderungen und Löschungen sofort im Livesystem wirken und der Papierkorb nach 7 Tagen geleert wird.
