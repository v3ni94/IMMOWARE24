# 02 Datenmodell des Mail-Moduls

Stand: 12.09.2026. Alle Tabellen tragen das Präfix `mail_`. Bestehende Tabellen bleiben unverändert (insbesondere `cases`, das vom Modul Estate belegt ist). Migrationen liegen unter `database/migrations/2026_09_1x_2xxxxx_mail_*.php`, laufen auf SQLite und MariaDB, verwenden `json()` für JSON, `bigInteger` in Cent für Beträge, `timestamp` in UTC. Kein Hard Delete auf fachlichen Tabellen, `deleted_at` wo angegeben. Kein `TRUNCATE`.

Konventionen:

- `id` bigint auto, `organization_id` FK `organizations` (Trait `BelongsToOrganization`), `created_at`, `updated_at`.
- FK auf `users` immer `nullOnDelete()`, FK auf Mail-Tabellen `cascadeOnDelete()` nur für reine Detailtabellen (Parts, Push-Events), sonst `restrictOnDelete()`.
- Externe IDs nie als Zuordnungsgrundlage über Namen oder E-Mail; Zuordnung zu Immoware-Spiegeldaten ausschließlich über `contacts.id`, `properties.id`, `units.id`, `contracts.id` (die ihrerseits `external_id` tragen).
- Verschlüsselte Spalten (`encrypted` Cast): OAuth-Tokens, API-Keys, Bankdaten-Rohwerte in Aufgaben.

## 1. Kern (Modul Mail)

### mail_teams
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id | | |
| name | string(120) | unique je Organisation |
| slug | string(60) | unique je Organisation |
| lead_user_id | FK users nullable | Teamleitung |
| escalation_user_id | FK users nullable | Eskalationsempfänger P0/P1 |
| settings_json | json nullable | Arbeitszeitmodell-Override, Benachrichtigungskanäle |
| created_at, updated_at, deleted_at | | |

Indizes: unique(organization_id, slug), unique(organization_id, name).

### mail_team_members
| Spalte | Typ |
|---|---|
| id, team_id FK mail_teams, user_id FK users | |
| team_role | string(24): lead, member, approver, reviewer |
| active_from, active_until | date nullable |
| created_at, updated_at | |

Unique(team_id, user_id). Index(user_id).

### mail_mailboxes
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id | | |
| team_id | FK mail_teams nullable | |
| label | string(120) | |
| email_address | string(254) | Gmail-Primäradresse, unique je Organisation |
| provider | string(16) default gmail | |
| legal_entity_code | string(32) | Gesellschaft (HVM, MHAG), keine Vermischung von Absendern |
| oauth_client_id | string(200) nullable | |
| oauth_refresh_token | text nullable, encrypted | |
| oauth_access_token | text nullable, encrypted | |
| oauth_token_expires_at | timestamp nullable | |
| oauth_scopes_json | json nullable | tatsächlich erteilte Scopes |
| oauth_granted_by | FK users nullable | |
| oauth_granted_at | timestamp nullable | |
| import_enabled | boolean default false | zusätzlich zu MAIL_IMPORT_ENABLED |
| import_from | timestamp nullable | Altmails ab diesem Datum, Empfangszeit bleibt |
| status | string(16) default not_configured: not_configured, configured, active, degraded, revoked | "Nicht eingerichtet" sichtbar |
| status_reason | string(200) nullable | |
| last_error_at, last_error_class | timestamp nullable, string(200) nullable | |
| created_at, updated_at, deleted_at | | |

Indizes: unique(organization_id, email_address), index(status), index(team_id).

### mail_mailbox_aliases
| Spalte | Typ |
|---|---|
| id, mailbox_id FK mail_mailboxes | |
| send_as_email | string(254) |
| display_name | string(200) nullable |
| reply_to | string(254) nullable |
| legal_entity_code | string(32) |
| verification_status | string(16): accepted, pending, unknown |
| is_default, is_primary | boolean |
| signature_key | string(60) nullable (Signatur aus CI-Skill, nicht aus Gmail) |
| synced_at | timestamp nullable |
| created_at, updated_at | |

Unique(mailbox_id, send_as_email).

