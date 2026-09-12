@extends('layouts.mail', ['title' => $title])
@section('content')
    @include('mail::admin._tabs')
    <div class="hub-card-grid">
        @foreach ($teams as $team)
            <section class="hub-card" aria-label="Team {{ $team->name }}">
                <h2>{{ $team->name }}</h2>
                <p class="hub-small">Leitung: {{ $team->lead?->name ?? 'offen' }}, Eskalation: {{ $team->escalationUser?->name ?? 'offen' }}, Postfächer: {{ $team->mailboxes->pluck('label')->implode(', ') ?: 'keine' }}</p>
                <form method="post" action="{{ route('mail.admin.teams.update', $team) }}" class="hub-form">
                    @csrf @method('PUT')
                    <div class="hub-form-row">
                        <div><label for="t-name-{{ $team->getKey() }}">Name</label><input id="t-name-{{ $team->getKey() }}" name="name" type="text" value="{{ $team->name }}" required></div>
                        <div><label for="t-lead-{{ $team->getKey() }}">Teamleitung</label><select id="t-lead-{{ $team->getKey() }}" name="lead_user_id"><option value="">offen</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected($team->lead_user_id === $u->getKey())>{{ $u->name }}</option>@endforeach</select></div>
                        <div><label for="t-esc-{{ $team->getKey() }}">Eskalationsempfänger</label><select id="t-esc-{{ $team->getKey() }}" name="escalation_user_id"><option value="">offen</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected($team->escalation_user_id === $u->getKey())>{{ $u->name }}</option>@endforeach</select></div>
                    </div>
                    <button type="submit" class="hub-button hub-button-secondary hub-button-small">Team speichern</button>
                </form>
                <h3>Mitglieder</h3>
                <div class="hub-table-wrapper"><table class="hub-table">
                    <thead><tr><th scope="col">Nutzer</th><th scope="col">Team-Rolle</th><th scope="col">Gültig</th><th scope="col"></th></tr></thead>
                    <tbody>
                    @forelse ($team->members as $member)
                        <tr>
                            <td>{{ $member->user?->name ?? 'Nutzer #'.$member->user_id }} <small class="hub-muted">({{ $member->user?->role->label() }})</small></td>
                            <td>{{ $roleLabels[$member->team_role] ?? $member->team_role }}</td>
                            <td>{{ $member->active_from ? \App\Core\Support\GermanDate::format($member->active_from) : 'ab sofort' }} bis {{ $member->active_until ? \App\Core\Support\GermanDate::format($member->active_until) : 'unbefristet' }}</td>
                            <td><form method="post" action="{{ route('mail.admin.teams.members.destroy', [$team, $member]) }}" class="hub-inline-form">@csrf @method('DELETE')<button type="submit" class="hub-button hub-button-danger hub-button-small">Entfernen</button></form></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="hub-table-empty">Keine Mitglieder.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <form method="post" action="{{ route('mail.admin.teams.members.store', $team) }}" class="hub-form">
                    @csrf
                    <div class="hub-form-row">
                        <div><label for="m-user-{{ $team->getKey() }}">Nutzer</label><select id="m-user-{{ $team->getKey() }}" name="user_id" required>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select></div>
                        <div><label for="m-role-{{ $team->getKey() }}">Team-Rolle</label><select id="m-role-{{ $team->getKey() }}" name="team_role">@foreach ($roles as $role)<option value="{{ $role }}">{{ $roleLabels[$role] ?? $role }}</option>@endforeach</select></div>
                        <div><label for="m-from-{{ $team->getKey() }}">Gültig ab</label><input id="m-from-{{ $team->getKey() }}" name="active_from" type="date"></div>
                        <div><label for="m-until-{{ $team->getKey() }}">Gültig bis</label><input id="m-until-{{ $team->getKey() }}" name="active_until" type="date"></div>
                    </div>
                    <button type="submit" class="hub-button hub-button-small">Mitglied hinzufügen oder Rolle ändern</button>
                </form>
            </section>
        @endforeach

        <section class="hub-card">
            <h2>Neues Team</h2>
            <form method="post" action="{{ route('mail.admin.teams.store') }}" class="hub-form">
                @csrf
                <label for="n-name">Name</label><input id="n-name" name="name" type="text" required minlength="2" maxlength="120">
                <label for="n-lead">Teamleitung</label><select id="n-lead" name="lead_user_id"><option value="">offen</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                <label for="n-esc">Eskalationsempfänger</label><select id="n-esc" name="escalation_user_id"><option value="">offen</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                <button type="submit" class="hub-button">Team anlegen</button>
            </form>
        </section>
    </div>

    <section class="hub-card">
        <h2>Rechtematrix der Team-Rollen</h2>
        <p class="hub-hint">Ein Recht gilt nur, wenn Systemrolle und Team-Rolle es gewähren. Postfachinhalte zusätzlich nur mit Postfachrecht.</p>
        <div class="hub-table-wrapper"><table class="hub-table mail-matrix">
            <thead><tr><th scope="col">Recht</th>@foreach ($roles as $role)<th scope="col">{{ $roleLabels[$role] ?? $role }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($catalog as $permission)
                <tr><th scope="row"><code>{{ $permission }}</code></th>
                    @foreach ($roles as $role)
                        @php $granted = in_array($permission, (array) ($matrix[$role] ?? []), true); @endphp
                        <td class="{{ $granted ? 'mail-matrix-yes' : 'mail-matrix-no' }}"><span aria-hidden="true">{{ $granted ? '●' : '○' }}</span> {{ $granted ? 'ja' : 'nein' }}</td>
                    @endforeach
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
@endsection
