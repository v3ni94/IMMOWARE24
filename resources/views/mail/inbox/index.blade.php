@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; use App\Modules\MailUi\Support\CaseTypes; @endphp
@section('content')
    <form method="get" action="{{ route('mail.inbox.index') }}" class="hub-form hub-card mail-filter">
        <div class="hub-form-row">
            <div><label for="f-q">Suche (Titel, Nummer)</label><input id="f-q" type="search" name="q" value="{{ $filter['q'] }}"></div>
            <div><label for="f-mailbox">Postfach</label>
                <select id="f-mailbox" name="mailbox"><option value="">Alle</option>
                    @foreach ($mailboxes as $mailbox)<option value="{{ $mailbox->getKey() }}" @selected($filter['mailbox'] === (int) $mailbox->getKey())>{{ $mailbox->label }}</option>@endforeach
                </select></div>
            <div><label for="f-status">Bearbeitung</label>
                <select id="f-status" name="status"><option value="open" @selected($filter['status'] === 'open')>Alle offenen</option><option value="all" @selected($filter['status'] === 'all')>Alle</option>
                    @foreach ($statuses as $status)<option value="{{ $status->value }}" @selected($filter['status'] === $status->value)>{{ $status->label() }}</option>@endforeach
                </select></div>
            <div><label for="f-priority">Priorität</label>
                <select id="f-priority" name="priority"><option value="">Alle</option>
                    @foreach ($priorities as $priority)<option value="{{ $priority->value }}" @selected($filter['priority'] === $priority->value)>{{ $priority->label() }}</option>@endforeach
                </select></div>
            <div><label for="f-type">Kategorie</label>
                <select id="f-type" name="type"><option value="">Alle</option>
                    @foreach ($caseTypes as $key => $label)<option value="{{ $key }}" @selected($filter['type'] === $key)>{{ $label }}</option>@endforeach
                </select></div>
            <div><label for="f-assignee">Verantwortlicher</label>
                <select id="f-assignee" name="assignee"><option value="">Alle</option><option value="me" @selected($filter['assignee'] === 'me')>Ich</option><option value="none" @selected($filter['assignee'] === 'none')>Niemand</option></select></div>
            <div><label for="f-sort">Sortierung</label>
                <select id="f-sort" name="sort">
                    @foreach (['due_at' => 'Fälligkeit', 'priority' => 'Priorität', 'opened_at' => 'Eingang', 'status_processing' => 'Status', 'title' => 'Betreff', 'case_type' => 'Kategorie'] as $key => $label)<option value="{{ $key }}" @selected($filter['sort'] === $key)>{{ $label }}</option>@endforeach
                </select></div>
            <div><label for="f-dir">Richtung</label><select id="f-dir" name="dir"><option value="asc" @selected($filter['dir'] === 'asc')>aufsteigend</option><option value="desc" @selected($filter['dir'] === 'desc')>absteigend</option></select></div>
        </div>
        <div class="hub-form-actions"><button type="submit" class="hub-button">Filtern</button><a class="hub-button hub-button-link" href="{{ route('mail.inbox.index') }}">Zurücksetzen</a></div>
    </form>

    <form method="post" action="{{ route('mail.inbox.bulk') }}" id="mail-bulk-form" data-mail-keynav>
        @csrf
        <x-admin.data-table :rows="$cases" :columns="['', 'Postfach', 'Absender', 'Betreff', 'Objekt', 'Kategorie', 'A Priorität', 'B Rückmeldung', 'Verantwortlicher', 'Status', 'Nächster Schritt', 'Fälligkeit']" empty="Keine Vorgänge für diese Auswahl.">
            @foreach ($cases as $case)
                @php
                    $clock = $clocks->get($case->getKey());
                    $overdue = $case->due_at !== null && $case->due_at->isPast();
                    $colorB = $clock?->color ?? ($overdue ? 'red' : ($case->status_communication->requiresAction() ? 'yellow' : 'green'));
                    $causeB = $clock?->cause_text ?? ($overdue ? 'Fälligkeit überschritten' : $case->status_communication->label());
                    $labelB = match ($colorB) { 'red' => 'Überfällig', 'yellow' => 'Bald fällig', default => 'Im Ziel' };
                @endphp
                <tr class="mail-row" data-mail-row data-mail-url="{{ route('mail.cases.show', $case) }}" tabindex="0">
                    <td><input type="checkbox" name="case_ids[]" value="{{ $case->getKey() }}" aria-label="Vorgang {{ $case->case_number }} auswählen" class="hub-checkbox"></td>
                    <td>{{ $case->mailbox?->label ?? 'ohne Postfach' }}</td>
                    <td>{{ $case->primaryContact?->name ?? ($case->primaryContact ? trim(($case->primaryContact->first_name ?? '').' '.($case->primaryContact->last_name ?? '')) : 'nicht zugeordnet') }}</td>
                    <td><a href="{{ route('mail.cases.show', $case) }}" data-mail-row-link><strong>{{ $case->case_number }}</strong> {{ $case->title }}</a></td>
                    <td>{{ $case->property?->name ?? 'kein Objekt' }}@if ($case->unit) , {{ $case->unit->unit_number }}@endif</td>
                    <td>{{ CaseTypes::label($case->case_type) }}</td>
                    <td><x-mail.priority-badge :priority="$case->priority" /><span class="mail-cause">{{ $case->priority_reason ?: 'Ursache: Regel oder Standard' }}</span></td>
                    <td><x-mail.sla-badge :color="$colorB" :label="$labelB" :state="$clock?->state ?? 'running'" /><span class="mail-cause">{{ $causeB }}</span></td>
                    <td>{{ $case->assignee?->name ?? 'offen' }}</td>
                    <td><x-mail.status-triple :processing="$case->status_processing" :communication="$case->status_communication" :business="$case->status_business" class="mail-status-compact" /></td>
                    <td>{{ $case->next_step ?: 'fehlt' }}</td>
                    <td class="{{ $overdue ? 'mail-overdue' : '' }}">{{ GermanDate::formatDateTime($case->due_at) ?? 'fehlt' }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>

        @if ($canAssign || $canTask)
            <fieldset class="hub-card mail-bulk">
                <legend>Sammelaktion für markierte Vorgänge (nur Zuordnung, Kategorie, interne Aufgabe)</legend>
                <div class="hub-form-row">
                    <div><label for="b-action">Aktion</label>
                        <select id="b-action" name="action">
                            @if ($canAssign)<option value="assign">Zuordnen (Verantwortlicher)</option><option value="category">Kategorie setzen</option>@endif
                            @if ($canTask)<option value="task">Interne Aufgabe anlegen</option>@endif
                        </select></div>
                    <div><label for="b-assignee">Verantwortlicher</label>
                        <select id="b-assignee" name="assignee_user_id"><option value="">unverändert oder niemand</option>
                            @foreach ($assignees as $assignee)<option value="{{ $assignee->getKey() }}">{{ $assignee->name }}</option>@endforeach
                        </select></div>
                    <div><label for="b-next">Nächster Schritt</label><input id="b-next" type="text" name="next_step" maxlength="500"></div>
                    <div><label for="b-due">Fälligkeit (TT.MM.JJJJ HH:MM)</label><input id="b-due" type="text" name="due_at" placeholder="31.12.2026 16:30"></div>
                    <div><label for="b-type">Kategorie</label>
                        <select id="b-type" name="case_type"><option value="">bitte wählen</option>@foreach ($caseTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                    <div><label for="b-task">Titel der internen Aufgabe</label><input id="b-task" type="text" name="task_title" maxlength="300"></div>
                </div>
                <div class="hub-form-actions"><button type="submit" class="hub-button">Sammelaktion ausführen</button><span class="hub-hint">Freigaben und Versand sind nie Sammelaktionen.</span></div>
            </fieldset>
        @endif
    </form>
@endsection
