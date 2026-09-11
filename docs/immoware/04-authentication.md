# Authentifizierung und Zugangsverwaltung gegenüber Immoware24

Projekt: Immoware Hub. Stand: 11.09.2026.
Regeln: Nur eigener autorisierter Zugang. Keine Umgehung von Authentifizierung oder 2FA. Kein Login-Scraping der Web-Oberfläche. Jede Aussage über Immoware24 trägt ihren Belegstatus (VERIFIZIERT, DOKUMENTIERT, VERMUTET, NICHT VERFÜGBAR). Details zur Statusdefinition und zur Quellenlage stehen in 01-interface-discovery.md.

## 1. Ergebnis

1. Es gibt keinen belegten API-Key-, OAuth- oder Token-Mechanismus bei Immoware24. Status NICHT VERFÜGBAR. Der Hub baut auf keinem dieser Verfahren.
2. Der Web-Login von Immoware24 ist durch 2FA (TOTP) absicherbar und für Automatisierung ungeeignet. Der Hub nutzt ihn nicht.
3. Der einzige belegte Nicht-Browser-Zugang ist der DAV-Adapter mit einem separaten Freigabe-Passwort je Nutzer. Benutzername ist der Immoware24-Benutzername. Das Verfahren des DAV-Endpunkts (Basic oder Digest) ist NICHT belegt und wird in Phase 0 per Probe festgestellt.
4. Freigaben legt ausschließlich der Benutzer "admin" des Mandanten über die Web-UI https://config.dav.immoware24.de/login an. Es gibt keinen programmatischen Weg, Freigaben oder Nutzer im DAV-Adapter zu verwalten.
5. Konsequenz: Zwei dedizierte technische Immoware24-Nutzer (lesen, schreiben), jeweils mit eigenem Freigabe-Passwort, verschlüsselt im Hub hinterlegt, mit dokumentiertem Rotationsprozess. Aktivierung des Schreibpfads nur im Vier-Augen-Prinzip nach Freigabe der Geschäftsführung.

## 2. Belegte Fakten zur Authentifizierung

### 2.1 Web-Login und 2FA

| Aussage | Quelle | Status |
|---|---|---|
| "Die Zwei-Faktor-Authentifizierung kann durch einen Nutzer mit Administratorrechten im Einstellungsmenü unter 'Einstellungen/ Sicherheit' aktiviert werden [...] erscheint ein QR-Code, welchen Sie mit einer Authenticator-App (Google Authenticator, Microsoft Authenticator, etc.) über Ihr Smartphone einscannen müssen." | https://support.immoware24.de/hc/de/articles/15389245891357-Zwei-Faktor-Authentifizierung-aktivieren | DOKUMENTIERT (Snippet) |
| "Nach der Eingabe Ihres Passworts wird ein zweiter Faktor, ein einmalig gültiger 6-stelliger Code, abgefragt." Wiederherstellungscodes zur einmaligen Verwendung werden bei Einrichtung angezeigt. | wie oben | DOKUMENTIERT (Snippet) |
| 2FA global oder individuell aktivierbar | wie oben | DOKUMENTIERT (Snippet) |
| Nutzerrollen: SYS: Administrator (alle Rechte), SYS: Standard, SYS: nur Lesezugriff, SYS: nur Lesezugriff Stammdaten; "Das Anlegen von Nutzerzugängen kann nur mit Nutzern der Rolle Administrator durchgeführt werden." | https://support.immoware24.de/hc/de/articles/360010771138-Immoware24-Nutzerrollen | DOKUMENTIERT (Snippet) |
| Der Begriff "App-Passwort" erscheint im Support-Center nur im Kontext externer Mailkonten (Gmail mit 2FA, App-Passwort wird im Immoware24-Posteingang/Postausgang hinterlegt). Ein Immoware24-eigenes App-Passwort für API-Zugriff ist nicht dokumentiert. | https://support.immoware24.de/hc/de/articles/5152396034973-Gmail-mit-2-Faktor-Authentifizierung | DOKUMENTIERT (Fund), NICHT VERFÜGBAR (API-App-Passwort) |
| Die Web-App wird laut einer Community-Browser-Extension unter https://*.immoware24.de/* und https://*.awi-rems.de/* gematcht. | https://github.com/hvb-tech/immoware-addons/blob/main/manifest.json | VERMUTET, für den Hub ohne Bedeutung, da kein Web-Login automatisiert wird |

