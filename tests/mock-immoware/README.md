# Mock-Immoware24-DAV-Server

Eigenständiger PHP-Built-in-Server, der den Immoware24-DAV-Adapter für Tests simuliert. Simulation auf Basis belegter Aussagen, kein Nachbau nicht dokumentierter Immoware24-Interna. Der Mock ist keine Aussage über das tatsächliche Verhalten des Immoware24-Servers; alle Annahmen sind im Code (`server.php`) als solche markiert und in Phase 0 gegen die Probe am eigenen Mandanten abzugleichen.

## Start

```
php -S 127.0.0.1:8089 tests/mock-immoware/server.php
php artisan hub:mock-immoware:serve --port=8089        Komfort-Wrapper, nur local und testing
```

Zugangsdaten (Basic Auth) über `MOCK_DAV_USER` und `MOCK_DAV_PASS`, Standard `hub-read` und `mock-secret`. Weitere Variablen: `MOCK_RUNTIME_DIR` (Uploads, Protokoll, Zähler), `MOCK_TIMEOUT_SLEEP` (Standard 35 s), `MOCK_SLOW_SLEEP` (Standard 2 s).

## Endpunkte

| Pfad | Protokoll | Methoden |
|---|---|---|
| `/dav/files/` mit `Posteingang/` und `Dokumente/` | WebDAV | OPTIONS, PROPFIND Depth 0 und 1 (getetag, getlastmodified, getcontentlength, getcontenttype, resourcetype, displayname), GET, HEAD, PUT nur mit `If-None-Match: *` (201 neu, 412 vorhanden, 409 Zielordner fehlt) |
| `/dav/addressbooks/kontakte/` | CardDAV | PROPFIND (getctag, supported-report-set), REPORT addressbook-query und addressbook-multiget, GET |
| `/dav/calendars/termine/` | CalDAV | PROPFIND (getctag, supported-report-set), REPORT calendar-query und calendar-multiget, GET |
| `/__mock/health`, `/__mock/log`, `/__mock/reset` | Steuerung | GET, ohne Auth |

DELETE, MOVE, COPY, PROPPATCH, MKCOL, LOCK, UNLOCK, POST und PATCH antworten 403 und werden im Protokoll als `violation: forbidden_method` markiert. PUT ohne `If-None-Match: *` antwortet 412 mit `violation: put_without_if_none_match`. REPORT sync-collection antwortet 403 (am Mandanten NICHT VERFÜGBAR).

## Szenarien

Auswahl über Header `X-Mock-Scenario`, Query `?scenario=` oder Pfadpräfix `/s/<szenario>/dav/...` (das Präfix ist für Connections gedacht, deren Basis-URL fest ist).

| Szenario | Verhalten |
|---|---|
| `ok` | Normalbetrieb |
| `unauthorized` | 401 mit `WWW-Authenticate: Basic` |
| `forbidden` | 403 |
| `notfound` | 404 |
| `conflict` | 409 |
| `ratelimited` | 429 mit `Retry-After: 10` |
| `servererror` | 500 |
| `timeout` | Antwort erst nach `MOCK_TIMEOUT_SLEEP` Sekunden |
| `invalidxml` | 207 mit unvollständigem XML |
| `slow` | Antwort nach `MOCK_SLOW_SLEEP` Sekunden |
| `etagunstable` | ETag wechselt bei jedem Request |

## Protokoll

`GET /__mock/log` liefert alle Requests seit dem letzten Reset als JSON (Methode, Pfad, Szenario, Depth, If-None-Match, Auth-Status, Verstoß, Antwortstatus). `GET /__mock/reset` löscht Protokoll, Uploads und ETag-Zähler.

## Tests

- `tests/Contract/MockServerContractTest.php` startet den Mock per Process und prüft die DAV-Struktur, Snapshot `tests/Contract/snapshots/mock.json`.
- `tests/Contract/MockScenarioTest.php` fährt die Szenarien gegen den echten WebDavConnector und CardDavConnector.
- `tests/Contract/DavServerContractTest.php` läuft gegen `CONTRACT_DAV_BASE_URL` (Mock oder Mandant), sonst Skip.
- `App\Modules\Connector\Testing\MockDavResponses` liefert dieselben Antworten als `Http::fake`-Bausteine für Unit- und Feature-Tests.
