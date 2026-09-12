# 04 Status, Priorität und SLA

Stand: 12.09.2026. Leitregeln des Auftrags: Gelesen ist nicht bearbeitet. Beantwortet ist nicht erledigt. Ein erfolgreicher HTTP-Aufruf ist kein verifiziertes Geschäftsergebnis. Jeder offene Vorgang hat Verantwortlichen, nächsten Schritt und Fälligkeit. Technische Fehler erscheinen nie als Erfolg.

## 1. Drei Statusdimensionen

Jeder Vorgang (`mail_cases`) und jedes Teilanliegen (`mail_case_items`) führt drei voneinander unabhängige Statuswerte. Kein Wert leitet sich automatisch aus einem anderen ab.

### 1.1 Bearbeitung (`status_processing`)

| Wert | Bedeutung |
|---|---|
| `new` | Angelegt, noch niemandem zugewiesen |
| `triage` | In Prüfung durch Team oder Dispatcher, Zuordnung offen |
| `assigned` | Verantwortlicher, nächster Schritt und Fälligkeit gesetzt |
| `in_progress` | Wird bearbeitet |
| `waiting_external` | Wartet auf Externe (Mieter, Handwerker, Behörde); Uhr `resolve` pausiert nur, wenn die SLA-Regel das erlaubt |
| `waiting_internal` | Wartet auf interne Stelle (Freigabe, Rückfrage) |
| `resolved` | Fachlich erledigt, Abschlussbedingungen (Abschnitt 2) erfüllt, noch nicht geschlossen |
| `closed` | Geschlossen, nur lesend, Wiedereröffnung möglich |
| `cancelled` | Gegenstandslos (Spam, Duplikat), Begründung Pflicht |

Erlaubte Übergänge:

```
new -> triage | assigned | cancelled
triage -> assigned | cancelled
assigned -> in_progress | waiting_external | waiting_internal | cancelled
in_progress -> waiting_external | waiting_internal | resolved | cancelled
waiting_external -> in_progress | resolved
waiting_internal -> in_progress | resolved
resolved -> closed | in_progress (Wiederaufnahme)
closed -> in_progress (Wiedereröffnung, Begründung Pflicht, Audit)
cancelled -> triage (Wiederaufnahme, Begründung Pflicht)
```

Regel "gelesen ist nicht bearbeitet": Das Öffnen einer Nachricht oder eines Vorgangs ändert `status_processing` nicht. Nur eine ausdrückliche Aktion (Zuweisen, Schritt setzen, Status ändern) verändert den Wert. `mail_messages.is_read_in_gmail` ist rein informativ.

Pflichtfelder ab `assigned` bis einschließlich `waiting_*`: `assignee_user_id`, `next_step`, `due_at`. Die Anwendung verweigert den Übergang, wenn eines fehlt.

### 1.2 Kommunikation (`status_communication`)

| Wert | Bedeutung |
|---|---|
| `unanswered` | Keine ausgehende Nachricht zum Vorgang |
| `acknowledged` | Eingangsbestätigung nachweislich versandt (Versandabgleich `sent_verified`) |
| `answered` | Inhaltliche Antwort nachweislich versandt |
| `awaiting_reply` | Wir warten auf Antwort des Absenders |
| `reply_received` | Neue eingehende Nachricht seit unserer letzten Antwort |
| `no_response_needed` | Keine Antwort erforderlich (intern, Info), Begründung Pflicht |

Übergänge: `unanswered -> acknowledged | answered | no_response_needed`; `acknowledged -> answered | awaiting_reply | reply_received`; `answered -> awaiting_reply | reply_received`; `awaiting_reply -> reply_received`; `reply_received -> answered | awaiting_reply`. Ein Übergang auf `acknowledged` oder `answered` setzt voraus, dass eine `mail_drafts`-Zeile im Status `sent_verified` mit `case_id` existiert. Ein nur angeforderter Versand (`sent_requested`) verändert die Kommunikationsdimension nicht.

Regel "beantwortet ist nicht erledigt": `answered` hat keinen Einfluss auf `status_processing` oder `status_business`. Ein Vorgang kann `answered` und zugleich `in_progress` sein.

### 1.3 Geschäftsergebnis (`status_business`)

