# 05 Rollen und Rechte des Mail-Moduls

Stand: 12.09.2026. Aufbau auf der bestehenden Security-Infrastruktur (`App\Core\Enums\Role`, `PermissionMap`, Gates aus `config/hub/security.php`, Middleware `auth`, `2fa`, `2fa.fresh`). Es werden keine neuen Rollenwerte in `Role` eingeführt; die Mail-Rollen sind Rechtebündel, die in der Permission-Map auf bestehende Rollen und zusätzlich auf Teamzugehörigkeit (`mail_team_members.team_role`) abgebildet werden. Damit bleiben `users.role` und alle bestehenden Tests unverändert.

## 1. Zwei Ebenen

| Ebene | Quelle | Prüfung |
|---|---|---|
| Globale Rechte `mail.*` | `config/hub/security.php` (`permission_catalog`, `permissions`), Gate je Recht | `Gate::allows('mail.cases.write')`, `Route::can()` |
| Objektbezogene Rechte | `mail_mailbox_permissions` (je Postfach), `mail_team_members` (je Team), Objektfreigaben über Team-Zuordnung von Objekten (`mail_teams.settings_json.property_ids`, Phase 2 eigene Tabelle) | Policies `MailCasePolicy`, `MailMailboxPolicy`, `MailDraftPolicy`, `MailTaskPolicy` |

Ein Zugriff ist nur erlaubt, wenn beide Ebenen zustimmen. Globales Recht ohne Postfachfreigabe reicht nicht (Ausnahme Administrator mit `mail.mailboxes.manage` für Verwaltung, nicht für Inhalte).

## 2. Mail-Rollen als Rechtebündel

| Mail-Rolle | Zweck | Abbildung |
|---|---|---|
| Administrator | Integrationen, Postfächer, Teams, Rechte, Flags anzeigen, Export | Bestehende Rollen `owner`, `administrator` |
| Teamleitung | Vorgänge zuweisen, Prioritäten ändern, Eskalationen sehen, Teamberichte, SLA-Regeln des Teams | Bestehende Rolle `operator` oder `administrator` plus `mail_team_members.team_role = lead` |
| Sachbearbeiter | Vorgänge bearbeiten, Entwürfe erstellen, Aufgaben abschließen, eigene Postfächer lesen | Bestehende Rolle `operator` plus Postfachrecht `can_read`, `can_draft` |
| Freigabeberechtigt | Action Plans freigeben, Versand freigeben, Bankdatenänderung bestätigen (zweite Person) | Bestehende Rolle `administrator` oder `owner`, oder `operator` mit `team_role = approver`; nie Autor derselben Version |
| Prüf-/Leserolle | Lesen von Vorgängen, Auditlog, Berichte, kein Schreiben, keine Bankdaten | Bestehende Rolle `read_only` oder `developer`, oder `team_role = reviewer` |

`api_client` erhält keine `mail.*`-Rechte. REST-Zugriff auf Mail-Daten ist in Phase 1 nicht vorgesehen.

## 3. Permission-Katalog `mail.*`