### mail_mailbox_permissions
| Spalte | Typ |
|---|---|
| id, mailbox_id FK mail_mailboxes, user_id FK users | |
| can_read, can_draft, can_send, can_assign, can_view_bank_data | boolean default false |
| granted_by FK users nullable, granted_at | |
| created_at, updated_at | |

Unique(mailbox_id, user_id). Index(user_id).

### mail_sync_states
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, mailbox_id FK mail_mailboxes | | |
| last_history_id | string(32) nullable | uint64 als String |
| history_id_updated_at | timestamp nullable | |
| watch_expiration | timestamp nullable | |
| watch_requested_at | timestamp nullable | |
| watch_confirmed_at | timestamp nullable | erst nach erstem Push oder erfolgreichem History-Abgleich |
| watch_status | string(16): none, requested, active, expired, failed | HTTP 200 auf watch ist nur requested |
| full_sync_cursor | string(255) nullable | pageToken |
| full_sync_started_at, full_sync_finished_at | timestamp nullable | |
| last_incremental_at | timestamp nullable | |
| lock_owner | string(64) nullable | Diagnose |
| created_at, updated_at | | |

Unique(mailbox_id).

## 2. Nachrichten (Modul Gmail)

### mail_threads
| Spalte | Typ |
|---|---|
| id, organization_id, mailbox_id FK mail_mailboxes | |
| gmail_thread_id | string(32) |
| subject_normalized | string(500) nullable |
| first_message_at, last_message_at | timestamp nullable |
| message_count | unsignedInteger default 0 |
| created_at, updated_at | |

Unique(mailbox_id, gmail_thread_id). Index(last_message_at).

### mail_messages
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, mailbox_id FK mail_mailboxes | | |
| thread_id | FK mail_threads nullable | |
| gmail_message_id | string(32) | |
| gmail_history_id | string(32) nullable | |
| rfc_message_id | string(998) nullable | Header Message-ID |
| in_reply_to | string(998) nullable | |
| references_json | json nullable | |
| direction | string(8): inbound, outbound | |
| from_address | string(254) | |
| from_name | string(200) nullable | |
| to_json, cc_json, bcc_json | json nullable | |
| reply_to | string(254) nullable | |
| subject | string(998) nullable | |
| snippet | string(500) nullable | |
| received_at | timestamp | Header Date bzw. internalDate; Altmails behalten diesen Wert |
| imported_at | timestamp | |
| label_ids_json | json nullable | |
| is_read_in_gmail | boolean default false | Informativ, "gelesen ist nicht bearbeitet" |
| has_attachments | boolean default false | |
| size_estimate | unsignedBigInteger nullable | |
| body_text | longText nullable | |
| body_html_sanitized | longText nullable | serverseitig bereinigt |
| body_fetched_at | timestamp nullable | full erst bei Bedarf |
| raw_payload_id | FK external_payloads nullable | Rohnutzlast mit SHA-256 (bestehende Tabelle) |
| checksum | char(64) | |
| processing_status | string(16): imported, classified, assigned, ignored, failed | technisch, kein Bearbeitungsstatus |
| sender_contact_id | FK contacts nullable | Vorschlag, gesetzt nur nach Bestätigung oder eindeutiger Kontaktkennung |
| sender_match_method | string(24) nullable: none, identifier, manual | nie "name" |
| created_at, updated_at, deleted_at | | |

Indizes: unique(mailbox_id, gmail_message_id), index(thread_id), index(received_at), index(from_address), index(rfc_message_id(191) auf MariaDB, in SQLite normal), index(processing_status), index(sender_contact_id).

### mail_message_parts
| Spalte | Typ |
|---|---|
| id, message_id FK mail_messages cascade | |
| part_id | string(64) |
| parent_part_id | string(64) nullable |
| mime_type | string(120) |
| filename | string(255) nullable |
| content_id | string(255) nullable |
| disposition | string(20) nullable |
| size_bytes | unsignedBigInteger nullable |
| gmail_attachment_id | string(255) nullable |
| headers_json | json nullable |
| created_at | |

Unique(message_id, part_id).

### mail_attachments
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, message_id FK mail_messages, part_id FK mail_message_parts nullable | | |
| filename | string(255) | |
| mime_type | string(120) | |
| size_bytes | unsignedBigInteger | |
| sha256 | char(64) | |
| storage_disk, storage_path | string(40), string(512) nullable | lokal verschlüsselt oder nicht geladen |
| fetched_at | timestamp nullable | |
| scan_status | string(16): pending, clean, blocked, skipped | Dateitypen-Allowlist |
| immoware_document_id | FK documents nullable | nach Upload in den Posteingang und Verifikation |
| created_at, updated_at, deleted_at | | |

