# Integrationsdossier Mail optimierung

Stand 25.09.2026. Erstellt nach Anhang B des Master-Prompts der MH Verwaltungsplattform (CRM-Repo `v3ni94/CRM-HV-Verwaltungssoftware`). Quelle: Repo `v3ni94/IMMOWARE24`. Das Mailprogramm ist dort als eigene Modulgruppe umgesetzt: Mail, Gmail, Cases, Sla, Actions, Ai, Drive, Paperless, Lexware, MailIntegration, MailUi, Playbooks. Enthält nur Strukturen, keine personenbezogenen Daten.

## 1. Zweck und Nutzer

Gemeinsames Postfach für die Hausverwaltung: eingehende Mails werden zu Vorgängen, zugewiesen, mit Fristen überwacht, mit KI vorbereitet und nach Freigabe beantwortet.

- Gmail-Postfächer mit Push-Abruf, Threads, Anhängen mit Prüfstatus.
- Vorgänge mit Teilanliegen, Zuständigkeit, Aufgaben, internen Notizen und Sperre „wird bearbeitet von“.
- SLA mit Prioritäten P0 bis P3 und Notfallkette, zum Beispiel Wasserschaden.
- Aktionspläne, etwa eine Adressänderung, mit Vier-Augen-Freigabe, Ausführung und Nachlesen im Zielsystem.
- KI-Vorschläge für Klassifikation, Antwortentwurf und Aktionsplan; nichts wird ohne Bestätigung geschrieben oder versendet.
- Prozessdatenbank (Playbooks): aus abgeschlossenen Vorgängen gelernte Abläufe, Vorschlag bei neuen ähnlichen Vorgängen.
- Kontext zum Vorgang: Objekt, Person, Drive- und Paperless-Dokumente, Lexware-Kontakt.

Nutzer: Sachbearbeitung, Teamleitung, Geschäftsführung, Prüfer. Oberfläche heute unter `mail.muellerhv.de`.

## 2. Technik

Gleiche Basis wie der Hub (Laravel 13, PHP 8.4, MariaDB, Redis, Docker Compose). Eigene Queues `mail-high`, `mail-sync`, `mail-ai`, eigener Worker `mail-worker-high`.

Externe Dienste: Gmail API (OAuth, Push über Pub/Sub), Google Drive, Paperless-ngx, Lexware, Anthropic und OpenAI.

Konfigurationsvariablen (Auswahl, ohne Werte): `MAIL_APP_DOMAIN`, `MAIL_IMPORT_ENABLED`, `MAIL_AI_ENABLED`, `MAIL_AI_PROVIDER`, `MAIL_GMAIL_PROVIDER`, `MAIL_GMAIL_DRAFTS_ENABLED`, `MAIL_GMAIL_SEND_ENABLED`, `MAIL_IMMOWARE_WRITE_ENABLED`, `MAIL_LEXWARE_PROVIDER`, `MAIL_LEXWARE_WRITE_ENABLED`, `MAIL_DRIVE_PROVIDER`, `MAIL_PAPERLESS_PROVIDER`, `MAIL_PAPERLESS_WRITE_ENABLED`, `MAIL_EMERGENCY_TEST_RECIPIENT`, `MAIL_QUEUE_{HIGH,SYNC,AI}`, `MAIL_RETENTION_*`, `MAIL_PUSH_RATE_LIMIT_*`.

Stand Produktion 24.09.2026: alle Schreib- und Versandschalter aus, kein Postfach angebunden, KI nicht eingerichtet.

## 3. Datenmodell

Rund 50 Tabellen mit Präfix `mail_`. Kerngruppen:

| Gruppe | Tabellen |
| --- | --- |
| Organisation | `mail_teams`, `mail_team_members`, `mail_absences`, `mail_org_settings`, `mail_property_responsibilities`, `mail_assignment_rules` |
| Postfach | `mail_mailboxes`, `mail_mailbox_aliases`, `mail_mailbox_permissions`, `mail_sync_states`, `mail_push_events` |
| Nachrichten | `mail_threads`, `mail_messages`, `mail_message_parts`, `mail_attachments` |
| Vorgang | `mail_cases` (Vorgangsnummer, `property_id`, `unit_id`, `contract_id`, `primary_contact_id`), `mail_case_items`, `mail_case_messages` (n:m zu Nachrichten), `mail_case_references`, `mail_case_locks`, `mail_case_status_log`, `mail_tasks`, `mail_assignment_decisions` |
| SLA | `mail_work_calendars`, `mail_holidays`, `mail_sla_rules`, `mail_sla_clocks`, `mail_sla_clock_log`, `mail_deadlines`, `mail_emergency_alerts`, `mail_escalation_steps` |
| Antwort | `mail_drafts`, `mail_outbox`, `mail_send_reconciliations` |
| Aktionen | `mail_action_plans`, `mail_action_plan_versions`, `mail_action_targets`, `mail_approvals`, `mail_identity_checks`, `mail_executions`, `mail_verifications` |
| KI | `mail_ai_runs`, `mail_ai_suggestions` |
| Dokumente, Lexware | `mail_drive_connections`, `mail_drive_folder_mappings`, `mail_document_references`, `mail_lexware_connections`, `mail_lexware_contact_snapshots` |
| Prozessdatenbank | `mail_playbooks`, `mail_playbook_matches` |