Konsequenz: Der Hub führt nie einen Web-Login gegen Immoware24 aus, speichert keine TOTP-Geheimnisse von Immoware24-Nutzern und umgeht 2FA nicht. Für alle Immoware24-Benutzer der Hausverwaltung Müller GmbH, auch für die technischen Nutzer, bleibt 2FA am Web-Login aktiv.

### 2.2 DAV-Adapter: Konfigurationsportal

| Aussage | Quelle | Status |
|---|---|---|
| Login-Seite https://config.dav.immoware24.de/login mit Titel "Dav-Adapter Login Page" | https://config.dav.immoware24.de/login | VERIFIZIERT (URL, Titel). Eine im Suchindex-Snippet sichtbare Versionsnummer wird nicht übernommen (Host gesperrt, VERMUTET, für den Hub ohne Nutzen) |
| "Tragen Sie im Feld 'Benutzername' den Namen 'admin' ein, im Feld 'Passwort' das in Immoware24 hinterlegte Passwort ein und klicken Sie auf die Schaltfläche 'Anmelden'." und "Klicken Sie auf den Menüpunkt 'Verwaltung'." | Anleitung zum DAV-Adapter (PDF, © 2023 Immoware24 GmbH), Snippet | VERIFIZIERT |
| "Für die Einrichtung eines Netzlaufwerkes muss für jeden Nutzer eine Dateifreigabe im DAV-Adapter durch den 'admin' Benutzer des Mandanten angelegt worden sein." | https://support.immoware24.de/hc/de/articles/360010770277 | VERIFIZIERT |
| Nutzer im DAV-Adapter freischalten: "wählen Sie im Menüpunkt 'Benutzer' das jeweils gewünschte Modul (Kalender, Datei oder Kontakte) aus, indem Sie die zugehörige Checkbox anklicken." "Jeder User benötigt eigene Freigaben." | https://support.immoware24.de/hc/de/articles/360010764417 und 360010876078 | VERIFIZIERT (Menüpunkt "Benutzer"), Pfadteil "Verwaltung" > "Benutzer" VERMUTET |
| "Dateifreigaben können nicht nachträglich bearbeitet werden. Einmal angelegte Freigaben müssen Sie erst löschen und dann neu anlegen." | https://support.immoware24.de/hc/de/articles/360010876078 | VERIFIZIERT |
| Pfad https://config.dav.immoware24.de/share für Endnutzer | Snippet zum Android-/iPhone-Artikel | VERMUTET (nur /login sicher gesehen, Menüpunkt "Meine Freigaben" VERIFIZIERT) |

Welches Passwort für "admin" gilt (das Immoware24-Passwort des Admin-Kontos oder ein separat hinterlegtes), geht aus den Snippets nicht eindeutig hervor. Ob der Admin-Login am Konfigurationsportal 2FA verlangt, ist NICHT VERFÜGBAR. Beides zu verifizieren am eigenen Mandanten.

### 2.3 DAV-Adapter: Endnutzer und Freigabe-Passwort

| Aussage | Quelle | Status |
|---|---|---|
| "Öffnen Sie die Webadresse https://config.dav.immoware24.de/login und melden Sie sich mit Ihren Immoware24-Login-Daten an." | https://support.immoware24.de/hc/de/articles/360010887358 (Android) | VERIFIZIERT |
| "Klicken Sie im Hauptmenü oben auf den Menüpunkt 'Meine Freigaben'. Das Fenster 'Meine Freigaben' öffnet sich [...] in dem Sie Ihr individuelles Passwort für den Zugriff auf die Freigaben festlegen." | Anleitung DAV-Adapter / Support-Artikel 360010768217 | VERIFIZIERT |
| "Das Passwort sollte von Ihrem Immoware24-Passwort abweichen und gilt für alle Ihre Freigaben." | wie oben (Anleitung DAV-Adapter, Support-Artikel 360010768217) | VERIFIZIERT. Dies ist die verbindliche Quellenzuordnung für dieses Zitat in allen Dokumenten des Repositories |
| "Als Benutzernamen verwenden Sie Ihren Immoware24-Benutzernamen. Verwenden Sie das in Schritt (2) angelegte Passwort." | wie oben, Windows-Artikel 360010770277 | VERIFIZIERT |
| "Sollten Sie Ihr Passwort vergessen, können Sie ein neues Passwort vergeben [...] Schaltfläche 'neues Passwort vergeben'", Feld "Neues Passwort für Freigaben", Schaltfläche "Passwort generieren" | wie oben | VERIFIZIERT |
| "Sollten Sie das Passwort im Immoware24 DAV-Adapter zurücksetzen, so müssen Sie es auch in allen Synchronisationen anpassen." | Android-Artikel 360010887358 | DOKUMENTIERT (nicht wörtlich in der Gegenprüfung gesehen, logische Folge von "gilt für alle Ihre Freigaben") |
| Freigabe-Links werden je Nutzer im Portal angezeigt und kopiert ("Kopieren Sie den Link unter Modul - CalDAV [...] oder den Link unter Modul - CardDAV", "Klicken Sie in der Zeile 'WebDAV' hinter dem Link auf die Schaltfläche 'Kopieren'") | Android-Artikel, Windows-Artikel | VERIFIZIERT für WebDAV-Link, DOKUMENTIERT für CalDAV/CardDAV-Modul-Links |

