# 07 Deploy-Verifikation, Nginx-Fix und neue Module (Stand 21.09.2026)

Grundlage: `docs/operations/01-deployment.md`, `docs/operations/03-monitoring.md`, `docs/operations/05-go-live-checkliste.md`, `deploy/scripts/deploy.sh`. Diese Liste prüft zwei Dinge auf dem Server `immoware.muellerhv.de`, nicht mehr: erstens den zuvor behobenen 502-Fehler nach Neustart des `app`-Containers, zweitens die dreiteilige Erweiterung aus den Commits `2712bb1`, `a72c162`, `479d381` (Ai-Anbieterwahl, Modul Learning, Modul Playbooks). Jeder Punkt wird mit Datum, Befehl und Ergebnis abgehakt.

## A. Basisbetrieb nach dem Nginx-Fix

| Nr. | Prüfung | Befehl | Erwartung | Status |
|---|---|---|---|---|
| A1 | web-Container startet nach Neustart von app ohne 502 | `docker compose ps`, danach `docker compose restart app` und `curl -I https://immoware.muellerhv.de/up` | HTTP 200 direkt nach dem Neustart, kein 502 | offen |
| A2 | DNS-Auflösung des Upstreams funktioniert zur Laufzeit | `docker compose exec web nginx -t` | Konfiguration ohne Fehler, `resolver 127.0.0.11` aktiv | offen |
| A3 | `web` hängt korrekt an `app` (compose.yaml `depends_on.app.restart: true`) | `docker compose config` | Abhängigkeit sichtbar | offen |
| A4 | Allgemeiner Systemstatus | `php artisan hub:doctor --no-interaction` (im `app`-Container) | Keine Fehler, alle Schreibflags false sofern nicht bewusst freigegeben | offen |
| A5 | Health-Endpunkte | `curl -sS https://immoware.muellerhv.de/health/database`, `.../health/queue`, `.../health` | Je HTTP 200, `status: ok` bzw. informativ bei `degraded` bei Immoware24-Anbindung | offen |
| A6 | Worker- und Scheduler-Herzschlag | `php artisan hub:heartbeat:check worker --max-age=180`, `... scheduler --max-age=180` | Exit-Code 0 | offen |
| A7 | `hub:user:create` mit `--organization-name` bei Erstinstallation | Nur bei einer echten Neuinstallation ohne bestehende Organisation nötig, sonst überspringen | Erste Organisation und Benutzer angelegt, Auditlog-Eintrag vorhanden | entfällt bei bestehendem System |

## B. Modul Ai, Anthropic-Adapter und Anbieterwahl

Betrifft `config/hub/ai.php`, `App\Modules\Ai\Services\SelectingAiProvider`. Beide Flags stehen im Repository standardmäßig auf aus; vor dem Test auf dem Server prüfen, ob sie dort absichtlich gesetzt wurden.

| Nr. | Prüfung | Befehl | Erwartung | Status |
|---|---|---|---|---|
| B1 | Aktueller Konfigurationsstand | `php artisan config:show hub.ai` (oder `.env`-Auszug ohne Secrets) | `provider_priority`, ggf. `MAIL_AI_ANTHROPIC_*` gesetzt wie beabsichtigt | offen |
| B2 | Kein Secret im Log oder in `config:cache`-Ausgabe | Sichtprüfung der Befehlsausgabe aus B1 | Keine API-Keys sichtbar | offen |
| B3 | Provider tatsächlich erreichbar, falls ein Task ausgelöst wird | Kontrollierter Testlauf einer KI-gestützten Mail-Funktion mit `hub.mail.flags.ai=true` in einer Nicht-Produktionsumgebung, Beobachtung in `mail_ai_runs` | Lauf mit Status `succeeded`, Anbieter wie in `provider_priority` erwartet, bei OpenAI-Ausfall Fallback auf Anthropic sichtbar | offen |
| B4 | Kostenschätzung plausibel | Eintrag in `mail_ai_runs.cost_cents` prüfen | Wert im erwarteten Rahmen, kein `null` bei konfiguriertem Anbieter | offen |

## C. Modul Learning, Lernphase Immoware24

Betrifft `config/hub/learning.php` (`HUB_LEARNING_ENABLED`, Standard aus), Tabelle `learning_runs`, Route `admin.learning.*`, Berechtigung `learning.manage`.

