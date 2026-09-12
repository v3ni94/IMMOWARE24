@extends('layouts.mail', ['title' => $title])
@section('content')
    @include('mail::admin._tabs')
    <x-admin.data-table :rows="$rows" :columns="['Objekt', 'Rolle', 'Team', 'Person', '']" empty="Keine Zuständigkeiten hinterlegt.">
        @foreach ($rows as $row)
            <tr>
                <td>{{ $row->property?->name ?? 'Objekt #'.$row->property_id }} <small class="hub-muted">{{ $row->property?->immoware_object_number }}</small></td>
                <td>{{ $row->role === 'primary' ? 'Hauptzuständigkeit' : 'Vertretung' }}</td>
                <td>{{ $row->team?->name ?? 'kein Team' }}</td>
                <td>{{ $row->user?->name ?? 'keine Person' }}</td>
                <td><form method="post" action="{{ route('mail.admin.responsibilities.destroy', $row) }}" class="hub-inline-form">@csrf @method('DELETE')<button type="submit" class="hub-button hub-button-danger hub-button-small">Entfernen</button></form></td>
            </tr>
        @endforeach
    </x-admin.data-table>
    <section class="hub-card">
        <h2>Zuständigkeit setzen</h2>
        @if ($properties->isEmpty())
            <p class="hub-muted">Keine Objekte im Spiegel (Immoware24-Sync noch nicht gelaufen).</p>
        @else
            <form method="post" action="{{ route('mail.admin.responsibilities.store') }}" class="hub-form">
                @csrf
                <div class="hub-form-row">
                    <div><label for="r-prop">Objekt</label><select id="r-prop" name="property_id" required>@foreach ($properties as $property)<option value="{{ $property->getKey() }}">{{ $property->name }}{{ $property->city ? ', '.$property->city : '' }}</option>@endforeach</select></div>
                    <div><label for="r-role">Rolle</label><select id="r-role" name="role"><option value="primary">Hauptzuständigkeit</option><option value="substitute">Vertretung</option></select></div>
                    <div><label for="r-team">Team</label><select id="r-team" name="team_id"><option value="">keins</option>@foreach ($teams as $team)<option value="{{ $team->getKey() }}">{{ $team->name }}</option>@endforeach</select></div>
                    <div><label for="r-user">Person</label><select id="r-user" name="user_id"><option value="">keine</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select></div>
                </div>
                <button type="submit" class="hub-button">Speichern</button>
            </form>
        @endif
    </section>
@endsection