Objektbezug über `properties.immoware_object_number` des Hub-Spiegels. Kontaktzuordnung über Kandidaten mit Bestätigung, nicht automatisch über E-Mail.

## 4. Schnittstellen

- Weboberfläche (Blade): Posteingang, Arbeitsliste, Vorgangsdetail dreispaltig, Freigaben, Aufgaben, Administration (Postfächer, Teams, Zuständigkeiten, SLA, Kalender, Playbooks, Einrichtung).
- Gmail: OAuth pro Postfach, Push-Endpunkt `/mail/gmail`, History-Abgleich, Entwürfe und Versand über die Gmail API mit Abgleich gegen den Gesendet-Ordner.
- Keine eigene REST-API für das Mailprogramm; alles läuft über die Weboberfläche.
- Rechte: Katalog `mail.*` mit Mail-Rollen je Team, Re-Authentifizierung vor kritischen Aktionen (`2fa.fresh`).

## 5. Fachlogik, die erhalten bleiben soll

Verweise auf `app/Modules` und `docs/mail` im Repo IMMOWARE24.

- Drei Statusdimensionen: Bearbeitung (`new`, `assignment_open`, `open`, `in_progress`, `waiting_customer`, `waiting_external`, `waiting_approval`, `blocked`, `resolved`, `closed`, `reopened`), Kommunikation und Geschäftsergebnis; Abschlussbedingungen je Vorgangstyp (`docs/mail/04-status-und-sla.md`, `Cases/Services/CloseConditionChecker.php`).
- Prioritäten P0 bis P3, vier Uhren, zwei Ampeln, Arbeitszeitmodell mit Feiertagen, Umgang mit importierten Altmails (`Sla/Services/*`).
- Notfallkette mit Eskalationsstufen (`Sla/Services/EmergencyQueue.php`).
- Zuweisung nach Objektzuständigkeit, Regeln und Abwesenheit (`Cases/Services/AssignmentService.php`, `DelegationService.php`).
- Aktionspläne: Allowlist, Vorbedingungen, Vier-Augen-Freigabe, Identitätsprüfung, Ausführung über die Outbox, Nachlesen, manuelle Bestätigung, wo das Zielsystem nicht schreibbar ist (`Actions/Services/*`).
- KI: Prompt-Aufbau mit Maskierung personenbezogener Daten, Schema-Prüfung, Geschäftsregelprüfung, Budget (`Ai/Services/PromptBuilder.php`, `PromptMasker.php`, `SchemaValidator.php`, `BusinessRuleValidator.php`, `AiBudget.php`).
- Maskierung von Bankdaten in der Oberfläche (`MailUi/Support/BankDataMasker.php`).
- Versand: kein Versand ohne Schalter und Recht, Abgleich gegen den Gesendet-Ordner (`Gmail/Services/SendService.php`, `SendReconciliationService.php`).
- Prozessdatenbank: Stichwortextraktion, Ähnlichkeitsbewertung, Lernen aus abgeschlossenen Vorgängen (`Playbooks/Services/*`).
- Paperless-Dokumente zum Objekt in der Vorgangsansicht (`MailUi/Http/Controllers/CaseController.php`).
- 16 dokumentierte Abnahmefälle als Testgrundlage (`docs/mail/auftrag-abnahmefaelle.md`, `tests/Feature/MailAcceptance`).

## 6. Bekannte Probleme und technische Schulden

- Noch nicht produktiv genutzt: kein Postfach angebunden, keine Vorgänge.
- Hängt am Hub-Spiegel für Objekte und Kontakte, der in Produktion leer ist.
- Eigene Benutzer- und Rechteverwaltung parallel zum CRM.
- Die Gmail-Anbindung ist nur mit Test-Doubles geprüft, nicht gegen ein echtes Postfach.
- Umfang: etwa 50 Tabellen und rund 200 Testdateien im Gesamtrepo. Eine Übernahme ist ein großer Block.

