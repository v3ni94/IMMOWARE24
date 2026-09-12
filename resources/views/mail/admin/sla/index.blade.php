@extends('layouts.mail', ['title' => $title])
@section('content')
    @include('mail::admin._tabs')
    <p class="hub-hint">Zielzeiten sind Orientierung und mit der Geschäftsführung zu bestätigen. P0 rechnet in Kalenderzeit, P1 bis P3 in Arbeitszeit des Standardkalenders.</p>
    <x-admin.data-table :rows="$rules" :columns="['Priorität', 'Kategorie', 'Team', 'Uhr', 'Ziel (Minuten)', 'Gelb ab', 'Eskalation', 'Aktiv', '']" empty="Keine SLA-Regeln.">
        @foreach ($rules as $rule)
            <tr>
                <td><x-mail.priority-badge :priority="$rule->priority" /></td>
                <td>{{ $rule->case_type ? ($caseTypes[$rule->case_type] ?? $rule->case_type) : 'alle' }}</td>
                <td>{{ $rule->team?->name ?? 'alle' }}</td>
                <td>{{ $clocks[$rule->clock_type] ?? $rule->clock_type }}</td>
                <td>{{ number_format($rule->target_minutes, 0, ',', '.') }} {{ $rule->uses_calendar ? '(Arbeitszeit)' : '(Kalenderzeit)' }}</td>
                <td>{{ $rule->warn_percent }} %</td>
                <td>{{ $rule->escalate_after_minutes ? 'nach '.$rule->escalate_after_minutes.' min an '.($roleLabels[$rule->escalate_to_role] ?? $rule->escalate_to_role ?? 'Teamleitung') : 'keine' }}</td>
                <td><span class="hub-badge {{ $rule->active ? 'hub-badge-ok' : 'hub-badge-disabled' }}">{{ $rule->active ? 'aktiv' : 'inaktiv' }}</span></td>
                <td><form method="post" action="{{ route('mail.admin.sla.toggle', $rule) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button hub-button-secondary hub-button-small">{{ $rule->active ? 'Deaktivieren' : 'Aktivieren' }}</button></form></td>
            </tr>
        @endforeach
    </x-admin.data-table>
    <section class="hub-card">
        <h2>Regel anlegen oder ändern</h2>
        <form method="post" action="{{ route('mail.admin.sla.store') }}" class="hub-form">
            @csrf
            <div class="hub-form-row">
                <div><label for="sl-prio">Priorität</label><select id="sl-prio" name="priority">@foreach ($priorities as $p)<option value="{{ $p->value }}">{{ $p->label() }}</option>@endforeach</select></div>
                <div><label for="sl-type">Kategorie</label><select id="sl-type" name="case_type"><option value="">alle</option>@foreach ($caseTypes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="sl-team">Team</label><select id="sl-team" name="team_id"><option value="">alle</option>@foreach ($teams as $team)<option value="{{ $team->getKey() }}">{{ $team->name }}</option>@endforeach</select></div>
                <div><label for="sl-clock">Uhr</label><select id="sl-clock" name="clock_type">@foreach ($clocks as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach</select></div>
                <div><label for="sl-target">Ziel in Minuten</label><input id="sl-target" name="target_minutes" type="number" min="1" required></div>
                <div><label for="sl-warn">Gelb ab Prozent</label><input id="sl-warn" name="warn_percent" type="number" min="1" max="99" value="50"></div>
                <div><label for="sl-esc">Eskalation nach Minuten</label><input id="sl-esc" name="escalate_after_minutes" type="number" min="0"></div>
                <div><label for="sl-role">Eskalation an Rolle</label><select id="sl-role" name="escalate_to_role"><option value="">keine</option>@foreach ($roleLabels as $role => $label)<option value="{{ $role }}">{{ $label }}</option>@endforeach</select></div>
            </div>
            <button type="submit" class="hub-button">Regel speichern</button>
        </form>
    </section>
@endsection