Ableitungen:

- Es gibt genau ein Freigabe-Passwort pro Nutzer, das für WebDAV, CardDAV und CalDAV dieses Nutzers gemeinsam gilt (VERIFIZIERT). Ein App-Passwort-Konzept mit mehreren Tokens je Nutzer und Einzelwiderruf ist nicht dokumentiert (NICHT VERFÜGBAR). Die Formulierung "genau ein" ist streng genommen eine leichte Überinterpretation von "gilt für alle Ihre Freigaben"; belastbar ist "mindestens ein, Mehrfach-Passwörter nicht dokumentiert".
- Ein 2FA-Bezug des Freigabe-Passworts wird nicht erwähnt (NICHT VERFÜGBAR). Damit ist der DAV-Adapter der legitime, vom Hersteller vorgesehene Nicht-Browser-Zugang, der 2FA nicht umgeht, sondern ein eigenes Geheimnis nutzt.
- Das HTTP-Authentifizierungsschema des DAV-Endpunkts (Basic, Digest, sonstiges) ist NICHT belegt. Die Annahme "Basic-Auth-artig" ist VERMUTET und darf nicht in den Code einfließen, bevor die Probe es festgestellt hat. Der Credential-Store des Hubs ist verfahrensneutral (auth_scheme ENUM 'unknown','basic','digest' in immoware_connections).
- Die Server-URL je Freigabe ist ein individueller, aus dem Portal kopierter Link. Ein Pfadschema ist öffentlich nicht dokumentiert (VERMUTET, dass es nutzerindividuell ist). Der Hub speichert den Link verschlüsselt (base_url_encrypted) und leitet keine Pfade ab.

### 2.4 Voraussetzungen und Freischaltung

| Aussage | Quelle | Status |
|---|---|---|
| "Der DAV-Adapter nutzt für die Bereitstellung der Daten das verbreitete WebDAV-Protokoll und benötigt eine Aktivierung für den Account." | Anleitung DAV-Adapter, Snippet | VERIFIZIERT |
| Buchung über Support oder Vertrieb ("um die Funktionalitäten zu buchen") | https://support.immoware24.de/hc/de/articles/360010876038 | VERIFIZIERT; Kostenpflicht ist Ableitung aus "buchen" (VERMUTET, WAITING_FOR_VENDOR_ACCESS) |
| AGB: Zugangsdaten "vor dem Zugriff durch Dritte zu schützen und nicht an unberechtigte Nutzer weiterzugeben"; der Kunde stellt sicher, dass benannte Nutzer die Vertragsbedingungen einhalten | https://www.immoware24.de/agb/ | DOKUMENTIERT (Snippet, Wortlaut nicht am Original geprüft) |
| Explizite Regelung zu automatisiertem Zugriff in den AGB | keine Fundstelle | NICHT VERFÜGBAR |

Einschätzung, keine Rechtsberatung: Ein technischer Nutzer, der der Hausverwaltung Müller GmbH gehört und dessen Zugangsdaten nur in der eigenen Infrastruktur liegen, ist kein "unberechtigter Dritter" im Sinne der zitierten Klausel. Ob eine serverseitige Automatisierung über den DAV-Adapter vertraglich zulässig ist, ist offen (WAITING_FOR_VENDOR_ACCESS). Vor Produktivbetrieb: schriftliche Bestätigung des Immoware24-Supports einholen und AGB durch Rechtsanwalt prüfen lassen.

