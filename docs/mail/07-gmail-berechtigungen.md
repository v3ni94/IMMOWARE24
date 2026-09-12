# 07 Gmail-Berechtigungen: minimale OAuth-Scope-Matrix

Stand: 12.09.2026. Quelle: `docs/mail/research/gmail-api.md` (WebSearch-Snippets). Alle Aussagen zu Scopes und Methoden sind aus Snippets und vor Implementierung am Original (developers.google.com) zu prüfen. Kein Live-Aufruf war möglich.

## 1. Grundsatz

Minimalprinzip: Je Funktion nur der kleinste Scope, der sie ermöglicht. Scopes werden je Postfach erteilt und in `mail_mailbox_aliases`-unabhängig in `mail_mailboxes.oauth_scopes_json` gespeichert. Die Anwendung fordert nur die Scopes an, deren Funktion per Feature-Flag aktiviert ist. Ein späteres Aktivieren eines Flags verlangt eine erneute OAuth-Einwilligung mit erweitertem Scope (inkrementelle Autorisierung), die Oberfläche zeigt dies als "Berechtigung fehlt, erneut verbinden".

## 2. Scope-Matrix je Funktion

| Funktion | Methoden (Snippet) | Minimaler Scope | Einstufung (Snippet) | Flag | Bemerkung |
|---|---|---|---|---|---|
| Nachrichten und History lesen, Anhänge laden, Versand nachlesen | history.list, messages.list, messages.get (metadata, full), threads.get, messages.attachments.get, getProfile | `https://www.googleapis.com/auth/gmail.readonly` | restricted | MAIL_IMPORT_ENABLED | `gmail.metadata` reicht nicht (kein Body, kein `format=full`) |
| Push einrichten | users.watch, users.stop | `gmail.readonly` (Snippets nennen keinen eigenen Scope; abgeleitet, zu prüfen) plus Pub/Sub-Topic mit Publisher-Rolle für `gmail-api-push@system.gserviceaccount.com` | | MAIL_IMPORT_ENABLED | Watch-Erfolg ohne Publisher-Rolle liefert nie Nachrichten |
| Entwürfe anlegen, ändern, löschen | drafts.create, drafts.update, drafts.delete | `https://www.googleapis.com/auth/gmail.compose` | restricted | MAIL_GMAIL_DRAFTS_ENABLED | **compose erlaubt auch drafts.send und messages.send** (Snippet). Der Versand wird deshalb serverseitig durch `MAIL_GMAIL_SEND_ENABLED`, Recht `mail.send`, Freigabe und Allowlist begrenzt, nicht durch den Scope allein |
| Senden | drafts.send oder messages.send | `https://www.googleapis.com/auth/gmail.send` (nur senden, sensitive) | sensitive | MAIL_GMAIL_SEND_ENABLED | Wenn compose bereits erteilt ist, ist `gmail.send` technisch nicht zusätzlich nötig; die Anwendung fordert ihn trotzdem explizit an, damit die Einwilligung den Versand sichtbar benennt |
| Aliasse lesen | settings.sendAs.list | `https://www.googleapis.com/auth/gmail.settings.basic` (lesend genutzt) | restricted | MAIL_IMPORT_ENABLED | Ob `gmail.readonly` für `sendAs.list` genügt, ist am Original zu prüfen (offener Punkt 7 der Recherche); falls ja, entfällt settings.basic |
| Labels setzen, Nachrichten ändern oder löschen | messages.modify, trash, delete | `gmail.modify` oder `https://mail.google.com/` | restricted | keines | **Nicht angefordert.** Bearbeitungsstatus lebt im Hub; Löschen ist ausgeschlossen |

## 3. Scope-Sätze je Flagkombination

| Aktive Flags | Angeforderte Scopes |
|---|---|
| keines | keine Verbindung möglich, Postfach bleibt "Nicht eingerichtet" |
| MAIL_IMPORT_ENABLED | gmail.readonly (+ gmail.settings.basic, bis Prüfung am Original das Gegenteil zeigt) |
| + MAIL_GMAIL_DRAFTS_ENABLED | + gmail.compose |
| + MAIL_GMAIL_SEND_ENABLED | + gmail.send |

Die Anwendung prüft nach jedem Token-Refresh die tatsächlich erteilten Scopes (Antwortfeld `scope`, am Original prüfen) und speichert sie. Fehlt ein Scope für eine Funktion, wird die Funktion mit Hinweis "Berechtigung fehlt" gesperrt statt einen 403 der API als Fehler erst zur Laufzeit zu zeigen.