Indizes: index(message_id), index(sha256).

### mail_push_events (Dedup)
| Spalte | Typ | Bemerkung |
|---|---|---|
| id | | |
| mailbox_id | FK mail_mailboxes nullable | null, wenn Adresse unbekannt |
| pubsub_message_id | string(64) | Dedup-Schlüssel |
| email_address | string(254) | |
| history_id | string(32) | |
| publish_time | timestamp nullable | |
| received_at | timestamp | |
| auth_result | string(16): ok, invalid_jwt, missing, bad_audience, bad_email, token_mismatch | |
| processed_at | timestamp nullable | |
| outcome | string(16) nullable: queued, duplicate, ignored, failed | |
| created_at | | |

Unique(pubsub_message_id). Index(mailbox_id, received_at). Retention 30 Tage (Prune-Kommando).

## 3. Vorgänge (Modul Cases)

### mail_cases
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id | | |
| case_number | string(32) | Format `V-JJJJ-NNNNNN`, unique |
| mailbox_id | FK mail_mailboxes nullable | |
| team_id | FK mail_teams nullable | |
| case_type | string(32) | z. B. schaden_notfall, schaden, adressaenderung, bankdaten, beschwerde, anfrage_allgemein, rechnung, kuendigung, sonstiges |
| title | string(300) | |
| priority | string(2): P0, P1, P2, P3 | |
| priority_reason | string(200) nullable | Regel oder manuell |
| status_processing | string(24) | Dimension 1 (04) |
| status_communication | string(24) | Dimension 2 |
| status_business | string(24) | Dimension 3 |
| assignee_user_id | FK users nullable | Verantwortlicher (Pflicht bei offen) |
| next_step | string(500) nullable | Pflicht bei offen |
| due_at | timestamp nullable | Fälligkeit (Pflicht bei offen) |
| opened_at | timestamp | Empfangszeit der ersten Nachricht (auch bei Altmails) |
| first_response_at | timestamp nullable | |
| acknowledged_at, acknowledged_by | timestamp nullable, FK users nullable | P0-Annahme |
| resolved_at, closed_at, closed_by | timestamp nullable | |
| close_reason | string(200) nullable | |
| primary_contact_id | FK contacts nullable | |
| property_id | FK properties nullable | |
| unit_id | FK units nullable | |
| contract_id | FK contracts nullable | |
| immoware_connection_id | FK immoware_connections nullable | Quelle der Referenzen |
| legal_entity_code | string(32) nullable | handelnde Gesellschaft |
| ai_summary | text nullable | nur Vorschlag, gekennzeichnet |
| ai_classification_json | json nullable | Schema-validiert |
| tags_json | json nullable | |
| created_by | FK users nullable | |
| created_at, updated_at, deleted_at | | |

Indizes: unique(case_number), index(status_processing, due_at), index(assignee_user_id, status_processing), index(priority, opened_at), index(property_id), index(unit_id), index(primary_contact_id), index(team_id), index(mailbox_id).

### mail_case_items (Teilanliegen)
| Spalte | Typ |
|---|---|
| id, case_id FK mail_cases | |
| position | unsignedSmallInteger |
| item_type | string(32) (wie case_type) |
| title | string(300) |
| description | text nullable |
| status_processing, status_communication, status_business | string(24) |
| assignee_user_id FK users nullable, due_at timestamp nullable | |
| source_message_id | FK mail_messages nullable |
| completed_at | timestamp nullable |
| created_at, updated_at, deleted_at | |

Index(case_id, status_processing).

### mail_case_messages
| Spalte | Typ |
|---|---|
| id, case_id FK mail_cases, message_id FK mail_messages | |
| link_type | string(16): origin, followup, reply, forwarded, manual |
| linked_by FK users nullable, linked_at | |
| created_at | |

Unique(case_id, message_id). Index(message_id).