| Nr. | Prüfung | Befehl | Erwartung | Status |
|---|---|---|---|---|
| C1 | Migration eingespielt | `php artisan migrate:status \| grep learning` | Migration `learning_create_tables` als `Ran` | offen |
| C2 | Admin-Seite erreichbar | Browser, angemeldet mit Rolle administrator oder developer: `https://immoware.muellerhv.de/admin/learning` | Seite lädt, Navigationspunkt Lernphase vorhanden | offen |
| C3 | Ein Lauf gegen eine echte, lesende Connection (WebDAV Dokumente, CardDAV oder CalDAV) | `php artisan hub:immoware:learn <connection>` mit einer bestehenden Lesenutzer-Connection | Lauf endet mit Status `succeeded`, Zusammenfassung zeigt gefundene Ordner beziehungsweise Felder, keine Schreibzugriffe gegen Immoware24 | offen |
| C4 | Wiederholbarkeit und Diff | Zweiten Lauf direkt danach starten | Zweiter Lauf zeigt keine oder nur echte Unterschiede zum ersten (`showsDifferences()`), keine Endlosschleife, keine Ratenlimit-Fehler | offen |
| C5 | KI-Vorschläge bleiben Entwurf | Falls `HUB_LEARNING_AI_ENABLED=true` gesetzt wurde: Vorschlag in der Admin-Ansicht prüfen | Vorschlag erscheint als Entscheidung ausstehend, wird nie automatisch übernommen | offen |
| C6 | Kein Schreibzugriff ausgelöst | `write_operations` vor und nach dem Lauf vergleichen | Keine neuen Einträge durch den Lernlauf | offen |

## D. Modul Playbooks, Prozessdatenbank

Betrifft `config/hub/playbooks.php` (`HUB_PLAYBOOKS_ENABLED`, Standard aus), Tabellen `mail_playbooks`, `mail_playbook_matches`, Route `mail.admin.playbooks.*`, Tab „Prozessdatenbank" im MailUi-Admin.

| Nr. | Prüfung | Befehl | Erwartung | Status |
|---|---|---|---|---|
| D1 | Migration eingespielt | `php artisan migrate:status \| grep playbooks` | Migration `playbooks_create_tables` als `Ran` | offen |
| D2 | Admin-Seite erreichbar | Browser, angemeldet mit Mail-Admin-Rolle: MailUi-Administration, Tab Prozessdatenbank | Seite lädt, Liste (anfangs leer) sichtbar | offen |
| D3 | Lernen aus abgeschlossenen Fällen | `php artisan hub:playbooks:relearn --organization=<id>` mit vorhandenen abgeschlossenen Vorgängen einer Kategorie | Entwurf eines Playbooks wird angelegt, keine Dubletten bei wiederholtem Lauf zum gleichen Muster | offen |
| D4 | Abgleich bei neuem Vorgang | Neuen Vorgang der gleichen Kategorie anlegen oder abwarten (Event `CaseOpened`) | Eintrag in `mail_playbook_matches` mit Status vorgeschlagen, sichtbar unter „Offene Abgleiche" | offen |
| D5 | Entscheidung wirkt auf den Zähler | In „Offene Abgleiche" einen Treffer annehmen | `times_accepted` am Playbook erhöht sich, Entscheidung im Auditlog nachvollziehbar | offen |
| D6 | Aktivieren und Stilllegen einer Version | Entwurf aktivieren, danach die vorherige aktive Version prüfen | Vorherige Version automatisch auf `retired`, keine zwei gleichzeitig `active` Playbooks je `case_type` | offen |
| D7 | Bekannte Lücke bestätigen | Fallansicht (Case-Detail) öffnen | Playbook-Vorschlag erscheint dort bewusst noch nicht, nur auf der eigenen Abgleichsseite (dokumentierte Einschränkung) | zur Kenntnis genommen |

## E. Abschluss

- Alle Punkte aus A bis D mit Datum und Ergebnis eintragen, bei Abweichung Runbook (`04-runbook.md`) prüfen statt der Reihe nach neu zu raten.
- Block B, C, D erst nach Block A vollständig grün angehen.
- Vor der ersten produktiven Aktivierung von `HUB_LEARNING_AI_ENABLED` oder `HUB_PLAYBOOKS_AI_ENABLED`: unabhängiger Code-Review der drei Commits, da ohne zweite Gegenprüfung entwickelt (siehe Commit-Historie 21.09.2026).