## 7. Empfehlung

**Übernehmen als Modul des CRM** unter dem Reiter „Mail“, erreichbar zusätzlich unter `mail.mueller-holding.ag`. Anmeldung, Benutzer, Rollen und Mandanten ausschließlich über das CRM (`mhvp.core.auth`, OIDC).

Grundlage im CRM: `mhvp.communication` (M20) hat bereits Postfach, Nachrichten, Zuordnung zu Kontakt und Objekt, Ticket aus Mail und Antwortentwurf aus Vorlage. `mhvp.ai` (M7) hat Anthropic und OpenAI mit Freigabepflicht. Die Übernahme erweitert diese Module, statt ein zweites Mailsystem daneben zu stellen.

Da der Hub in PHP und das CRM in Python geschrieben ist, wird Fachlogik neu umgesetzt, nicht Code kopiert. Maßgeblich sind die Regeln und Abnahmefälle aus Abschnitt 5.

Reihenfolge:
1. Gmail-Anbindung in `mhvp.communication` (offener Punkt M20-01 „Postfachverbindung“): OAuth, Push, Threads.
2. Vorgangsmodell: Teilanliegen, drei Statusdimensionen, Sperre, Zuweisung. Abgleich mit dem vorhandenen CRM-Ticket, damit es kein doppeltes Vorgangsobjekt gibt.
3. SLA und Notfallkette.
4. Entwurf, Freigabe und Versand mit Abgleich gegen den Gesendet-Ordner.
5. Aktionspläne mit Vier-Augen-Freigabe, auf CRM-Stammdaten statt Immoware24.
6. KI-Vorschläge über `mhvp.ai`, Prompts und Maskierung übernehmen.
7. Prozessdatenbank (Playbooks).
8. Paperless- und Drive-Kontext im Vorgang.
9. Oberfläche im Next.js-Frontend (`apps/web-crm`) als Reiter „Mail“, Domain `mail.mueller-holding.ag` über Traefik.

Aufwand grob, zu verifizieren nach Sichtung von `mhvp.communication` und dem Ticketmodul: 25 bis 40 Arbeitstage für die Schritte 1 bis 9. Schritte 1, 2 und 4 zusammen ergeben bereits ein nutzbares gemeinsames Postfach.

Datenmigration: keine, das Mailprogramm ist nicht produktiv im Einsatz. Übernommen werden Konfigurationen: SLA-Startwerte, Prioritätsregeln, Zuständigkeitsregeln und Playbooks, soweit dann vorhanden.

## 8. Offene Fragen an den Betreiber

1. Sollen Vorgänge und CRM-Tickets ein gemeinsames Objekt sein? Empfehlung: ja, der Mail-Vorgang ist ein Ticket mit Mail-Bezug.
2. Erstes Postfach: `info@muellerhv.de` (entschieden 25.09.2026). Offen: welche Gesellschaften das Modul außerdem nutzen.
3. Gmail bleibt der Mailanbieter, oder ist ein Wechsel geplant?
4. Freigabe (entschieden 25.09.2026): eigene Berechtigung „Mail freigeben“ (`mail.approve`), im CRM je Benutzer über Rollen vergeben. Vier-Augen-Prinzip: Wer einen Entwurf oder Aktionsplan erstellt, kann ihn nicht selbst freigeben.
5. Soll `mail.muellerhv.de` nach der Umstellung auf `mail.mueller-holding.ag` weiterleiten?

## Zusammenfassung

1. Das Mailprogramm ist ein vollständiges gemeinsames Postfach mit Vorgängen, SLA, Freigaben und KI.
2. Es liegt im Repo IMMOWARE24 und ist noch nicht produktiv im Einsatz.
3. Empfehlung: als Modul ins CRM übernehmen, Reiter „Mail“.
4. Anmeldung und Benutzer laufen nur über das CRM.
5. Das CRM hat mit M20 und M7 bereits eine Grundlage.
6. Fachlogik wird in Python neu umgesetzt, Maßstab sind Regeln und Abnahmefälle.
7. Die Reihenfolge beginnt mit Gmail-Anbindung, Vorgangsmodell und Versand.
8. Aufwand grob 25 bis 40 Arbeitstage, zu verifizieren.
9. Keine Datenmigration nötig.
10. Erreichbar zusätzlich unter `mail.mueller-holding.ag`.