### mail_case_references (externe IDs)
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, case_id FK mail_cases | | |
| target_system | string(24): immoware24, lexware, gmail, drive | |
| reference_type | string(32): contact, property, unit, contract, document, ticket, lexware_contact, drive_file, thread | |
| external_id | string(255) | |
| external_id_hash | char(64) | |
| local_id | unsignedBigInteger nullable | z. B. contacts.id |
| connection_id | FK immoware_connections nullable | |
| verified_at | timestamp nullable | Existenz per Nachlesen bestätigt |
| created_by FK users nullable | | |
| created_at, updated_at | | |

Unique(case_id, target_system, reference_type, external_id_hash).

### mail_assignment_decisions
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, case_id FK mail_cases, message_id FK mail_messages nullable | | |
| decision_type | string(24): contact, property, unit, contract, team, assignee, priority, case_type | |
| proposed_value_json | json | Kandidaten mit Quelle (regel, kennung, ki) |
| chosen_value | string(255) nullable | |
| chosen_local_id | unsignedBigInteger nullable | |
| decided_by | FK users nullable | null = automatisch nur bei eindeutiger Kennung |
| decision_basis | string(24): identifier, rule, manual, ai_confirmed | nie ai_unconfirmed |
| decided_at | timestamp | |
| created_at | | |

Index(case_id, decision_type).

### mail_tasks
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, case_id FK mail_cases nullable, case_item_id FK mail_case_items nullable | | |
| task_type | string(32): manual_change_immoware, manual_change_lexware, callback, site_visit, document_request, internal_review, other | |
| title | string(300) | |
| instructions | text nullable | |
| old_value_json, new_value_json | json nullable, encrypted für Bankdaten | Alt/Neu bei Stammdatenänderung |
| target_system | string(24) nullable | |
| assignee_user_id | FK users nullable | Pflicht bei offen |
| due_at | timestamp nullable | Pflicht bei offen |
| status | string(24): open, in_progress, waiting, done_manual_confirmed, done_verified, cancelled | "Manuell bestätigt" = done_manual_confirmed |
| confirmed_by, confirmed_at | FK users nullable, timestamp nullable | |
| proposed_change_id | FK proposed_changes nullable | bestehender Rückweg Immoware24 |
| created_by | FK users nullable | |
| created_at, updated_at, deleted_at | | |

Index(assignee_user_id, status, due_at), index(case_id).

## 4. SLA (Modul Sla)

### mail_work_calendars
| Spalte | Typ |
|---|---|
| id, organization_id | |
| name | string(120) |
| timezone | string(64) default Europe/Berlin |
| weekly_hours_json | json (Mo bis Fr 08:00 bis 16:30 als Startvorschlag) |
| is_default | boolean |
| created_at, updated_at | |

### mail_holidays
| Spalte | Typ |
|---|---|
| id, work_calendar_id FK mail_work_calendars | |
| holiday_date | date |
| label | string(120) |
| region | string(8) default NW |
| created_at | |

Unique(work_calendar_id, holiday_date).

### mail_sla_rules
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, team_id FK mail_teams nullable | | |
| priority | string(2) | |
| case_type | string(32) nullable | null = alle |
| clock_type | string(24): acknowledge, first_response, resolve, task_due | |
| target_minutes | unsignedInteger | in Arbeitszeit, außer P0 (Kalenderzeit) |
| uses_calendar | boolean | |
| warn_percent | unsignedTinyInteger default 50 | gelb |
| escalate_after_minutes | unsignedInteger nullable | P0: 5 |
| escalate_to_role | string(24) nullable | |
| active | boolean | |
| created_at, updated_at | | |

Unique(organization_id, team_id, priority, case_type, clock_type).

### mail_sla_clocks
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, case_id FK mail_cases, case_item_id FK mail_case_items nullable | | |
| clock_type | string(24) | vier Uhren (04) |
| sla_rule_id | FK mail_sla_rules nullable | |
| started_at | timestamp | |
| paused_at | timestamp nullable | |
| paused_minutes | unsignedInteger default 0 | |
| target_at | timestamp | berechnet mit Kalender |
| warn_at | timestamp | |
| stopped_at | timestamp nullable | |
| state | string(16): running, paused, met, breached, cancelled | |
| color | string(8): green, yellow, red | |
| last_evaluated_at | timestamp | |
| created_at, updated_at | | |

Index(state, target_at), unique(case_id, case_item_id, clock_type).

