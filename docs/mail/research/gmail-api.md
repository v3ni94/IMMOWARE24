# Gmail API, Rechercheergebnis für das Mailmodul (mail.muellerhv.de)

Stand: 12.09.2026. Quelle der Erkenntnisse: ausschließlich WebSearch-Snippets, da die offiziellen Doku-Hosts (developers.google.com, docs.cloud.google.com) in dieser Umgebung per WebFetch gesperrt sind. Jede Aussage trägt einen Status:

- **Snippet gesehen**: Aussage stand wörtlich oder sinngemäß in einem Suchergebnis-Snippet.
- **abgeleitet**: Schlussfolgerung aus mehreren Snippets oder aus allgemeinem API-Wissen, nicht im Snippet belegt.

Alle Aussagen zu Fremd-APIs gelten als **aus Snippets, vor Implementierung am Original zu prüfen**. Es wurde kein Aufruf gegen die echte Gmail API durchgeführt; ein Live-Test war und ist in dieser Umgebung nicht möglich.

---

## 1. OAuth-Scopes und Einstufung

| Scope | Zweck | Einstufung | Status |
|---|---|---|---|
| `https://www.googleapis.com/auth/gmail.readonly` | Alle Ressourcen und Metadaten lesen, keine Schreiboperation | restricted | Snippet gesehen |
| `.../gmail.metadata` | Labels, History, Nachrichten-Header lesen, kein Body, keine Anhänge | restricted | Snippet gesehen |
| `.../gmail.modify` | Alle Lese- und Schreiboperationen außer endgültigem Löschen unter Umgehung des Papierkorbs | restricted | Snippet gesehen |
| `.../gmail.compose` | Drafts anlegen, lesen, ändern, löschen; Nachrichten und Drafts senden | restricted | Snippet gesehen |
| `.../gmail.send` | Nur senden | sensitive (nicht restricted), Verifizierung nötig, keine Sicherheitsprüfung | Snippet gesehen |
| `.../gmail.settings.basic` | Grundeinstellungen verwalten (u. a. sendAs-Aliase) | restricted | Snippet gesehen |
| `.../gmail.insert`, `https://mail.google.com/` | Einfügen bzw. Vollzugriff | restricted | Snippet gesehen |

Bemerkung: `format=full` und `format=raw` bei `messages.get` sind mit dem Scope `gmail.metadata` nicht nutzbar (Snippet gesehen, siehe Abschnitt 6).

Empfehlung für das Mailmodul (abgeleitet): `gmail.modify` (Lesen, Labels setzen, Drafts, Senden) plus `gmail.settings.basic` nur, falls sendAs-Aliase gelesen werden sollen. Minimalvariante ohne Alias-Lesen: `gmail.modify` allein. Für `sendAs.list` reicht nach allgemeinem Doku-Muster vermutlich bereits `gmail.readonly` oder `gmail.modify`, das ist am Original zu prüfen (abgeleitet, nicht im Snippet belegt).

Quellen:
- https://developers.google.com/workspace/gmail/api/auth/scopes
- https://support.google.com/cloud/answer/13464325
- https://www.unipile.com/gmail-api-scopes-guide/
- https://bollardai.com/resources/gmail

---

## 2. users.watch (Push-Benachrichtigungen einrichten)