| Recht | Bedeutung | owner | administrator | operator | developer | read_only |
|---|---|---|---|---|---|---|
| `mail.access` | Mail-Oberfläche betreten | x | x | x | x | x |
| `mail.cases.view` | Vorgänge der freigegebenen Postfächer/Teams lesen | x | x | x | x | x |
| `mail.cases.write` | Vorgänge anlegen, Status setzen, Schritte, Fälligkeit, Zuordnung bestätigen | x | x | x | | |
| `mail.cases.assign` | Verantwortlichen setzen, Priorität ändern (Herabstufung nur Teamleitung) | x | x | x (nur lead) | | |
| `mail.cases.close` | Vorgang schließen, Wiedereröffnen | x | x | x | | |
| `mail.messages.view` | Nachrichten der freigegebenen Postfächer lesen | x | x | x | x | x |
| `mail.attachments.download` | Anhänge laden | x | x | x | x | |
| `mail.drafts.write` | Entwürfe erstellen und ändern, an Gmail übergeben (Flag) | x | x | x | | |
| `mail.send` | Versand anfordern (Flag, Freigabe, 2fa.fresh) | x | x | x (nur mit `can_send`) | | |
| `mail.approvals.decide` | Freigaben erteilen oder verweigern (Vier-Augen) | x | x | x (nur approver) | | |
| `mail.tasks.write` | Aufgaben anlegen, bearbeiten, abschließen ("Manuell bestätigt") | x | x | x | | |
| `mail.tasks.confirm_second` | Zweite Bestätigung bei Bankdaten- und Stammdatenaufgaben | x | x | x (nur approver oder lead) | | |
| `mail.bank_data.view` | Bankdaten im Klartext sehen (Alt/Neu), sonst maskiert | x | x | x (nur mit `can_view_bank_data`) | | |
| `mail.export.run` | Export von Vorgangslisten und Berichten (CSV) | x | x | x (nur lead) | x | x |
| `mail.mailboxes.manage` | Postfächer, Aliasse, OAuth-Verbindung, Postfachrechte | x | x | | | |
| `mail.teams.manage` | Teams, Mitglieder, SLA-Regeln, Arbeitskalender | x | x | | | |
| `mail.integrations.manage` | Lexware, Drive, OpenAI, Push-Konfiguration; Flags anzeigen | x | x | | | |
| `mail.integrations.lexware.write` | Lexware-Schreibaktionen ausführen lassen (Flag) | x | x | | | |
| `mail.integrations.immoware.write` | Posteingang-Upload aus Vorgängen anstoßen (Flag, bestehende Immoware-Freigabe) | x | x | x | | |
| `mail.ai.use` | KI-Vorschläge anfordern (Flag) | x | x | x | | |
| `mail.audit.view` | Auditeinträge zum Mail-Modul sehen (nutzt `audit.view`) | x | x | | x | x |
| `mail.reports.view` | Team- und SLA-Berichte | x | x | x | x | x |

Einträge "nur lead", "nur approver", "nur mit can_*" werden in den Policies zusätzlich zur Gate-Prüfung durchgesetzt. Der Owner erhält über `*` alle Rechte, wie bisher.

Umsetzung additiv: `config/hub/mail.php` liefert `permission_catalog` und `permissions` für `mail.*`; `MailServiceProvider::register()` merged sie in `hub.security.permission_catalog` und `hub.security.permissions`, bevor `SecurityServiceProvider::boot()` die Gates registriert (Provider-Reihenfolge in `bootstrap/providers.php` beachten: Merge muss in `register()` erfolgen, Gates entstehen in `boot()`). Bestehende Rechte werden nicht verändert.

## 4. Getrennte Rechte je Gegenstand

| Gegenstand | Steuerung |
|---|---|
| Postfächer | `mail_mailbox_permissions` je Nutzer: `can_read`, `can_draft`, `can_send`, `can_assign`, `can_view_bank_data`. Ohne Zeile kein Zugriff, auch nicht für Teamleitung. Administrator verwaltet, sieht ohne eigene Zeile keine Inhalte. |
| Objekte | Team-Zuordnung von Objekten (Property-IDs) über Team-Einstellungen; Vorgänge ohne Objekt sind für alle Teammitglieder des Postfachs sichtbar. Phase 2: Tabelle `mail_team_properties`. |
| Bankdatenansicht | Recht `mail.bank_data.view` plus `can_view_bank_data`; jede Klartextanzeige wird auditiert (`mail.bank_data.viewed`), Standardanzeige maskiert (IBAN: erste 4, letzte 4 Zeichen). |
| Freigaben | `mail.approvals.decide`; Autor der Version kann nicht freigeben (`mail_approvals.approver_user_id != versions.author_user_id`); Freigabe bindet an `steps_hash`. |
| Versand | `mail.send` plus `can_send` plus Flag plus freigegebener Entwurf plus `2fa.fresh`; Alias muss zur Gesellschaft des Vorgangs passen (`legal_entity_code`). |
| Export | `mail.export.run`; Export enthält keine Bankdaten, keine Bodies, nur Metadaten; auditiert. |
| Integrationen | `mail.integrations.manage` mit `2fa.fresh`; Zugangsdaten nur schreibend eingebbar, nie anzeigbar; Widerruf OAuth jederzeit. |