### mail_deadlines
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, case_id FK mail_cases | | |
| kind | string(24): internal, external, statutory_hint | Fristen nur als Orientierung |
| label | string(200) | |
| due_at | timestamp | |
| pre_alert_at | timestamp nullable | Vorfrist |
| verified_by, verified_at | FK users nullable, timestamp nullable | "zu verifizieren" bis gesetzt |
| status | string(16): open, met, missed, cancelled | |
| created_by | FK users nullable | |
| created_at, updated_at | | |

Index(due_at, status).

## 5. Aktionen (Modul Actions)

### mail_action_plans
| Spalte | Typ |
|---|---|
| id, organization_id, case_id FK mail_cases, case_item_id FK mail_case_items nullable | |
| current_version_id | FK mail_action_plan_versions nullable |
| status | string(24): draft, pending_approval, approved, executing, verified, failed, rejected, superseded |
| created_by FK users nullable | |
| created_at, updated_at | |

### mail_action_plan_versions
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, action_plan_id FK mail_action_plans | | |
| version | unsignedInteger | |
| steps_json | json | Liste Schritte: action_key, target_system, params (maskiert), erwartetes Ergebnis |
| steps_hash | char(64) | Freigabe bindet an Hash |
| generated_by | string(16): user, rule, ai | KI-generierte Pläne immer draft |
| author_user_id | FK users nullable | |
| change_note | string(500) nullable | |
| created_at | | |

Unique(action_plan_id, version).

### mail_approvals
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, action_plan_version_id FK mail_action_plan_versions | | |
| approver_user_id | FK users | ungleich Autor (Vier-Augen) |
| decision | string(16): approved, rejected | |
| steps_hash | char(64) | muss zur Version passen |
| comment | string(500) nullable | |
| reauth_confirmed_at | timestamp | aus 2fa.fresh |
| created_at | | |

Unique(action_plan_version_id, approver_user_id).

### mail_executions
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, action_plan_version_id FK, step_index unsignedSmallInteger | | |
| execution_uuid | uuid unique | |
| idempotency_key | string(128) unique | Retry erzeugt keinen zweiten Aufruf |
| action_key | string(64) | aus Allowlist |
| target_system | string(24) | |
| status | string(24): pending, running, http_ok_unverified, verified, failed, blocked_flag, blocked_capability, blocked_permission | HTTP 2xx ist http_ok_unverified |
| request_summary_json | json nullable | ohne Secrets |
| response_status | unsignedSmallInteger nullable | |
| response_excerpt | text nullable | maskiert, max 4000 Zeichen |
| write_operation_id | FK write_operations nullable | Immoware Posteingang |
| started_at, finished_at | timestamp nullable | |
| attempts | unsignedTinyInteger default 0 | |
| error_class, error_message | string(200) nullable, text nullable | |
| created_at, updated_at | | |

Index(status), index(action_plan_version_id, step_index).

### mail_verifications
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, execution_id FK mail_executions | | |
| method | string(32): reread_get, propfind_etag, gmail_sent_label, lexware_version_compare, manual_confirmation | |
| expected_json, observed_json | json nullable | |
| result | string(16): verified, mismatch, unavailable, pending | |
| verified_by | FK users nullable | bei manual_confirmation |
| verified_at | timestamp nullable | |
| created_at | | |

Index(execution_id).

### mail_drafts
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, case_id FK mail_cases nullable, mailbox_id FK mail_mailboxes | | |
| alias_id | FK mail_mailbox_aliases nullable | Absender je Gesellschaft |
| reply_to_message_id | FK mail_messages nullable | |
| to_json, cc_json, bcc_json | json | |
| subject | string(998) | |
| body_text | longText | |
| body_html | longText nullable | |
| attachments_json | json nullable | Referenzen auf mail_attachments oder drive_file |
| generated_by | string(16): user, template, ai | |
| ai_prompt_hash | char(64) nullable | |
| status | string(24): local, pending_approval, approved, pushed_to_gmail, sent_requested, sent_verified, send_failed, discarded | |
| gmail_draft_id | string(64) nullable | |
| approved_by, approved_at | FK users nullable, timestamp nullable | |
| approval_reauth_confirmed_at | timestamp nullable | Re-Authentifizierung der freigebenden Person aus der Sitzung, analog approvals.reauth_confirmed_at, Migration 2026_09_14_000003 |
| sent_requested_by, sent_requested_at | | |
| sent_message_id | FK mail_messages nullable | nach Abgleich |
| created_by | FK users nullable | |
| created_at, updated_at, deleted_at | | |

