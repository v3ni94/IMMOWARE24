# Immoware Hub

Integrationsschicht der Hausverwaltung Müller GmbH um den Immoware24-Mandanten. Laravel 12, PHP 8.4, MariaDB 10.11+, Redis 7, Horizon, Scheduler.

Stand: 11.09.2026. Projektphase: Konzeption abgeschlossen, Phase 0 (Voraussetzungen und Probe) nicht begonnen. Es existiert noch kein Anwendungscode.

## Zweck

Immoware24 bleibt führendes System. Der Hub hält einen versionierten, nachvollziehbaren Spiegel der Stammdaten, Kontakte, Dokumente und Buchhaltungsdaten und stellt genau einen eng begrenzten Schreibpfad bereit: neue Dateien per WebDAV in den DMS-Posteingang legen. Alle anderen Schreibrichtungen laufen über einen manuellen Rückweg (proposed_change).

## Oberste Regel

Nichts erfinden. Jede Aussage zu Immoware24-Schnittstellen trägt einen Belegstatus:

| Status | Bedeutung |
|---|---|
| VERIFIZIERT | Offizielle Immoware24-Quelle (immoware24.de, support.immoware24.de, content.immoware24.de, config.dav.immoware24.de), Wortlaut in einem WebSearch-Snippet oder per Fetch tatsächlich gesehen. Ein nahezu wörtlich gesehener Kernsatz zählt, eine sinngemäße Zusammenfassung nicht. |
| DOKUMENTIERT | Quelle mit URL, die die Aussage trägt: offizielle Quelle, deren Wortlaut nur einmal gesehen, in der Gegenprüfung nicht reproduziert oder nur als Snippet-Paraphrase vorliegt, oder glaubwürdige Drittquelle. |
| VERMUTET | Plausibel, aber nicht durch gesichtete Quelle gedeckt, oder Interpretation über den Quellentext hinaus. Vor Nutzung am eigenen Mandanten zu verifizieren. |
| NICHT VERFÜGBAR | Negativbefund: kein Beleg gefunden. Keine offizielle Aussage, dass die Funktion fehlt. Gilt auch für alle Aussagen der Form "ohne API", "nicht dokumentiert", "keine Angabe". |

Diese Definition ist für alle Dokumente des Repositories verbindlich (Leitdefinition in README.md). Abweichende Kurzfassungen in Einzeldokumenten sind durch diese Tabelle ersetzt.

Nur eigener autorisierter Zugang. Keine Umgehung von Authentifizierung oder 2FA. Kein Scraping als Datenbankersatz.

## Belegstand der Zugangswege (Kurzfassung)

| Zugangsweg | Status | Nutzung im Hub |
|---|---|---|
| WebDAV auf DMS (Posteingang, Dokumente) | VERIFIZIERT: Existenz, Netzlaufwerk, Scanner-Upload, Änderungen und Löschungen wirken im Livesystem | Lesen; einziger Schreibkanal, create-only in den Posteingang |
| CardDAV Kontaktfreigabe | VERIFIZIERT für Existenz, Schreibrichtung unklar | Lesen |
| CalDAV Kalenderfreigabe | wie CardDAV | Lesen, niedrige Priorität |
| Konfigurationsportal config.dav.immoware24.de | VERIFIZIERT (Web-UI, Freigaben durch admin, eigenes Freigabe-Passwort) | Manuelle Einrichtung, kein programmatischer Zugriff |
| DAV-Modul | VERIFIZIERT als buchbares Zusatzmodul über Support oder Vertrieb (Wortlaut "buchen", Artikel 360010876038); Kostenpflicht ist Ableitung (VERMUTET), Kosten WAITING_FOR_VENDOR_ACCESS | Blocker vor Phase 0 |
| CSV-Export der Auswertungen | DOKUMENTIERT (Export-Button, manuell), Spaltenformat NICHT VERFÜGBAR | Datei-Import |
| DATEV-CSV-Buchungsexport | DOKUMENTIERT (manuell) | Datei-Import, Phase 3 |
| CAMT.053, MT940, SEPA pain | DOKUMENTIERT (UI und Banking-Client) | CAMT.053 nur lesend |
| REST-API, Webhooks, API-Keys, Zapier/Make/n8n | NICHT VERFÜGBAR (Negativbefund) | Kein Baustein darf darauf bauen |
| Rate Limits, Quotas, SLA | NICHT VERFÜGBAR | Feste konservative Limits |

Vollständige Herleitung mit Quellen in `docs/immoware/` und in der Architekturentscheidung.

## Verzeichnisstruktur