| Wert | Bedeutung |
|---|---|
| `open` | Kein fachliches Ergebnis |
| `action_planned` | Action Plan erstellt, nicht freigegeben |
| `action_approved` | Freigegeben, nicht ausgeführt |
| `action_executed_unverified` | Ausführung technisch erfolgreich (HTTP 2xx), Ergebnis nicht nachgelesen |
| `verified` | Ergebnis im Zielsystem nachgelesen oder manuell bestätigt (`done_manual_confirmed` bei Immoware-Stammdaten) |
| `failed` | Ausführung gescheitert, Fehler sichtbar |
| `not_applicable` | Kein Geschäftsergebnis nötig (reine Auskunft) |

Übergänge: `open -> action_planned | not_applicable`; `action_planned -> action_approved | open`; `action_approved -> action_executed_unverified | failed`; `action_executed_unverified -> verified | failed`; `failed -> action_planned`. Ein Übergang auf `verified` verlangt eine `mail_verifications`-Zeile mit `result = verified` oder eine `mail_tasks`-Zeile im Status `done_manual_confirmed` beziehungsweise `done_verified`.

Regel "technische Fehler erscheinen nie als Erfolg": Jede Exception, jeder Nicht-2xx, jedes Timeout setzt `failed` mit Fehlerklasse; ein Retry setzt zurück auf `action_approved`. Kein Code-Pfad darf bei Exception `verified` schreiben.

## 2. Abschlussbedingungen je Vorgangstyp

`status_processing = resolved` ist nur erlaubt, wenn alle Bedingungen erfüllt sind. Teilanliegen müssen alle `resolved`, `closed` oder `cancelled` sein.

| Vorgangstyp (`case_type`) | Bedingungen für `resolved` |
|---|---|
| `schaden_notfall` (P0) | `acknowledged_at` gesetzt; Kommunikation mindestens `acknowledged`; Aufgabe Notdienst/Handwerker mit Status `done_manual_confirmed` oder `done_verified`; Geschäftsergebnis `verified` oder `not_applicable` mit Begründung |
| `schaden` | Kommunikation mindestens `answered`; Aufgabe (Handwerker, Besichtigung) abgeschlossen; Dokumentreferenz (Angebot, Auftrag) oder Begründung |
| `adressaenderung` | Manuelle Aufgabe Immoware24 mit Alt/Neu im Status `done_manual_confirmed` oder `done_verified`; falls Lexware relevant: Aufgabe Lexware abgeschlossen oder Ausführung `verified`; Kommunikation `answered` |
| `bankdaten` | Wie Adressänderung, zusätzlich Vier-Augen-Bestätigung der Aufgabe (`confirmed_by` ungleich `assignee_user_id`); Alt/Neu verschlüsselt gespeichert |
| `beschwerde` | Kommunikation `answered`; Verantwortlicher hat Ergebnisnotiz erfasst; bei Eskalation Kenntnisnahme Teamleitung |
| `anfrage_allgemein` | Kommunikation `answered` oder `no_response_needed` mit Begründung; Geschäftsergebnis `not_applicable` oder `verified` |
| `rechnung` | Dokumentreferenz vorhanden; Weitergabe (Posteingang Immoware oder manuelle Aufgabe) `verified` oder `done_manual_confirmed`; Kommunikation nach Bedarf |
| `kuendigung` | Eingangsbestätigung `acknowledged`; Frist in `mail_deadlines` mit `verified_by` gesetzt; Hinweis auf Freigabe durch Geschäftsführung dokumentiert; manuelle Aufgabe Immoware24 abgeschlossen |
| `sonstiges` | Kommunikation entschieden (`answered` oder `no_response_needed`); Geschäftsergebnis entschieden |

`closed` zusätzlich: alle Uhren gestoppt, alle Aufgaben abgeschlossen oder storniert, alle Entwürfe `sent_verified` oder `discarded`.

## 3. Prioritätsmodell P0 bis P3