## 5. Vier-Augen-Prinzip

| Aktion | Erste Person | Zweite Person | Bindung |
|---|---|---|---|
| Action Plan mit Außenwirkung (Versand an Externe, Lexware-Änderung, Posteingang-Upload) | Autor der Version (`mail.cases.write`) | Freigabeberechtigter (`mail.approvals.decide`), ungleich Autor | `steps_hash` der Version, Re-Auth der zweiten Person (`reauth_confirmed_at`) |
| Bankdatenänderung (manuelle Aufgabe) | Sachbearbeiter erfasst Alt/Neu und bestätigt Übertrag | Zweite Person bestätigt (`mail.tasks.confirm_second`) | `confirmed_by != assignee_user_id` |
| Herabstufung P0/P1 | Teamleitung | keine zweite Person, aber Begründung und Audit | |
| Aktivieren eines Schreib-Flags (`MAIL_*_ENABLED`) | Technisch: Umgebungsvariable durch Administrator | Fachlich: dokumentierte Freigabe der Geschäftsführung (Verweis in `docs/mail/10-implementierungsliste.md`) | Flags bleiben Konfiguration, kein UI-Schalter |
| Immoware-Schreibpfad | Unverändert: Administrator beantragt, Owner bestätigt (`ImmowareConnection::hasCompleteWriteApproval()`) | | bestehend |

Ein Freigabeversuch durch den Autor selbst wird mit 403 und Audit `mail.approval.rejected_self` abgewiesen.

## 6. Re-Authentifizierung (`2fa.fresh`)

Pflicht für: Freigabe erteilen, Versand anfordern, Bankdaten im Klartext anzeigen, Postfach verbinden oder trennen (OAuth), Integrationszugangsdaten setzen, Postfachrechte ändern, Export ausführen, Vorgang wiedereröffnen nach `closed`, SLA-Regeln ändern. Frischefenster wie bisher `hub.security.totp.fresh_minutes` (15 Minuten).

Alle Nutzer des Mail-Moduls benötigen eingerichtete 2FA (Middleware `2fa` in Gruppe `mail`, `exempt_roles` bleibt leer).

## 7. Sitzung und Abmeldung

Hostgebundene Sitzung (01, Abschnitt 4). Sitzungsobergrenzen wie bisher (30 Minuten inaktiv, 8 Stunden absolut). Bei Rollenwechsel oder Entzug einer Postfachfreigabe werden die anderen Sitzungen des Nutzers invalidiert (`SessionManager`, bestehend).

## 8. Audit-Pflichten je Recht

Jede Nutzung folgender Rechte erzeugt einen Auditeintrag: `mail.cases.assign`, `mail.cases.close`, `mail.send`, `mail.approvals.decide`, `mail.tasks.confirm_second`, `mail.bank_data.view`, `mail.export.run`, `mail.mailboxes.manage`, `mail.teams.manage`, `mail.integrations.*`. Lesen von Vorgängen wird nicht einzeln auditiert (Datenminimierung), Bankdatenansicht ausnahmslos.

## 9. Tests (Auszug, siehe 06)

- Jede Rolle gegen jede Route: 403 ohne Recht, 200 mit Recht (`MailPermissionMatrixTest`).
- Postfach ohne Freigabezeile: 403 trotz globalem Recht.
- Autor kann eigene Version nicht freigeben.
- Bankdaten maskiert ohne `can_view_bank_data`, Klartext auditiert.
- `2fa.fresh` erzwingt Re-Auth nach 15 Minuten.
