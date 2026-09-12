@extends('layouts.admin', ['title' => 'Benutzer'])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.roles.index') }}">Rollenmatrix</a>
    @if ($assignable->isNotEmpty())
        <a class="hub-button" href="{{ route('admin.users.create') }}">Benutzer anlegen</a>
    @endif
@endsection
@section('content')
    <form method="get" action="{{ route('admin.users.index') }}" class="hub-form hub-card">
        <div class="hub-form-row">
            <div>
                <label for="f-q">Suche (Name, E-Mail)</label>
                <input id="f-q" type="search" name="q" value="{{ $filter['q'] }}">
            </div>
            <div>
                <label for="f-role">Rolle</label>
                <select id="f-role" name="role">
                    <option value="">Alle</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected($filter['role'] === $role->value)>{{ $role->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-state">Zustand</label>
                <select id="f-state" name="state">
                    <option value="">Alle</option>
                    <option value="active" @selected($filter['state'] === 'active')>Aktiv</option>
                    <option value="disabled" @selected($filter['state'] === 'disabled')>Deaktiviert</option>
                    <option value="locked" @selected($filter['state'] === 'locked')>Gesperrt</option>
                </select>
            </div>
        </div>
        <div class="hub-form-actions">
            <button type="submit" class="hub-button">Filtern</button>
            <a class="hub-button hub-button-link" href="{{ route('admin.users.index') }}">Zurücksetzen</a>
        </div>
    </form>

    <x-admin.data-table :rows="$users" :columns="['Name', 'E-Mail', 'Rolle', 'Zustand', '2FA', 'Letzte Anmeldung', '']" empty="Keine Benutzer gefunden.">
        @foreach ($users as $user)
            <tr>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td>{{ $user->role->label() }}</td>
                <td>
                    @if ($user->isDisabled())
                        <x-admin.status-badge status="disabled" label="Deaktiviert" />
                    @elseif ($user->isLocked())
                        <x-admin.status-badge status="warn" :label="'Gesperrt bis '.GermanDate::formatDateTime($user->locked_until)" />
                    @else
                        <x-admin.status-badge status="ok" label="Aktiv" />
                    @endif
                </td>
                <td>
                    @if ($user->hasConfirmedTotp())
                        <x-admin.status-badge status="ok" label="Eingerichtet" />
                    @else
                        <x-admin.status-badge status="warn" label="Nicht eingerichtet" />
                    @endif
                </td>
                <td>{{ GermanDate::formatDateTime($user->last_login_at) ?? 'noch nie' }}</td>
                <td><a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.users.edit', ['user' => $user->getKey()]) }}">Bearbeiten</a></td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