## 3. Zugangskonzept des Hubs

### 3.1 Technische Nutzer in Immoware24

| Nutzer | Zweck | Rolle in Immoware24 | DAV-Module | Freigaben |
|---|---|---|---|---|
| hub-read (Arbeitsname) | Lesen: WebDAV-Metadaten, CardDAV, CalDAV | kleinste Rolle, die DAV-Freigaben trägt; Kandidat SYS: nur Lesezugriff oder SYS: nur Lesezugriff Stammdaten. Welche Rolle DAV tragen darf, ist NICHT belegt, Testpunkt Phase 0 (O6) | Datei, Kontakte, optional Kalender | Dateifreigabe (gesamter freigegebener DMS-Umfang), Kontaktfreigabe(n), Kalenderfreigabe |
| hub-write (Arbeitsname) | Schreiben: create-only Upload in den Posteingang | kleinste Rolle mit Schreibrecht auf den Posteingang; zu ermitteln in Phase 0 | nur Datei | Dateifreigabe, wenn möglich auf den Posteingang beschränkt (Beschränkbarkeit VERMUTET, Testpunkt O4). Ist keine Beschränkung möglich, bleibt die Trennung organisatorisch und der Schutz liegt bei den Code-Guards des Schreibpfads. Diese Abhängigkeit ist im Freigabeprotokoll der Geschäftsführung zu benennen. |

Regeln:

- Beide Nutzer sind personenunabhängig benannt, haben eine eigene, funktionale E-Mail-Adresse der Hausverwaltung Müller GmbH und 2FA am Web-Login aktiviert. Der Web-Login wird ausschließlich manuell durch einen Administrator benutzt (Einrichtung, Passwortwechsel).
- Freigaben werden vom Immoware24-Administrator der Hausverwaltung Müller GmbH manuell im Konfigurationsportal angelegt. Es gibt keinen programmatischen Weg (VERIFIZIERT: Web-UI, Freigaben nicht editierbar).
- Für jeden technischen Nutzer wird im Portal ein eigenes Freigabe-Passwort gesetzt, das vom Immoware24-Passwort abweicht (offizielle Empfehlung, VERIFIZIERT). Es wird über "Passwort generieren" oder mit einem Passwortmanager erzeugt, mindestens 24 Zeichen, sofern das Portal die Länge zulässt (Längenbegrenzung NICHT VERFÜGBAR, zu verifizieren am eigenen Mandanten).
- Niemand außer dem Hub und dem verwaltenden Administrator kennt die Freigabe-Passwörter. Sie werden nie in E-Mails, Tickets, Dokumenten oder Repositorys abgelegt.

### 3.2 Speicherung im Hub

- Tabelle immoware_technical_users: username, secret_encrypted, secret_rotated_at, purpose ('read' oder 'write'), breaker_state. Ein Datensatz je technischem Nutzer; das Geheimnis liegt hier, weil es für alle Freigaben des Nutzers gilt. Tabelle immoware_connections: base_url_encrypted, auth_scheme, purpose, technical_user_id. Ein Datensatz je Freigabe-URL; Rotation ändert genau eine Zeile in immoware_technical_users und wirkt auf alle Connections dieses Nutzers.
- Verschlüsselung mit dediziertem Schlüssel außerhalb der Anwendungs-ENV (KMS oder HashiCorp Vault). Anwendungscode erhält Klartext nur zur Laufzeit im HTTP-Client, kein Logging von Authorization-Headern, keine Klartextausgabe in Fehlermeldungen, Exceptions und DLQ-Payloads werden vor Persistierung von Credentials bereinigt.
- Der HTTP-Client sendet Credentials nur an den in base_url_encrypted hinterlegten Host über HTTPS. Redirects auf fremde Hosts werden nicht gefolgt. Kein Preemptive-Basic-Auth an unbekannte Hosts; das Schema wird aus der Probe (auth_scheme) übernommen. Solange auth_scheme = 'unknown', antwortet der Client auf die Challenge des Servers und persistiert das erkannte Schema nach Freigabe durch einen Administrator.
- TLS-Verifikation ist immer aktiv. Zertifikatspinning wird nicht eingesetzt, weil Immoware24 Zertifikate rotieren kann und kein Vorankündigungskanal existiert.

### 3.3 Probe (Phase 0) zu Authentifizierung

