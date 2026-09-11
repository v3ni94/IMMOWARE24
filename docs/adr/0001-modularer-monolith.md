# ADR 0001: Modularer Monolith statt Microservices

Status: Angenommen
Datum: 11.09.2026
Entscheider: Projektleitung Immoware Hub, Hausverwaltung Müller GmbH

## Kontext

Der Immoware Hub spiegelt Daten eines Immoware24-Mandanten mit 67 Objekten, 869 Verwaltungseinheiten und rund 4.600 Kontakten (Stichtag 01.07.2026). Die Zugangswege sind ausschließlich WebDAV, CardDAV, CalDAV (VERIFIZIERT bzw. DOKUMENTIERT) und manuelle Datei-Exporte (DOKUMENTIERT). Eine REST-API oder Webhooks von Immoware24 sind NICHT VERFÜGBAR. Es gibt genau einen Schreibpfad (WebDAV create-only in den Posteingang). Das Team ist klein, der Betrieb soll mit einem Deployment auskommen.

Erwogene Optionen:

1. Microservices je Connector (WebDAV-Service, CardDAV-Service, Import-Service, Write-Service) mit Message-Bus.
2. Modularer Laravel-Monolith mit klar getrennten Modulen unter `app/Modules/`, einem Deployment, Horizon-Queues und Redis.
3. Einfacher Monolith ohne Modulgrenzen.

## Entscheidung

Option 2: modularer Monolith. Ein Laravel-12-Deployment mit den Modulen Core, Connectors, Capability, Probe, Sync, Domain, Writes, Conflicts, Resilience, Outbound. Modulgrenzen werden durch Namespaces, Interfaces (z. B. `ImmowareConnectorInterface`) und Architekturtests (z. B. Verbot direkter Datenbankzugriffe im Mapper) durchgesetzt.

## Begründung

- Die Lastannahmen sind gering: 2 Requests pro Sekunde gegen den DAV-Server, Posteingang-Scan unter einer Minute, Kontakt-Vollabgleich unter zwei Minuten. Verteilte Systeme lösen hier kein Problem, das existiert.
- Der Schreibpfad verlangt strikte Idempotenz und ein einziges Wahrheitsprotokoll (write_operations, audit_logs mit Hash-Kette). Eine einzelne Datenbank mit Transaktionen ist dafür einfacher korrekt zu halten als verteilte Konsistenz.
- Der HTTP-Methoden-Guard (Verbot von DELETE, MOVE, COPY, PROPPATCH, LOCK, UNLOCK und PUT ohne If-None-Match: *) muss an genau einer Stelle liegen und für jeden Aufruf gelten. In einem Monolithen ist das ein Client-Decorator, in Microservices eine Vervielfachung.
- Der Betrieb durch die Hausverwaltung Müller GmbH ist mit einem Deployment, einem Datenbankbackup und einem Queue-Worker-Set realistisch.
- Modulgrenzen halten den Weg zu einer späteren Extraktion (z. B. lesende API in Phase 4) offen, ohne sie heute zu bezahlen.

## Konsequenzen

Positiv:
- Ein Deployment, ein Backup-Konzept, ein Auditlog.
- Mapping als reine Funktion ist im Monolithen einfach testbar und aus `external_payloads` per `hub:replay` reproduzierbar.
- Redis-Locks verhindern parallele Läufe derselben Quelle ohne verteilte Koordination.

Negativ:
- Ein fehlerhaftes Modul kann den Prozess belasten; Gegenmaßnahme sind getrennte Horizon-Queues je Connector-Typ und ein CircuitBreaker je Connection.
- Skalierung erfolgt vertikal oder über mehrere Worker desselben Codes, nicht je Modul. Für 50.000 Einheiten und 250.000 Kontakte (Kapazitätsziel des Datenmodells) ist das ausreichend.

## Regeln, die aus dieser Entscheidung folgen

1. Kein Modul greift auf Tabellen eines anderen Moduls ohne dessen Service-Klasse zu.
2. Der Mapper hat keinen Datenbankzugriff.
3. Der WebDAV-Client ist ausschließlich über den Methoden-Guard erreichbar.
4. Neue Adapter werden über die Capability Registry registriert, nie hart kodiert.