```
.
├── README.md                      Projektübersicht (diese Datei)
├── .env.example                   Platzhalter, keine echten Werte
├── .gitignore
└── docs/
    ├── implementation-plan.md     Phasen 0 bis 14 mit Definition of Done
    ├── adr/
    │   ├── 0001-modularer-monolith.md
    │   ├── 0002-immoware-master.md
    │   └── 0003-dav-first.md
    ├── n8n/
    │   ├── README.md              Anbindung von n8n an den Hub (Hub-Webhooks, nicht Immoware24)
    │   └── beispiele.md           Webhook-Payload-Beispiele
    └── immoware/                  Schnittstellenrecherche (01 bis 10)
```

Geplante Anwendungsstruktur (ab Phase 1, siehe ADR 0001):

```
app/Modules/
  Core         Auth (2FA TOTP), Rollen, API-Keys, Audit (append-only, Hash-Kette)
  Connectors   ImmowareConnectorInterface, WebDav-, CardDav-, CalDav-, Csv-, Datev-, BankFile-Connector
  Capability   CapabilityRegistry (Belegstatus, Test, Hard Lock)
  Probe        ServerProbe (Strategie je Connection, Server-Fingerprint)
  Sync         SyncOrchestrator, ChangeDetector, Mapper, Reconciler, Bootstrap
  Domain       properties, units, contacts, contracts, documents, open_items, transactions
  Writes       WriteOperationService (Idempotenz, Precheck, Verify)
  Conflicts    ConflictQueue inkl. proposed_change
  Resilience   feste Limits, CircuitBreaker, DLQ, Degraded-Mode
  Outbound     HMAC-Webhooks (erst mit benanntem Konsumenten aktiv)
```

## Leitprinzipien

1. Immoware24 ist Master, der Hub ist Spiegel.
2. Rohdaten vor Interpretation: jede Nutzlast wird mit SHA-256 archiviert, Mapping ist reproduzierbar.
3. Hash statt Zeitstempel: kein Zugangsweg liefert ein verlässliches updated_at.
4. Schreiben ist die Ausnahme: nur PUT mit If-None-Match: * in den Posteingang. Kein DELETE, MOVE, COPY, PROPPATCH, LOCK, kein Overwrite.
5. Read-only ist Standard: jede Connection startet mit write_enabled = false, Aktivierung im Vier-Augen-Prinzip mit Freigabe der Geschäftsführung.
6. Fähigkeiten werden gemessen (Probe), nicht angenommen.
7. Datenalter ist sichtbar (data_age_seconds, stale_since, source_status).

## Offene Punkte vor Baubeginn (Phase 0)

- Buchung des DAV-Moduls und Kostenfreigabe: WAITING_FOR_VENDOR_ACCESS
- Schriftliche Bestätigung des Immoware24-Supports zur Zulässigkeit automatisierter WebDAV-Nutzung: WAITING_FOR_VENDOR_ACCESS
- Auth-Schema, ETag-Stabilität, sync-token, CTag, If-None-Match-Verhalten des DAV-Servers: zu verifizieren am eigenen Mandanten
- Ordnerumfang per WebDAV und Beschränkbarkeit einer Freigabe auf den Posteingang: zu verifizieren am eigenen Mandanten
- Nutzerrolle, die DAV-Freigaben tragen darf: zu verifizieren am eigenen Mandanten
- Spaltenformate aller CSV-Exporte und der DATEV-Datei: zu verifizieren am eigenen Mandanten
- AGB-Wortlaut zu automatisiertem Zugriff und Exportregelung bei Vertragsende: Prüfung durch Rechtsanwalt

## Hinweise zur Recherche

Der Host www.immoware24.de sowie support.immoware24.de und content.immoware24.de waren aus der Rechercheumgebung gesperrt (EGRESS_BLOCKED). Belege beruhen auf WebSearch-Snippets offizieller Quellen und auf Drittquellen. Snippet-Paraphrasen sind in `docs/immoware/` als solche gekennzeichnet und in Phase 0 gegen das Original abzugleichen.

## Lizenz und Vertraulichkeit

Internes Projekt der Hausverwaltung Müller GmbH. Keine Zugangsdaten, Steuernummern oder Bankverbindungen im Repository.

## Betriebsdomain

Der Hub wird unter `https://immoware.muellerhv.de` betrieben (Vorgabe der Geschäftsführung vom 11.09.2026). Admin-Oberfläche, API (`/api/v1`), API-Dokumentation (`/api/docs`) und Health-Endpunkte laufen unter dieser Domain. DNS, TLS-Zertifikat und Reverse Proxy sind Bestandteil von Phase 1.