## 4. Serverseitige Begrenzung des Versands

Da `gmail.compose` den Versand technisch mit erlaubt, gilt unabhängig vom Scope:

1. `MAIL_GMAIL_SEND_ENABLED` muss true sein (Default false, in Staging durch `MailBootGuard` gesperrt).
2. Nutzer braucht `mail.send` und Postfachrecht `can_send`.
3. Entwurf muss `approved` sein (Vier-Augen bei Außenwirkung).
4. Aktion `gmail.draft.send` muss in der Allowlist stehen; `messages.send` mit fremdem Raw ist nicht in der Allowlist.
5. Absender-Alias muss `verificationStatus = accepted` haben und zur Gesellschaft des Vorgangs passen.
6. Re-Authentifizierung (`2fa.fresh`).
7. Nach dem Aufruf: Versandabgleich (Label SENT, Message-ID), sonst kein `sent_verified`.

## 5. App-Typ und Verifizierung

- Cloud-Projekt in der Google-Workspace-Organisation `muellerhv.de`, OAuth-Consent-Screen Typ **Intern**. Nach den Snippets entfällt damit die externe Verifizierung und die jährliche Sicherheitsprüfung für restricted Scopes. Am Original (Cloud Console) zu prüfen.
- Kein Testing-Modus in Produktion (Refresh-Tokens laufen dort nach 7 Tagen ab, Snippet).
- **Keine Domain-wide Delegation ohne Bedarf.** Für ein oder wenige Teampostfächer reicht der Nutzer-OAuth-Flow (Authorization Code mit Refresh-Token), erteilt durch den Postfachinhaber oder einen Workspace-Administrator mit Zugriff auf das Postfach. Domain-wide Delegation würde Zugriff auf alle Postfächer der Domain eröffnen und widerspricht dem Minimalprinzip. Falls später viele Postfächer angebunden werden, ist die Entscheidung mit Datenschutzberatung neu zu treffen.
- Workspace-Admin sollte die App unter "Control which apps access Google Workspace data" als vertrauenswürdig markieren, damit restricted Scopes für die interne App erteilt werden können (Snippet).

## 6. Token-Handling

- Refresh- und Access-Token verschlüsselt (`encrypted` Cast) in `mail_mailboxes`, nie in Logs, Exceptions, Views oder Audit (`SecretMasker`).
- `state`-Parameter des OAuth-Flows kryptografisch zufällig, sessiongebunden, einmalig.
- Redirect-URI ausschließlich `https://mail.muellerhv.de/mail/gmail/oauth/callback` (Produktion) und die Staging-Variante im Staging-Projekt.
- Widerruf: Schaltfläche "Verbindung trennen" ruft den Google-Revoke-Endpunkt (am Original prüfen) und löscht die Token; Postfach wechselt auf `revoked`.
- Token-Refresh-Fehler (400 `invalid_grant`) setzt Postfach auf `degraded` mit sichtbarem Hinweis und Benachrichtigung an Administrator; kein stiller Retry ohne Ende.

## 7. Pub/Sub

- Topic `projects/<projekt>/topics/gmail-mail-muellerhv`, Push-Subscription auf `https://mail.muellerhv.de/mail/gmail/push/<token>` mit OIDC-Token (Dienstkonto der Subscription, Audience = Endpunkt-URL).
- Publisher-Rolle für `gmail-api-push@system.gserviceaccount.com` auf dem Topic (Snippet).
- Erneuerung `users.watch` täglich (Ablauf 7 Tage), zusätzlich Polling-Fallback `history.list` alle 5 Minuten, weil Push nicht garantiert ist.
- Ack-Frist: Endpunkt antwortet sofort 204 nach Dedup-Eintrag und Job-Dispatch; Verarbeitung asynchron auf `mail-sync`.

## 8. Offene Punkte (am Original zu prüfen)

1. Scope für `users.watch` und `users.stop`.
2. Ob `sendAs.list` mit `gmail.readonly` funktioniert.
3. Antwortfeld mit den tatsächlich erteilten Scopes beim Token-Tausch.
4. Revoke-Endpunkt und Verhalten bei bereits ungültigem Token.
5. Quota-Kosten von `users.watch`; Regime für neue Projekte ab 01.05.2026 (6.000 Einheiten pro Minute je Nutzer).