Bestandteil der Server-Probe aus der Architekturentscheidung, nur lesend, mit eigenen Zugangsdaten:

1. Unauthentifizierter OPTIONS und PROPFIND Depth 0 auf den Freigabe-Root: Erwartung 401 mit WWW-Authenticate-Header. Schema (Basic, Digest, sonstiges), realm und Server-Header werden protokolliert.
2. Authentifizierter PROPFIND Depth 0 mit dem erkannten Schema: Erwartung 207.
3. Test mit falschem Passwort: Erwartung 401, kein anderer Statuscode; Verhalten bei mehreren Fehlversuchen (Sperre, Verzögerung) wird beobachtet, ohne die Grenze bewusst auszureizen. Maximal drei Fehlversuche in der Probe.
4. Prüfung, ob Cookies oder Session-Tokens gesetzt werden und ob der Server ohne diese auf Folgeanfragen antwortet. Der Hub hält keinen Sitzungszustand vor, sofern die Probe das nicht erfordert.
5. Ergebnis in immoware_connections.auth_scheme, probe_result und server_fingerprint; Testprotokoll im Repository.

Was nicht getestet wird: kein Login an https://config.dav.immoware24.de durch den Hub, kein Auslesen von Freigabe-Links per Script, kein Test von Schreibmethoden außer dem If-None-Match-Test im Posteingang gemäß Architekturentscheidung, kein Test mit fremden Zugangsdaten.

### 3.4 Rotation und Vorfälle

| Ereignis | Vorgehen |
|---|---|
| Planmäßige Rotation (Default alle 180 Tage, konfigurierbar) | Administrator setzt im Konfigurationsportal "neues Passwort vergeben" für den technischen Nutzer. Weil das Passwort für alle Freigaben des Nutzers gilt (VERIFIZIERT), werden alle Connections dieses Nutzers im Hub in einem Schritt aktualisiert (Geheimnis liegt in immoware_technical_users.secret_encrypted, nicht je Connection). Ablauf: alle Connections des Nutzers auf paused, secret_encrypted am Nutzer ersetzen, Health-Check je Connection, Connections auf active, secret_rotated_at setzen, Auditeintrag. |
| Verdacht auf Kompromittierung | Sofortige Rotation wie oben, zusätzlich Prüfung der sync_runs und write_operations auf ungewöhnliche Muster, Meldung an Geschäftsführung, Prüfung der DSGVO-Meldepflichten mit Rechtsanwalt. |
| 401 im Health-Check oder Sync | CircuitBreaker öffnet für alle Connections dieses technischen Nutzers, Connections auf error, stale_since auf allen Datensätzen, Benachrichtigung des Betriebs (Leitdokument 07-sync-strategy.md Abschnitt 6). Kein automatischer Retry mit denselben Credentials über den Breaker hinaus, um Sperren zu vermeiden. Ausnahme: ein 401 im Health-Check während einer laufenden Rotation lässt die Connection paused (08-security.md Abschnitt 2.3). |
| Passwort-Reset in Immoware24 durch Dritte (z. B. Administratorwechsel) | wie 401. Prozess dokumentiert, dass ein Reset des Freigabe-Passworts alle Synchronisationen des Nutzers betrifft (VERIFIZIERT). |
| Wechsel des Immoware24-Benutzernamens oder der Freigabe | Freigabe-Link ändert sich (Freigaben sind nicht editierbar, nur löschen und neu anlegen, VERIFIZIERT). Neue Connection anlegen, alte auf paused, external_mappings bleiben erhalten, da sie an der Connection hängen; Migration der Mappings auf die neue Connection erfolgt als eigener, protokollierter Schritt mit Hash-Abgleich. |
| Kündigung eines Mitarbeiters mit Admin-Rechten | Rotation aller Freigabe-Passwörter, die dieser Mitarbeiter gekannt haben könnte, Prüfung der Hub-Nutzerkonten. |

### 3.5 Aktivierung des Schreibpfads

Aus der Architekturentscheidung, hier nur die Auth-relevanten Bedingungen:

- Connection mit purpose = 'write' startet mit write_enabled = 0 und status = paused.
- write_enabled = 1 nur, wenn write_enabled_by und write_confirmed_by gesetzt sind, zwei verschiedene Personen, davon eine mit Rolle release, und write_approval_document_id auf das protokollierte Freigabedokument der Geschäftsführung verweist. Anwendungsprüfung plus Datenbank-Trigger.
- Vorher erforderlich: schriftliche Bestätigung des Immoware24-Supports zur Zulässigkeit automatisierter Uploads, Buchung des DAV-Moduls, eigener technischer Schreibnutzer, erfolgreiche Probe inklusive If-None-Match, Testlauf mit Testdokumenten, AGB-Prüfung durch Rechtsanwalt.