| Priorität | Definition | Regeln zur automatischen Vorbelegung (regelbasiert, KI nur als Vorschlag) |
|---|---|---|
| P0 Notfall | Gefahr für Personen oder Gebäude: Wasserrohrbruch, Gasgeruch, Brand, Stromausfall ganzes Haus, Heizungsausfall bei Frost, Aufzug mit Personen, Einbruch aktuell | Schlüsselwortliste (konfigurierbar) im Betreff oder Text UND eingehende Nachricht; zusätzlich manuelle Setzung durch jeden Berechtigten |
| P1 Dringend | Erheblicher Schaden ohne akute Gefahr, Heizungsausfall außerhalb Frost, Wasserschaden begrenzt, Fristsachen mit Ablauf unter 3 Arbeitstagen, gerichtliche oder anwaltliche Schreiben | Schlüsselwörter, Absenderklasse (Gericht, Kanzlei), erkannte Frist |
| P2 Normal | Reguläre Anliegen: Reparatur, Adressänderung, Bankdaten, Abrechnungsfragen, Beschwerde | Standardwert für alle nicht zugeordneten Fälle |
| P3 Niedrig | Information, Newsletter mit Bezug, Rückfragen ohne Frist, interne Ablage | Absender auf Liste "informativ", Betreff-Muster |

Regeln:

- Priorität kann jederzeit manuell erhöht werden; Herabstufung von P0 oder P1 verlangt Begründung und wird auditiert.
- Eine KI-Einstufung (`ai_classification_json`) wird nur übernommen, wenn sie die Priorität erhöht oder ein Mensch sie bestätigt. Eine KI-Herabstufung wird nie automatisch übernommen.
- Bei P0 wird zusätzlich zur Vorgangsanlage sofort ein Job auf `mail-high` gestellt (Benachrichtigung Team, Teamleitung, Eskalationskontakt).
- Priorität gilt je Vorgang; Teilanliegen erben sie, können aber abweichen (nur nach oben).

## 4. Vier Uhren

| Uhr (`clock_type`) | Start | Stopp | Zeitbasis |
|---|---|---|---|
| `acknowledge` (Annahme) | `opened_at` | `acknowledged_at` (P0: Bestätigung durch Menschen im Hub; P1 bis P3: Zuweisung `assigned`) | P0 Kalenderzeit, sonst Arbeitszeit |
| `first_response` (Erstantwort) | `opened_at` | erste `mail_drafts` mit `sent_verified` zum Vorgang | Arbeitszeit (P0 Kalenderzeit) |
| `resolve` (Lösung) | `opened_at` | `status_processing = resolved` | Arbeitszeit; Pause bei `waiting_external`, wenn `sla_rules.uses_calendar` und Teamregel Pause erlauben (Standard: keine Pause bei P0/P1) |
| `task_due` (Aufgabenfälligkeit) | Anlage der Aufgabe | Aufgabe `done_*` oder `cancelled` | `mail_tasks.due_at`, Arbeitszeit |

Eine Uhr wird nie durch einen technischen Erfolg gestoppt, nur durch verifizierte Ereignisse (`sent_verified`, Statusübergang mit erfüllten Bedingungen).

## 5. Zwei Ampeln

| Ampel | Berechnung | Anzeige |
|---|---|---|
| SLA-Ampel je Uhr (`mail_sla_clocks.color`) | grün unter `warn_percent` (50 %) der Zielzeit, gelb ab 50 %, rot ab 100 % oder `breached` | Liste, Detail, Dashboard je Uhr |
| Vorgangs-Ampel (Gesamt) | schlechteste Farbe aller laufenden Uhren des Vorgangs und seiner offenen Aufgaben; zusätzlich rot, wenn Pflichtfelder (Verantwortlicher, nächster Schritt, Fälligkeit) fehlen oder `due_at` überschritten | Vorgangsliste, Kachel, Teamübersicht |

Beide Ampeln werden vom Scheduler minütlich (`mail:sla:evaluate`, Queue `mail-high`) und bei jeder Statusänderung neu berechnet. Berechnungsgrundlage ist immer UTC; Anzeige Europe/Berlin.

## 6. SLA-Startwerte

| Priorität | Uhr `acknowledge` | Eskalation | Uhr `first_response` | Uhr `resolve` | gelb ab |
|---|---|---|---|---|---|
| P0 | 10 Minuten Kalenderzeit | nach 5 Minuten ohne Bestätigung: Teamleitung und Eskalationskontakt (mail-high), nach 10 Minuten zusätzlich Geschäftsführung | 30 Minuten Kalenderzeit (Startvorschlag) | 4 Stunden Kalenderzeit bis Notdienst beauftragt (Startvorschlag) | 50 % |
| P1 | 4 Arbeitsstunden | bei rot: Teamleitung | 4 Arbeitsstunden | 2 Arbeitstage (Startvorschlag) | 50 % |
| P2 | 2 Arbeitstage | bei rot: Teamleitung | 2 Arbeitstage | 5 Arbeitstage (Startvorschlag) | 50 % |
| P3 | 4 Arbeitstage | keine automatische Eskalation, Bericht wöchentlich | 4 Arbeitstage | 10 Arbeitstage (Startvorschlag) | 50 % |