Index(case_id), index(status), index(gmail_draft_id).

### mail_send_reconciliations
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, draft_id FK mail_drafts | | |
| requested_at | timestamp | |
| gmail_response_message_id | string(32) nullable | aus drafts.send |
| expected_rfc_message_id | string(998) | selbst erzeugt |
| found_in_sent_at | timestamp nullable | messages.get mit Label SENT |
| attempts | unsignedTinyInteger | |
| next_check_at | timestamp nullable | |
| result | string(16): pending, verified, not_found, mismatch | |
| created_at, updated_at | | |

Index(result, next_check_at).

### mail_outbox
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id | | |
| event | string(64) | z. B. case.escalated, task.due, draft.approved |
| aggregate_type, aggregate_id | string(40), unsignedBigInteger | |
| payload_json | json | maskiert |
| queue | string(24) | mail-high, mail-sync, mail-ai |
| status | string(16): pending, dispatched, failed | |
| dispatched_at | timestamp nullable | |
| attempts | unsignedTinyInteger | |
| created_at | | |

Index(status, created_at). Einträge entstehen in derselben Transaktion wie die fachliche Änderung (Muster `WebhookDispatcher`).

## 6. Dokumente (Modul Drive und Anhänge)

### mail_document_references
| Spalte | Typ | Bemerkung |
|---|---|---|
| id, organization_id, case_id FK mail_cases | | |
| source | string(16): drive, immoware, attachment | |
| drive_file_id | string(128) nullable | |
| immoware_document_id | FK documents nullable | |
| attachment_id | FK mail_attachments nullable | |
| name | string(255) | |
| mime_type | string(120) nullable | |
| web_view_link | string(1024) nullable | |
| permissions_summary_json | json nullable | wer sieht das Dokument |
| linked_by FK users nullable, linked_at | | |
| verified_at | timestamp nullable | Existenz nachgelesen |
| created_at, updated_at, deleted_at | | |

Index(case_id), index(drive_file_id).

## 7. Audit

Keine eigene Audit-Tabelle. Alle Aktionen laufen über `AuditLoggerInterface::log()` in `audit_logs` mit `entity_type` = Klassenbasename (`MailCase`, `MailDraft`, `MailApproval`, ...), `source` aus `AuditSource` (`mail`, `gmail_push`, `ai`, `user`, `system`). Pflicht-Auditereignisse: Vorgang angelegt/zugewiesen/Status geändert/geschlossen, Freigabe erteilt/verweigert, Ausführung gestartet/verifiziert/fehlgeschlagen, Versand angefordert/bestätigt, Bankdaten angezeigt, Export ausgeführt, Integration geändert, OAuth erteilt/widerrufen.

## 8. Fremdschlüssel auf Bestand (Übersicht)

| Mail-Tabelle | FK-Ziel |
|---|---|
| alle mit organization_id | organizations |
| mail_team_members, mail_mailbox_permissions, alle *_by-Spalten | users (nullOnDelete) |
| mail_messages.sender_contact_id, mail_cases.primary_contact_id | contacts |
| mail_cases.property_id, unit_id, contract_id | properties, units, contracts |
| mail_cases.immoware_connection_id, mail_case_references.connection_id | immoware_connections |
| mail_attachments.immoware_document_id, mail_document_references.immoware_document_id | documents |
| mail_messages.raw_payload_id | external_payloads |
| mail_tasks.proposed_change_id | proposed_changes |
| mail_executions.write_operation_id | write_operations |

## 9. Retention (Vorschlag, 08)

| Tabelle | Aufbewahrung |
|---|---|
| mail_push_events | 30 Tage |
| mail_messages Body und mail_attachments Inhalt | konfigurierbar, Vorschlag 10 Jahre für geschäftsrelevante Vorgänge, 1 Jahr für ignorierte Nachrichten; Löschung nur als Soft Delete plus Inhaltsentfernung (`body_text = null`, Datei gelöscht), Metadaten bleiben |
| mail_sync_states, mail_sla_clocks | mit Vorgang |
| audit_logs | unbegrenzt, append-only |
