@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; use App\Modules\MailUi\Support\CaseTypes; @endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('mail.inbox.index') }}">Zurück zur Inbox</a>
@endsection
@section('content')
    <div class="mail-case-head">
        <div>
            <x-mail.priority-badge :priority="$case->priority" /> <span class="mail-cause">{{ $case->priority_reason ?: 'Ursache: Regel oder Standard' }}</span>
            <span class="hub-badge">{{ CaseTypes::label($case->case_type) }}</span>
            <span class="hub-badge">{{ $case->mailbox?->label ?? 'ohne Postfach' }}{{ $case->legal_entity_code ? ', '.$case->legal_entity_code : '' }}</span>
            @if ($lock)
                <span class="hub-badge {{ $lockedByOther ? 'hub-badge-warn' : 'hub-badge-ok' }}" role="status"><span aria-hidden="true">✎</span> wird bearbeitet von {{ $lock->user?->name ?? 'Nutzer #'.$lock->user_id }} (bis {{ GermanDate::formatDateTime($lock->expires_at) }})</span>
            @endif
        </div>
        <x-mail.status-triple :processing="$case->status_processing" :communication="$case->status_communication" :business="$case->status_business" />
        <dl class="hub-kv mail-kv-inline">
            <div class="hub-kv-row"><dt>Verantwortlich</dt><dd>{{ $case->assignee?->name ?? 'offen' }}</dd></div>
            <div class="hub-kv-row"><dt>Nächster Schritt</dt><dd>{{ $case->next_step ?: 'fehlt' }}</dd></div>
            <div class="hub-kv-row"><dt>Fälligkeit</dt><dd class="{{ $case->due_at?->isPast() ? 'mail-overdue' : '' }}">{{ GermanDate::formatDateTime($case->due_at) ?? 'fehlt' }}</dd></div>
            <div class="hub-kv-row"><dt>Eingang</dt><dd>{{ GermanDate::formatDateTime($case->opened_at) }}</dd></div>
        </dl>
        @if ($missing !== [])
            <div class="hub-alert hub-alert-warning" role="status">Offener Vorgang unvollständig: {{ implode(', ', $missing) }} fehlt.</div>
        @endif
        @foreach ($clocks as $clock)
            <x-mail.sla-badge :color="$clock->color" :label="$clock->clock_type.' bis '.GermanDate::formatDateTime($clock->target_at)" :state="$clock->state" /> <span class="mail-cause">{{ $clock->cause_text ?: 'Ziel aus Regel' }}</span>
        @endforeach
    </div>

    <x-mail.three-pane listTitle="Originalthread" detailTitle="Bearbeitung" contextTitle="Kontext">
        <x-slot:list>
            @if ($messages->isEmpty())
                <p class="hub-muted">Keine Nachrichten verknüpft.</p>
            @endif
            @foreach ($messages as $message)
                <article class="mail-message {{ $message->direction === 'outbound' ? 'mail-message-out' : '' }}" id="m-{{ $message->getKey() }}">
                    <header>
                        <strong>{{ $message->from_name ?: $message->from_address }}</strong> <small class="hub-muted">&lt;{{ $message->from_address }}&gt;</small><br>
                        <small>{{ GermanDate::formatDateTime($message->received_at) }}, {{ $message->direction === 'outbound' ? 'ausgehend' : 'eingehend' }}{{ $message->is_read_in_gmail ? ', in Gmail gelesen (informativ)' : '' }}</small>
                    </header>
                    <h3 class="mail-message-subject">{{ $message->subject }}</h3>
                    @if ($message->body_html_sanitized)
                        <div class="mail-message-body">{!! $message->body_html_sanitized !!}</div>
                    @elseif ($message->body_text)
                        <pre class="mail-message-body hub-pre">{{ $message->body_text }}</pre>
                    @else
                        <p class="hub-muted">{{ $message->snippet ?: 'Inhalt nicht geladen.' }}</p>
                    @endif
                    @if ($attachments->has($message->getKey()))
                        <ul class="mail-attachments">
                            @foreach ($attachments->get($message->getKey()) as $attachment)
                                @php $scan = match ((string) $attachment->scan_status) { 'clean' => ['ok', '●', 'geprüft'], 'blocked' => ['fail', '■', 'blockiert'], 'skipped' => ['warn', '▲', 'nicht geprüft'], default => ['warn', '▲', 'Prüfung offen'] }; @endphp
                                <li><span class="hub-badge hub-badge-{{ $scan[0] }}"><span aria-hidden="true">{{ $scan[1] }}</span> {{ $scan[2] }}</span> {{ $attachment->filename }} <small class="hub-muted">{{ $attachment->mime_type }}, {{ number_format($attachment->size_bytes / 1024, 0, ',', '.') }} KB</small></li>
                            @endforeach
                        </ul>
                    @endif
                </article>
            @endforeach

            <section class="mail-notes" aria-labelledby="h-notes">
                <h3 id="h-notes">Interne Notizen (nie Teil einer Antwort)</h3>
                @forelse ($notes as $note)
                    <div class="mail-note"><small>{{ GermanDate::formatDateTime($note->changed_at) }}, {{ $note->changedBy?->name ?? 'System' }}</small><p>{{ $note->reason }}</p></div>
                @empty
                    <p class="hub-muted">Keine internen Notizen.</p>
                @endforelse
                <form method="post" action="{{ route('mail.cases.notes.store', $case) }}" class="hub-form">
                    @csrf
                    <label for="note">Neue interne Notiz</label>
                    <textarea id="note" name="note" rows="3" maxlength="2000" required></textarea>
                    <button type="submit" class="hub-button hub-button-secondary hub-button-small">Notiz speichern</button>
                </form>
            </section>
        </x-slot:list>

        <x-slot:detail>
            <section class="mail-section">
                <h3>Zusammenfassung</h3>
                @if ($case->ai_summary)
                    <p>{{ $case->ai_summary }}</p><p class="hub-hint">KI-Zusammenfassung, nur Vorschlag, nicht bestätigt.</p>
                @else
                    <p class="hub-muted">Keine Zusammenfassung vorhanden.</p>
                @endif
            </section>

            <section class="mail-section">
                <h3>Teilanliegen ({{ $case->items->count() }})</h3>
                @forelse ($case->items as $item)
                    <div class="mail-item">
                        <strong>{{ $item->position }}. {{ $item->title }}</strong> <x-mail.priority-badge :priority="$item->priority" />
                        <x-mail.status-triple :processing="$item->status_processing" :communication="$item->status_communication" :business="$item->status_business" class="mail-status-compact" />
                        <small class="hub-muted">{{ $item->assignee?->name ?? 'offen' }}, {{ $item->next_step ?: 'nächster Schritt fehlt' }}, fällig {{ GermanDate::formatDateTime($item->due_at) ?? 'fehlt' }}</small>
                    </div>
                @empty
                    <p class="hub-muted">Keine Teilanliegen.</p>
                @endforelse
            </section>

            <section class="mail-section">
                <h3>Aufgaben ({{ $case->tasks->count() }})</h3>
                <ul class="mail-list">
                    @forelse ($case->tasks as $task)
                        <li><span class="hub-badge">{{ \App\Modules\Cases\Enums\TaskStatus::tryFrom((string) $task->status)?->label() ?? $task->status }}</span> {{ $task->title }} <small class="hub-muted">{{ $task->assignee?->name ?? 'offen' }}, fällig {{ GermanDate::formatDateTime($task->due_at) ?? 'ohne' }}</small>
                            @if ($can['task'] && in_array((string) $task->status, ['open', 'in_progress', 'waiting'], true) && str_starts_with((string) $task->task_type, 'manual_change_'))
                                <form method="post" action="{{ route('mail.cases.tasks.confirm', ['case' => $case, 'task' => $task]) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button hub-button-secondary hub-button-small">Manuell erledigt bestätigen (Reauth)</button></form>
                            @endif
                        </li>
                    @empty
                        <li class="hub-muted">Keine Aufgaben.</li>
                    @endforelse
                </ul>
                @if ($can['task'])
                    <details class="hub-details"><summary>Interne Aufgabe anlegen</summary>
                        <form method="post" action="{{ route('mail.cases.tasks.store', $case) }}" class="hub-form">
                            @csrf
                            <label for="t-title">Titel</label><input id="t-title" name="title" type="text" maxlength="300" required>
                            <label for="t-instr">Anweisung</label><textarea id="t-instr" name="instructions" rows="2"></textarea>
                            <label for="t-assignee">Verantwortlich</label>
                            <select id="t-assignee" name="assignee_user_id"><option value="">offen</option>@foreach ($assignees as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                            <label for="t-due">Fällig (TT.MM.JJJJ HH:MM)</label><input id="t-due" name="due_at" type="text" placeholder="31.12.2026 16:30">
                            <button type="submit" class="hub-button hub-button-secondary">Aufgabe anlegen</button>
                        </form>
                    </details>
                @endif
            </section>

            <section class="mail-section">
                <h3>Aktionen und Freigaben ({{ count($plans) }})</h3>
                @forelse ($plans as $row)
                    @php $plan = $row['plan']; $version = $row['version']; @endphp
                    <article class="mail-plan">
                        <header>
                            <strong>Plan #{{ $plan->getKey() }}</strong> <span class="hub-badge">{{ $plan->target_system->label() }}</span>
                            <span class="hub-badge mail-risk mail-risk-{{ $plan->risk_class->value }}">Risiko {{ $plan->risk_class->label() }}</span>
                            <span class="hub-badge mail-status-badge mail-status-{{ $plan->status->value }}">{{ $plan->status->label() }}</span>
                        </header>
                        @if ($version)
                            <table class="hub-table mail-diff">
                                <caption>Vorschau Alt/Neu (Version {{ $version->version }}, Hash {{ substr((string) $version->steps_hash, 0, 12) }})</caption>
                                <thead><tr><th scope="col">Feld</th><th scope="col">Alt</th><th scope="col">Neu</th></tr></thead>
                                <tbody>
                                @foreach (array_unique(array_merge(array_keys($row['old']), array_keys($row['new']))) as $field)
                                    <tr><th scope="row">{{ $field }}</th><td class="mail-diff-old">{{ $row['old'][$field] ?? '' }}</td><td class="mail-diff-new">{{ $row['new'][$field] ?? '' }}</td></tr>
                                @endforeach
                                </tbody>
                            </table>
                            @if ($row['sensitive'])
                                @if ($row['revealed'])
                                    <p class="hub-hint">Bankdaten vollständig angezeigt, Zugriff protokolliert.</p>
                                @elseif ($can['bank'])
                                    <form method="post" action="{{ route('mail.approvals.reveal', $plan) }}" class="hub-inline-form">@csrf<input type="hidden" name="return" value="case"><button type="submit" class="hub-button hub-button-secondary hub-button-small">Volle IBAN anzeigen (wird protokolliert)</button></form>
                                @else
                                    <p class="hub-hint">Bankdaten maskiert. Vollständige Anzeige nur mit Recht mail.bank_data.view.</p>
                                @endif
                            @endif
                            <p class="hub-small">Freigaben: {{ $version->approvals->where('decision', 'approved')->count() }} von {{ max(1, (int) $version->required_approvals) }}, Identitätsnachweise: {{ $version->identityChecks->count() }}</p>
                            <ul class="mail-list mail-targets">
                                @foreach ($row['targets'] as $target)
                                    <li><span class="hub-badge mail-status-badge mail-status-{{ $target->status }}">{{ $target->status }}</span> Schritt {{ $target->step_index + 1 }}: {{ $target->target_system }}@if ($target->last_error), {{ $target->last_error }}@endif</li>
                                @endforeach
                            </ul>
                        @endif
                        @if ($plan->status === \App\Modules\Actions\Enums\ActionStatus::ApprovalRequired && $can['approve'])
                            <a class="hub-button hub-button-small" href="{{ route('mail.approvals.show', $plan) }}">Im Freigabecenter prüfen</a>
                        @endif
                    </article>
                @empty
                    <p class="hub-muted">Keine Aktionspläne.</p>
                @endforelse
            </section>

            <section class="mail-section" id="editor">
                <h3>Antworteditor</h3>
                @if (! $can['draft'])
                    <p class="hub-muted">Kein Entwurfsrecht für dieses Postfach.</p>
                @elseif ($case->mailbox_id === null)
                    <p class="hub-muted">Ohne Postfach kein Entwurf möglich.</p>
                @else
                    @if ($activeDraft)
                        <p><span class="hub-badge {{ $activeDraft->status === 'pending_approval' ? 'hub-badge-warn' : '' }}">Entwurf {{ $activeDraft->status === 'pending_approval' ? 'in Prüfung' : 'lokal' }}</span> <small class="hub-muted">zuletzt {{ GermanDate::formatDateTime($activeDraft->updated_at) }}</small></p>
                    @endif
                    <form method="post" action="{{ $activeDraft ? route('mail.cases.drafts.update', [$case, $activeDraft]) : route('mail.cases.drafts.store', $case) }}" class="hub-form">
                        @csrf
                        @if ($activeDraft)@method('PUT')@endif
                        <label for="d-alias">Absender (Alias des Postfachs)</label>
                        <select id="d-alias" name="alias_id">
                            <option value="">Postfachadresse {{ $case->mailbox?->email_address }}</option>
                            @foreach ($aliases as $alias)<option value="{{ $alias->getKey() }}" @selected($activeDraft?->alias_id === $alias->getKey())>{{ $alias->send_as_email }} ({{ $alias->legal_entity_code }})</option>@endforeach
                        </select>
                        <label for="d-to">An</label><input id="d-to" name="to" type="text" value="{{ old('to', $activeDraft ? implode(', ', (array) $activeDraft->to_json) : ($messages->last()?->reply_to ?? $messages->last()?->from_address ?? '')) }}" required>
                        <label for="d-cc">Kopie</label><input id="d-cc" name="cc" type="text" value="{{ old('cc', $activeDraft ? implode(', ', (array) $activeDraft->cc_json) : '') }}">
                        <label for="d-subject">Betreff</label><input id="d-subject" name="subject" type="text" maxlength="998" value="{{ old('subject', $activeDraft?->subject ?? ($messages->last()?->subject ? 'AW: '.$messages->last()->subject : '')) }}" required>
                        <label for="d-body">Text</label><textarea id="d-body" name="body_text" rows="10" required>{{ old('body_text', $activeDraft?->body_text) }}</textarea>
                        <div class="hub-form-actions">
                            <button type="submit" class="hub-button {{ $primaryAction['key'] === 'draft' ? 'mail-primary' : 'hub-button-secondary' }}">{{ $activeDraft ? 'Entwurf aktualisieren' : 'Entwurf anlegen' }}</button>
                        </div>
                    </form>
                    @if ($activeDraft)
                        <div class="hub-form-actions mail-draft-actions">
                            @if ($activeDraft->status === 'local')
                                <form method="post" action="{{ route('mail.cases.drafts.review', [$case, $activeDraft]) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button {{ $primaryAction['key'] === 'review_submit' ? 'mail-primary' : 'hub-button-secondary' }}">Zur Prüfung geben</button></form>
                            @endif
                            @if ($can['approve'] && in_array($activeDraft->status, ['pending_approval', 'pushed_to_gmail'], true) && (int) $activeDraft->created_by !== (int) auth()->id() && $activeDraft->approved_by === null)
                                <form method="post" action="{{ route('mail.cases.drafts.approve', [$case, $activeDraft]) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button hub-button-secondary">Entwurf freigeben (Vier-Augen, Reauth)</button></form>
                            @endif
                            @if ($activeDraft->approved_by !== null)
                                <span class="hub-badge hub-badge-ok">Freigegeben durch {{ $activeDraft->approvedBy?->name ?? 'Nutzer #'.$activeDraft->approved_by }}</span>
                            @elseif (! in_array($activeDraft->status, ['local'], true))
                                <span class="hub-hint">Versand erst nach Freigabe durch eine zweite Person.</span>
                            @endif
                            @if ($can['send'])
                                <form method="post" action="{{ route('mail.cases.drafts.send', [$case, $activeDraft]) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button {{ $primaryAction['key'] === 'send' ? 'mail-primary' : 'hub-button-secondary' }}">Senden (Reauth erforderlich)</button></form>
                            @else
                                <button type="button" class="hub-button hub-button-secondary" disabled aria-disabled="true" title="{{ $can['sendFlag'] ? 'Kein Versandrecht für dieses Postfach' : 'Versand gesperrt: MAIL_GMAIL_SEND_ENABLED=false' }}">Senden gesperrt</button>
                                <span class="hub-hint">{{ $can['sendFlag'] ? 'Kein Versandrecht (mail.send und Postfachrecht can_send).' : 'Versand gesperrt: Flag MAIL_GMAIL_SEND_ENABLED ist aus.' }}</span>
                            @endif
                        </div>
                    @endif
                    @if ($drafts->count() > ($activeDraft ? 1 : 0))
                        <details class="hub-details"><summary>Frühere Entwürfe</summary>
                            <ul class="mail-list">@foreach ($drafts as $draft)@if ($draft->getKey() !== $activeDraft?->getKey())<li><span class="hub-badge">{{ $draft->status }}</span> {{ $draft->subject }} <small class="hub-muted">{{ GermanDate::formatDateTime($draft->updated_at) }}</small></li>@endif @endforeach</ul>
                        </details>
                    @endif
                @endif
            </section>
        </x-slot:detail>

        <x-slot:context>
            <section class="mail-section">
                <h3>Hauptaktion</h3>
                <p><span class="hub-badge hub-badge-warn mail-primary-hint" data-mail-primary="{{ $primaryAction['key'] }}">Nächster Schritt: {{ $primaryAction['label'] }}</span></p>
            </section>

            <section class="mail-section" id="assign">
                <h3>Zuweisung und Status</h3>
                @if ($can['assign'])
                    <form method="post" action="{{ route('mail.cases.assign', $case) }}" class="hub-form">
                        @csrf
                        <label for="a-user">Verantwortlicher</label>
                        <select id="a-user" name="assignee_user_id" data-mail-assign-field><option value="">niemand</option>@foreach ($assignees as $u)<option value="{{ $u->getKey() }}" @selected($case->assignee_user_id === $u->getKey())>{{ $u->name }}</option>@endforeach</select>
                        <label for="a-next">Nächster Schritt</label><input id="a-next" name="next_step" type="text" maxlength="500" value="{{ $case->next_step }}">
                        <label for="a-due">Fälligkeit (TT.MM.JJJJ HH:MM)</label><input id="a-due" name="due_at" type="text" value="{{ GermanDate::formatDateTime($case->due_at) }}">
                        <button type="submit" class="hub-button {{ $primaryAction['key'] === 'assign' ? 'mail-primary' : 'hub-button-secondary' }}">Zuweisen</button>
                    </form>
                @else
                    <p class="hub-muted">Kein Zuweisungsrecht.</p>
                @endif
                @if ($can['category'])
                    <form method="post" action="{{ route('mail.cases.category', $case) }}" class="hub-form">
                        @csrf
                        <label for="c-type">Kategorie</label>
                        <select id="c-type" name="case_type">@foreach ($caseTypes as $key => $label)<option value="{{ $key }}" @selected($case->case_type === $key)>{{ $label }}</option>@endforeach</select>
                        <button type="submit" class="hub-button hub-button-secondary hub-button-small">Kategorie setzen</button>
                    </form>
                @endif
                @if ($transitions !== [])
                    <form method="post" action="{{ route('mail.cases.status', $case) }}" class="hub-form">
                        @csrf
                        <label for="s-status">Bearbeitungsstatus</label>
                        <select id="s-status" name="status" data-mail-status-field>@foreach ($transitions as $status)<option value="{{ $status->value }}">{{ $status->label() }}</option>@endforeach</select>
                        <label for="s-reason">Begründung</label><input id="s-reason" name="reason" type="text" maxlength="500">
                        <button type="submit" class="hub-button {{ $primaryAction['key'] === 'status' ? 'mail-primary' : 'hub-button-secondary' }} hub-button-small">Status setzen</button>
                        <p class="hub-hint">Gelöst und Geschlossen prüft das Modul Vorgänge (Abschlussbedingungen).</p>
                    </form>
                @endif
            </section>

            <section class="mail-section">
                <h3>Person und Objekt</h3>
                <dl class="hub-kv">
                    <div class="hub-kv-row"><dt>Kontakt</dt><dd>{{ $case->primaryContact ? ($case->primaryContact->name ?: trim(($case->primaryContact->first_name ?? '').' '.($case->primaryContact->last_name ?? ''))) : 'nicht zugeordnet' }}</dd></div>
                    <div class="hub-kv-row"><dt>Objekt</dt><dd>{{ $case->property?->name ?? 'nicht zugeordnet' }}</dd></div>
                    <div class="hub-kv-row"><dt>Einheit</dt><dd>{{ $case->unit?->unit_number ?? 'keine' }}</dd></div>
                    <div class="hub-kv-row"><dt>Vertrag</dt><dd>{{ $case->contract?->contract_number ?? 'keiner' }}</dd></div>
                </dl>
                <h4>Kandidaten (Quelle und Grund)</h4>
                @if ($candidates === [])
                    <p class="hub-muted">Keine Kandidaten. Zuordnung nur über Kennung oder Mensch, nie über Namen.</p>
                @else
                    <ul class="mail-list">
                        @foreach ($candidates as $candidate)
                            <li>
                                <strong>{{ $candidate['label'] }}</strong> <span class="hub-badge">{{ $candidate['type'] }}</span>
                                <small class="hub-muted">Quelle {{ $candidate['source'] }}: {{ $candidate['reason'] }}@if ($candidate['confidence'] !== null), {{ $candidate['confidence'] }} %@endif</small>
                                @if ($can['category'])
                                    <form method="post" action="{{ route('mail.cases.candidates.confirm', $case) }}" class="hub-inline-form">@csrf<input type="hidden" name="type" value="{{ $candidate['type'] }}"><input type="hidden" name="local_id" value="{{ $candidate['local_id'] }}"><button type="submit" class="hub-button hub-button-secondary hub-button-small">Bestätigen</button></form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="mail-section">
                <h3>Quelldokumente</h3>
                @forelse ($documents as $document)
                    <p><span class="hub-badge">{{ $document->source === 'drive' ? 'Google Drive' : ($document->source === 'immoware' ? 'Immoware24' : 'Anhang') }}</span>
                        @if ($document->web_view_link)<a href="{{ $document->web_view_link }}" rel="noopener noreferrer" target="_blank">{{ $document->name }}</a>@else{{ $document->name }}@endif
                        <small class="hub-muted">{{ $document->verified_at ? 'geprüft '.GermanDate::formatDateTime($document->verified_at) : 'nicht geprüft' }}</small></p>
                @empty
                    <p class="hub-muted">Keine Quelldokumente verknüpft.</p>
                @endforelse
                @foreach ($case->references as $reference)
                    <p class="hub-small"><span class="hub-badge">{{ $reference->target_system }}</span> {{ $reference->reference_type }} {{ $reference->external_id }}</p>
                @endforeach
            </section>

            @if ($paperless['state'] !== 'not_configured')
                <section class="mail-section">
                    <h3>Paperless zum Objekt</h3>
                    @if ($paperless['state'] === 'no_property')
                        <p class="hub-muted">Kein Objekt zugeordnet, daher keine Paperless-Suche.</p>
                    @elseif ($paperless['state'] === 'error')
                        <p class="hub-muted">Paperless derzeit nicht erreichbar.</p>
                    @else
                        @forelse ($paperless['documents'] as $doc)
                            <p><span class="hub-badge">{{ $doc['company'] ?? 'Paperless' }}</span>
                                <a href="{{ $paperless['base_url'] }}/documents/{{ (int) $doc['id'] }}/details" rel="noopener noreferrer" target="_blank">{{ $doc['title'] }}</a>
                                @if ($doc['created'])<small class="hub-muted">{{ GermanDate::format($doc['created']) }}</small>@endif</p>
                        @empty
                            <p class="hub-muted">Keine Dokumente zu diesem Objekt in Paperless.</p>
                        @endforelse
                        @if ($paperless['count'] > count($paperless['documents']))
                            <p class="hub-small hub-muted">{{ count($paperless['documents']) }} von {{ $paperless['count'] }} angezeigt.</p>
                        @endif
                    @endif
                </section>
            @endif

            <section class="mail-section">
                <h3>Historie</h3>
                <ul class="mail-history">
                    @forelse ($history->where('dimension', '!=', 'note') as $entry)
                        <li><small>{{ GermanDate::formatDateTime($entry->changed_at) }}</small> <strong>{{ $entry->dimension }}</strong>: {{ $entry->from_status ? $entry->from_status.' zu ' : '' }}{{ $entry->to_status }} <small class="hub-muted">{{ $entry->changedBy?->name ?? $entry->source }}{{ $entry->reason ? ', '.$entry->reason : '' }}</small></li>
                    @empty
                        <li class="hub-muted">Keine Einträge.</li>
                    @endforelse
                </ul>
            </section>
        </x-slot:context>
    </x-mail.three-pane>
@endsection