## 4. Authentifizierung im Hub selbst

Kurz, weil Gegenstand der Kernarchitektur:

- Hub-Login mit Pflicht-2FA (TOTP, Wiederherstellungscodes einmalig nutzbar, gehasht). Passwörter Argon2id. Login ohne 2FA nur bis zur Einrichtung (totp_confirmed_at).
- Rollen: viewer, operator, admin, release. Rolle release ist ausschließlich zweite Person für write_enabled und für das Zurücksetzen von degraded auf active.
- API-Keys für spätere Fremdsysteme: Argon2id-Hash, Prefix, Scopes, IP-Allowlist, Ablaufdatum, letzte Nutzung, Widerruf. In Phase 1 werden keine Keys ausgegeben.
- Ausgehende Webhooks: HMAC-SHA256 über Body plus Zeitstempel, Replay-Fenster 5 Minuten. Erst mit benanntem Konsument aktiv.
- Auditlog append-only mit Hash-Kette; DB-Nutzer der Anwendung ohne UPDATE und DELETE auf audit_logs.

## 5. Ausgeschlossene Verfahren

| Verfahren | Grund |
|---|---|
| Automatisierter Web-Login (Headless Browser, Session-Cookies, Formular-Post) | Umgeht den vom Hersteller vorgesehenen Zugang, kollidiert mit 2FA, Scraping als Datenbankersatz ist ausgeschlossen. |
| Speichern von TOTP-Geheimnissen von Immoware24-Nutzern | Umgehung von 2FA. |
| Nutzung persönlicher Zugangsdaten von Mitarbeitern im Hub | Verstoß gegen AGB-Klausel zur Weitergabe von Zugangsdaten und gegen Nachvollziehbarkeit. |
| Login des Hubs am Konfigurationsportal config.dav.immoware24.de | Reine Web-UI, kein programmatischer Zugang vorgesehen. |
| Remote-Desktop- oder RPA-Automatisierung (z. B. legacy-use) | Zugangsdaten bei Dritten, compliance- und datenschutzrechtlich problematisch, kein API-Zugang. |
| Annahme von API-Keys, OAuth oder Bearer-Tokens | NICHT VERFÜGBAR, kein Beleg. Adapter-Slot in der Capability Registry bleibt für den Fall einer künftigen offiziellen API vorgesehen. |

## 6. Offene Punkte

| Nr. | Punkt | Kennzeichnung |
|---|---|---|
| A1 | HTTP-Auth-Schema des DAV-Endpunkts (Basic, Digest, sonstiges), realm, Verhalten bei Fehlversuchen | zu verifizieren am eigenen Mandanten (Probe Phase 0) |
| A2 | Kleinste Immoware24-Rolle, die DAV-Freigaben tragen darf; Rolle mit Schreibrecht nur auf Posteingang | zu verifizieren am eigenen Mandanten |
| A3 | Beschränkbarkeit einer Dateifreigabe auf den Ordner Posteingang | zu verifizieren am eigenen Mandanten |
| A4 | Passwortrichtlinie des Freigabe-Passworts (Länge, Zeichen), Verhalten von "Passwort generieren" | zu verifizieren am eigenen Mandanten |
| A5 | Welches Passwort der Benutzer "admin" am Konfigurationsportal verwendet, ob 2FA dort gilt | zu verifizieren am eigenen Mandanten |
| A6 | Zulässigkeit automatisierter DAV-Nutzung durch eine Serveranwendung, AGB-Wortlaut | WAITING_FOR_VENDOR_ACCESS, Prüfung durch Rechtsanwalt |
| A7 | Existenz einer nicht öffentlichen API mit eigenem Auth-Verfahren | WAITING_FOR_VENDOR_ACCESS |
| A8 | Ob Freigabe-Links nutzerindividuell sind und ob ein Pfadschema existiert (nur beobachten, nicht ableiten) | zu verifizieren am eigenen Mandanten |
| A9 | Sitzungs- oder Cookie-Verhalten des DAV-Servers | zu verifizieren am eigenen Mandanten |