Vom Auftrag vorgegeben: P0 Annahme 10 Minuten mit Eskalation nach 5 Minuten, P1 4 Arbeitsstunden, P2 2 Arbeitstage, P3 4 Arbeitstage, gelb bei 50 %. Die übrigen Werte sind Startvorschläge und über `mail_sla_rules` je Team änderbar; sie sind von der Geschäftsführung zu bestätigen.

Eskalationskette: Verantwortlicher, Teamleitung (`mail_teams.lead_user_id`), Eskalationskontakt (`escalation_user_id`), Geschäftsführung (Rolle owner). Jede Eskalation erzeugt einen `mail_outbox`-Eintrag und einen Auditeintrag; die Benachrichtigung läuft über den konfigurierten Kanal (E-Mail über Laravel Mailer, kein Gmail-Versand nötig).

## 7. Arbeitszeitmodell

- Startvorschlag: Montag bis Freitag 08:00 bis 16:30 Uhr Europe/Berlin, keine Mittagspause. Konfigurierbar je Organisation und je Team (`mail_work_calendars.weekly_hours_json`).
- Feiertage: konfigurierbare Liste (`mail_holidays`) mit Vorbelegung Nordrhein-Westfalen. Die Liste ist Konfiguration, nicht Code: Neujahr, Karfreitag, Ostermontag, Tag der Arbeit, Christi Himmelfahrt, Pfingstmontag, Fronleichnam, Tag der Deutschen Einheit, Allerheiligen, 1. und 2. Weihnachtstag. Bewegliche Feiertage werden pro Jahr eingetragen oder über eine Osterberechnung (Gauß, PHP `easter_date` ist nicht zuverlässig verfügbar, daher eigene Implementierung) vorgeschlagen und vor Übernahme angezeigt. Zusätzlich frei definierbare Betriebsruhetage (24.12., 31.12. als Vorschlag).
- Berechnung von Arbeitsminuten: Intervallschnitt zwischen Start und Ziel mit den Arbeitsfenstern des Kalenders in Europe/Berlin, gespeichert als UTC. `WorkCalendar::addWorkingMinutes(CarbonImmutable $from, int $minutes): CarbonImmutable` und `WorkCalendar::workingMinutesBetween()`.
- Sommerzeit: Alle Berechnungen erfolgen in Europe/Berlin mit `CarbonImmutable`, Umrechnung nach UTC erst bei Speicherung. Tests decken den Umstellungstag im März (23-Stunden-Tag) und Oktober (25-Stunden-Tag) ab: eine 4-Arbeitsstunden-Frist über die Umstellung darf sich nicht um eine Stunde verschieben.
- Ein Ereignis außerhalb der Arbeitszeit startet die Arbeitszeit-Uhren beim nächsten Arbeitsbeginn; P0-Uhren laufen immer in Kalenderzeit.

## 8. Importierte Altmails

- `mail_messages.received_at` ist die ursprüngliche Empfangszeit (Header `Date`, ersatzweise `internalDate`), `imported_at` der Importzeitpunkt.
- `mail_cases.opened_at` übernimmt die Empfangszeit der ersten Nachricht, nicht den Import.
- SLA-Uhren für Altmails (Empfang vor `mail_mailboxes.import_from` oder älter als konfigurierbare Schwelle, Vorschlag 14 Tage) werden mit `state = cancelled` und Hinweis "Altbestand" angelegt, damit die Verzugsanzeige nicht künstlich rot wird; Verantwortlicher, nächster Schritt und Fälligkeit bleiben Pflicht.
- Altmails erhalten `priority` P3, außer die Regelerkennung findet P0/P1-Merkmale; dann Hinweis an Teamleitung statt Notfall-Eskalation.

## 9. Sichtbarkeit

Dashboard und Listen zeigen je Vorgang: Ampel gesamt, Priorität, drei Statuswerte, Verantwortlicher, nächster Schritt, Fälligkeit (TT.MM.JJJJ HH:MM), Restzeit der kritischsten Uhr. Vorgänge ohne Verantwortlichen, nächsten Schritt oder Fälligkeit stehen in einer eigenen Liste "Unvollständig" und zählen als rot.
