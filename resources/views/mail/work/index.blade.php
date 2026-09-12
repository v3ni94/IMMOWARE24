@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; @endphp
@section('content')
    <div class="hub-card-grid">
        <section class="hub-card" aria-labelledby="h-today">
            <h2 id="h-today">Heute fällig und überfällig ({{ $dueToday->count() }})</h2>
            @if ($dueToday->isEmpty())<p class="hub-muted">Nichts für heute fällig.</p>@else
                <ul class="mail-list" data-mail-keynav>
                    @foreach ($dueToday as $case)
                        <li data-mail-row data-mail-url="{{ route('mail.cases.show', $case) }}" tabindex="0"><x-mail.sla-badge :color="$case->due_at->isPast() ? 'red' : 'yellow'" :label="GermanDate::formatDateTime($case->due_at)" /> <a href="{{ route('mail.cases.show', $case) }}">{{ $case->case_number }} {{ $case->title }}</a> <small class="hub-muted">{{ $case->next_step ?: 'nächster Schritt fehlt' }}</small></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-tasks">
            <h2 id="h-tasks">Meine Aufgaben ({{ $tasks->count() }})</h2>
            @if ($tasks->isEmpty())<p class="hub-muted">Keine offenen Aufgaben.</p>@else
                <ul class="mail-list">
                    @foreach ($tasks as $task)
                        <li><span class="hub-badge">{{ \App\Modules\Cases\Enums\TaskStatus::tryFrom((string) $task->status)?->label() ?? $task->status }}</span> {{ $task->title }}
                            @if ($task->case)<a href="{{ route('mail.cases.show', $task->case) }}">{{ $task->case->case_number }}</a>@endif
                            <small class="hub-muted">fällig {{ GermanDate::formatDateTime($task->due_at) ?? 'ohne Datum' }}</small></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-mine">
            <h2 id="h-mine">Meine Vorgänge ({{ $myCases->count() }})</h2>
            @if ($myCases->isEmpty())<p class="hub-muted">Keine zugewiesenen offenen Vorgänge.</p>@else
                <ul class="mail-list">
                    @foreach ($myCases as $case)
                        <li><x-mail.priority-badge :priority="$case->priority" /> <a href="{{ route('mail.cases.show', $case) }}">{{ $case->case_number }} {{ $case->title }}</a> <small class="hub-muted">{{ $case->status_processing->label() }}, fällig {{ GermanDate::formatDateTime($case->due_at) ?? 'fehlt' }}</small></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-followups">
            <h2 id="h-followups">Wiedervorlagen und Fristen ({{ $followUps->count() }})</h2>
            @if ($followUps->isEmpty())<p class="hub-muted">Keine Wiedervorlagen.</p>@else
                <ul class="mail-list">
                    @foreach ($followUps as $deadline)
                        <li><span class="hub-badge {{ $deadline->kind === 'statutory_hint' ? 'hub-badge-warn' : '' }}">{{ $deadline->kind === 'statutory_hint' ? 'Frist (zu verifizieren)' : ($deadline->kind === 'external' ? 'Extern' : 'Intern') }}</span> {{ $deadline->label }} <small class="hub-muted">bis {{ GermanDate::formatDateTime($deadline->due_at) }}, Vorgang #{{ $deadline->case_id }}{{ $deadline->verified_at === null ? ', nicht verifiziert' : '' }}</small></li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-subst">
            <h2 id="h-subst">Vertretungen ({{ $substitutions->count() }})</h2>
            @if ($substitutions->isEmpty())<p class="hub-muted">Aktuell keine Vertretung.</p>@else
                <ul class="mail-list">
                    @foreach ($substitutions as $absence)
                        <li>Vertretung für <strong>{{ $absence->user?->name ?? 'Nutzer #'.$absence->user_id }}</strong> bis {{ GermanDate::formatDateTime($absence->ends_at) }}@if ($absence->reason) ({{ $absence->reason }})@endif</li>
                    @endforeach
                </ul>
                <h3>Vorgänge der vertretenen Personen</h3>
                <ul class="mail-list">
                    @forelse ($substituteCases as $case)
                        <li><x-mail.priority-badge :priority="$case->priority" /> <a href="{{ route('mail.cases.show', $case) }}">{{ $case->case_number }} {{ $case->title }}</a> <small class="hub-muted">{{ $case->assignee?->name }}, fällig {{ GermanDate::formatDateTime($case->due_at) ?? 'fehlt' }}</small></li>
                    @empty
                        <li class="hub-muted">Keine offenen Vorgänge.</li>
                    @endforelse
                </ul>
            @endif
        </section>
    </div>
@endsection