Request-Felder (Snippet gesehen):
- `topicName`: vollqualifizierter Pub/Sub-Topic-Name (`projects/<projekt>/topics/<topic>`). Topic muss existieren, Gmail muss Publish-Recht darauf haben.
- `labelIds[]`: Liste von Label-IDs zur Einschränkung. Ohne Angabe werden alle Änderungen gemeldet.
- `labelFilterBehavior` (`include` / `exclude`): in den Snippets nicht explizit genannt, aus allgemeinem API-Wissen bekannt (abgeleitet, zu prüfen). Es existiert ein alter Issue-Tracker-Eintrag zu Problemen mit dem labelIds-Filter (https://issuetracker.google.com/issues/36759803, Snippet gesehen).

Response-Felder (Snippet gesehen):
- `historyId`: aktuelle History-ID der Mailbox (String, uint64-Semantik).
- `expiration`: Epoch-Millisekunden, ab dann sendet Gmail keine Benachrichtigungen mehr.

Gültigkeit (Snippet gesehen):
- Watch läuft nach 7 Tagen ab. Google empfiehlt tägliche Erneuerung. Erneuter `users.watch` ist idempotent und setzt die Frist zurück.
- `users.stop` beendet Push-Benachrichtigungen (Signatur im Snippet nicht detailliert, abgeleitet: POST `users/me/stop` ohne Body).

Pub/Sub-Berechtigung (Snippet gesehen):
- Gmail publiziert über das Google-verwaltete Dienstkonto `gmail-api-push@system.gserviceaccount.com`. Diesem Konto muss auf dem Topic die Rolle **Pub/Sub Publisher** (`roles/pubsub.publisher`) gegeben werden.
- Ohne diese Rolle ist `users.watch` **erfolgreich**, aber es kommen nie Benachrichtigungen an. Das ist ein Musterfall für die Auftragsregel "erfolgreicher HTTP-Aufruf ist kein verifiziertes Geschäftsergebnis": Der Watch-Status im Modul darf erst als "aktiv" gelten, wenn nach dem Watch tatsächlich eine Benachrichtigung eingetroffen ist oder ein History-Abgleich läuft (abgeleitet).

Konsequenz für das Modul (abgeleitet):
- Scheduler-Job täglich `users.watch` erneuern, `expiration` und `historyId` persistent speichern.
- Zusätzlich periodischer Polling-Fallback über `history.list`, weil Push nicht garantiert ist.

Quellen:
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users/watch
- https://developers.google.com/workspace/gmail/api/guides/push
- https://www.unipile.com/gmail-api-push-notifications/
- https://cli.nylas.com/guides/gmail-push-notifications
- https://kb.torq.io/en/articles/9138324-receive-gmail-push-notifications-using-google-cloud-pub-sub
- https://googleapis.dev/java/google-api-services-gmail/latest/com/google/api/services/gmail/model/WatchResponse.html

---

## 3. Push-Notification-Payload (Pub/Sub Push an unseren Endpunkt)

Struktur (Snippet gesehen):
- HTTP POST mit JSON-Body. Gmail-Daten stehen in `message.data`, Base64-kodiert.
- Dekodiert ergibt sich: `{"emailAddress": "user@example.com", "historyId": "9876543210"}`.
- Weitere Felder der Pub/Sub-Hülle (`message.messageId`, `message.publishTime`, `subscription`) sind aus allgemeinem Pub/Sub-Wissen bekannt (abgeleitet).

Antwortverhalten (Snippet gesehen):
- Endpunkt muss mit HTTP 200 bis 299 innerhalb der Ack-Frist antworten (Standard 10 bis 600 Sekunden konfigurierbar). Andernfalls erneute Zustellung.

Wichtig (abgeleitet): Die `historyId` im Push ist nur ein Signal. Die Nachrichten selbst werden über `history.list` ab der **zuletzt gespeicherten** historyId ermittelt, nicht ab der aus dem Push. Duplikate der Zustellung sind zu erwarten, die Verarbeitung muss idempotent sein.

Authentifizierung des Push-Endpunkts (Snippet gesehen):
- Bei aktivierter Authentifizierung signiert Pub/Sub ein JWT (OIDC-ID-Token) und sendet es im `Authorization: Bearer`-Header.
- Prüfen: Signatur gegen Googles öffentliche Schlüssel, `aud` muss der in der Subscription konfigurierten Audience (Endpunkt-URL) entsprechen, `email` muss dem konfigurierten Dienstkonto entsprechen, `email_verified` muss true sein. Das Dienstkonto der Subscription braucht `iam.serviceAccounts.getOpenIdToken`.
- Public Keys: Googles JWKS/Zertifikat-Endpunkt (`https://www.googleapis.com/oauth2/v3/certs`) aus allgemeinem Wissen (abgeleitet, zu prüfen). Issuer `https://accounts.google.com` (abgeleitet).
- Zusätzlich ist ein eigener Token-Query-Parameter im Endpunkt-URL als zweite Hürde empfehlenswert (abgeleitet).

Quellen:
- https://developers.google.com/workspace/gmail/api/guides/push
- https://docs.cloud.google.com/pubsub/docs/authenticate-push-subscriptions
- https://docs.cloud.google.com/pubsub/docs/create-push-subscription
- https://www.unipile.com/gmail-api-push-notifications/

---

## 4. users.history.list (inkrementeller Abgleich)

Parameter (Snippet gesehen):
- `startHistoryId` (Pflicht für inkrementellen Sync).
- `labelId`: nur Änderungen an Nachrichten mit diesem Label.
- `historyTypes[]`: `messageAdded`, `messageDeleted`, `labelAdded`, `labelRemoved`.
- `pageToken`, `maxResults` (abgeleitet aus allgemeinem Muster, in Snippets nur `nextPageToken` erwähnt).

Verhalten (Snippet gesehen):
- Ungültige oder zu alte `startHistoryId` liefert **HTTP 404**. Dann ist ein Full Sync nötig (`messages.list` und neue historyId aus `getProfile`).
- historyId ist typischerweise mindestens eine Woche gültig, in seltenen Fällen nur wenige Stunden. History-Datensätze werden begrenzt aufbewahrt (Snippets nennen mindestens eine Woche, ein Drittanbieter nennt etwa 30 Tage).
- Ohne `nextPageToken` gibt es keine weiteren Einträge; die zurückgegebene `historyId` ist als neuer Stand zu speichern.
- Ein Drittanbieter beschreibt "Eventual Consistency": Nachrichten aus history.list können bei sofortigem `messages.get` noch 404 liefern (Snippet gesehen bei cli.nylas.com, Detailgrad gering). Konsequenz: Retry mit Backoff bei 404 auf einzelne Nachrichten (abgeleitet).

Quellen:
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.history/list
- https://developer.nylas.com/docs/cookbook/email/gmail-api-pagination-sync/
- https://cli.nylas.com/guides/gmail-api-eventual-consistency
- https://googleapis.github.io/google-api-python-client/docs/dyn/gmail_v1.users.history.html

---

## 5. Drafts (create, update, send) und Threading

Snippet gesehen:
- `drafts.create`: Body ist eine Draft-Ressource mit `message.raw` als **base64url**-kodierter RFC-2822-MIME-Nachricht.
- `drafts.update`: gleiche Struktur; die enthaltene Nachricht wird nicht editiert, sondern durch die neue MIME-Nachricht vollständig ersetzt.
- `drafts.send`: sendet den Draft; Body enthält die Draft-`id`.
- Threading: Um einen Draft oder eine Nachricht an einen Thread anzuhängen, `threadId` auf `message.threadId` setzen **und** die Header `In-Reply-To` sowie `References` gemäß RFC 2822 auf die `Message-ID` der Vorgängernachricht setzen. Der `Subject`-Header muss zum Thread passen (Snippet nennt "übereinstimmen", vermutlich inklusive `Re:`-Präfix, abgeleitet).
- Ein GitHub-Issue der Node-Clientbibliothek beschreibt, dass In-Reply-To/References im Raw gesetzt werden müssen und nicht als separate Felder (https://github.com/googleapis/google-api-nodejs-client/issues/1938, Snippet gesehen).

Konsequenz für das Modul (abgeleitet):
- Eigener MIME-Builder (multipart/alternative, ggf. multipart/mixed für Anhänge, UTF-8, quoted-printable oder base64, RFC-2047-kodierte Header).
- Eigene `Message-ID` erzeugen, `In-Reply-To`/`References` aus den per `format=metadata` gelesenen Headern `Message-ID` und `References` der Ursprungsnachricht aufbauen.
- Base64url ohne Padding: `rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')`.
- Max. Nachrichtengröße bei Upload etwa 35 MB per Media-Upload; für JSON-Body `raw` gelten kleinere Grenzen (abgeleitet, nicht im Snippet, zu prüfen).

Quellen:
- https://developers.google.com/workspace/gmail/api/guides/drafts
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.drafts/create
- https://googleapis.github.io/google-api-python-client/docs/dyn/gmail_v1.users.drafts.html
- https://github.com/googleapis/google-api-nodejs-client/issues/1938

---

## 6. messages.list und messages.get, Formate

`messages.get?format=` (Snippet gesehen):
- `minimal`: nur `id`, `threadId`, `labelIds`, kein Header, kein Body.
- `metadata`: `id`, Labels, Header; mit `metadataHeaders` (wiederholbarer Query-Parameter) auf bestimmte Header einschränkbar, z. B. `From`, `To`, `Subject`, `Date`, `Message-ID`, `References`, `In-Reply-To`.
- `full`: vollständig geparster `payload` (Parts, Body base64url), `raw` leer. Nicht mit Scope `gmail.metadata`.
- `raw`: komplette MIME-Nachricht base64url in `raw`, `payload` leer. Nicht mit Scope `gmail.metadata`.

`messages.list` (abgeleitet, Snippets enthielten nur Verweise): Parameter `q` (Gmail-Suchsyntax), `labelIds`, `maxResults`, `pageToken`, `includeSpamTrash`; Antwort enthält nur `id` und `threadId`, Details sind per `messages.get` nachzuladen. `threads.get` liefert alle Nachrichten eines Threads mit denselben Format-Optionen (Snippet gesehen, Link users.threads/get).

Empfehlung (abgeleitet): Für den Posteingangsspiegel `format=metadata` mit gezielten Headern (günstig), Body erst bei Bedarf per `format=full`; Anhänge über `messages.attachments.get` (abgeleitet).

Quellen:
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages/get
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/Format
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.threads/get
- https://googleapis.github.io/google-api-python-client/docs/dyn/gmail_v1.users.messages.html

---

## 7. sendAs-Aliase (users.settings.sendAs)

Snippet gesehen:
- `users.settings.sendAs.list` liefert die primäre Adresse und alle benutzerdefinierten From-Aliase.
- Felder: `sendAsEmail`, `displayName`, `replyToAddress`, `signature` (im Snippet nicht genannt, aus Ressourcenbeschreibung bekannt, abgeleitet), `treatAsAlias`, `verificationStatus` (`accepted`, `pending`; read-only), `isDefault`, `isPrimary` (abgeleitet).
- Nur Aliase mit `verificationStatus = accepted` sind zum Senden nutzbar.
- Beim Senden wird die Absenderadresse über den `From`-Header im Raw-MIME gewählt; sie muss einem akzeptierten sendAs-Eintrag entsprechen, sonst ersetzt Gmail den Absender (abgeleitet, zu prüfen).

Konsequenz für das Modul (abgeleitet): Aliase je Gesellschaft (Hausverwaltung Müller GmbH, Müller Holding AG) einlesen und nur akzeptierte Aliase im Entwurfsdialog anbieten. Verweis auf die Organisationsregel: keine Vermischung von Absendern.

Quellen:
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.settings.sendAs
- https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.settings.sendAs/list
- https://developers.google.com/workspace/gmail/api/guides/alias_and_signature_settings
- https://www.labnol.org/code/20295-gmail-api-email-aliases

---

## 8. Quota-Einheiten und Limits

Snippet gesehen (Achtung, zwei Regime):
- Bestandsregime (Projekte mit Gmail-API-Nutzung zwischen 11/2025 und 04/2026): 1.200.000 Einheiten pro Minute je Projekt, 15.000 Einheiten pro Minute je Nutzer. Ein Drittanbieter nennt zusätzlich 250 Einheiten pro Sekunde je Nutzer als praktische Grenze.
- Neues Regime seit 01.05.2026 für neue Cloud-Projekte: 1.200.000 pro Minute je Projekt, **6.000 pro Minute je Nutzer je Projekt**, geänderte Methodenkosten (z. B. `messages.get` 20 Einheiten statt 5).

Methodenkosten aus den Snippets (Bestandsregime):

| Methode | Einheiten | Status |
|---|---|---|
| messages.get | 5 (neu: 20) | Snippet gesehen |
| messages.send, drafts.send | 100 | Snippet gesehen |
| drafts.create | 10 | Snippet gesehen |
| drafts.update | 15 | Snippet gesehen |
| history.list | 2 | Snippet gesehen |
| getProfile | 1 | Snippet gesehen |
| labels.list | 1 | Snippet gesehen |
| messages.list | 5 | abgeleitet |
| users.watch | nicht in Snippets, vermutlich 100 | abgeleitet, zu prüfen |
| messages.modify | 5 | abgeleitet |
| messages.attachments.get | 5 | abgeleitet |

Fehlerbilder (Snippet gesehen): HTTP 429 mit `userRateLimitExceeded` bzw. `rateLimitExceeded`; Exponential Backoff empfohlen. Tägliches Sendelimit der Gmail-Konten (Workspace) gilt zusätzlich unabhängig von API-Quoten (Snippet bei hiverhq, Detailgrad gering).

Konsequenz für das Modul (abgeleitet): Eigener RateLimitManager aus dem Connector-Modul wiederverwenden; Kostenbudget pro Nutzer konservativ auf das neue Regime (6.000/Minute) auslegen, da das Projekt für mail.muellerhv.de neu angelegt wird.

Quellen:
- https://developers.google.com/workspace/gmail/api/reference/quota
- https://developers.google.com/workspace/gmail/release-notes
- https://cli.nylas.com/guides/gmail-api-quotas-2026
- https://www.unipile.com/gmail-api-limits/
- https://support.nylas.com/hc/en-us/articles/5210474048669-Gmail-Quota-exceeded-error-429-response

---

## 9. Workspace, interne App und Verifizierung

Snippet gesehen:
- OAuth-Consent-Screen mit User Type **Internal** ist möglich, wenn das Cloud-Projekt der Workspace-Organisation gehört und nur Nutzer dieser Organisation die App verwenden.
- Ausnahmen von der Verifizierungspflicht: Apps, die nur für interne Konten der eigenen Organisation konfiguriert sind; Apps im Testing-Modus; Apps mit Zugriff auf weniger als 100 Gmail-Konten. Restricted Scopes können mit bis zu 100 Konten ohne vollständige Verifizierung getestet werden.
- Für externe Apps mit restricted Scopes: jährliche Sicherheitsprüfung durch Dritte (Snippet nennt Größenordnung ab ca. 500 USD pro Jahr, Drittanbieterangabe).
- Workspace-Admin kann per "Control which apps access Google Workspace data" Apps als vertrauenswürdig einstufen bzw. restricted Scopes für Drittanbieter sperren.

Bewertung für die Hausverwaltung Müller GmbH (abgeleitet):
- Das Cloud-Projekt muss in der Workspace-Organisation von muellerhv.de angelegt werden, Consent Screen "Internal". Damit entfällt nach den Snippets die externe Verifizierung und die Sicherheitsprüfung. Die Aussage ist vor Go-Live im Google-Cloud-Konsolen-Text am Original zu prüfen.
- Alternative ohne Nutzer-Login: Service Account mit Domain-wide Delegation (Snippet erwähnt "Domain-Wide Installation"). Für ein Team-Postfach ist der Nutzer-OAuth-Flow mit Refresh-Token vermutlich einfacher; Refresh-Tokens interner Apps laufen im Gegensatz zu Testing-Modus-Apps (7 Tage) nicht automatisch ab (abgeleitet, zu prüfen).

Quellen:
- https://developers.google.com/identity/protocols/oauth2/production-readiness/restricted-scope-verification
- https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification
- https://support.google.com/cloud/answer/13463073
- https://support.google.com/a/answer/7281227

---

## 10. Offene Punkte, vor Implementierung am Original zu prüfen

1. Exakter Body von `users.stop` und Quota-Kosten von `users.watch`.
2. Feldnamen `labelFilterBehavior`, `isPrimary`, `signature` (nur aus allgemeinem Wissen, nicht in Snippets).
3. Verhalten bei `From`-Header, der keinem akzeptierten sendAs-Alias entspricht.
4. Größenlimits für `raw` im JSON-Body gegenüber Media-Upload.
5. Endgültige Quota-Regeln des neuen Projekts (Regime ab 01.05.2026).
6. JWKS-URL und Issuer der Pub/Sub-OIDC-Tokens; Umgang mit Key-Rotation (Cache mit Ablauf).
7. Ob `gmail.settings.basic` für `sendAs.list` überhaupt nötig ist oder `gmail.modify` genügt.

Alle Punkte sind in der Testsuite mit `Http::fake` abbildbar; ein Mock-Erfolg gilt nicht als Live-Test.
