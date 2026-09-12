@extends('layouts.admin', ['title' => 'Benutzer anlegen'])
@section('content')
    <div class="hub-card">
        <form method="post" action="{{ route('admin.users.store') }}" class="hub-form">
            @csrf
            <label for="u-name">Name</label>
            <input id="u-name" type="text" name="name" maxlength="120" value="{{ old('name') }}" required>
            <label for="u-email">E-Mail</label>
            <input id="u-email" type="email" name="email" maxlength="254" value="{{ old('email') }}" required>
            <label for="u-role">Rolle</label>
            <select id="u-role" name="role" required>
                @foreach ($assignable as $role)
                    <option value="{{ $role->value }}" @selected(old('role', 'read_only') === $role->value)>{{ $role->label() }}</option>
                @endforeach
            </select>
            <label for="u-password">Startpasswort (mindestens {{ (int) config('hub.security.login.password_min_length', 12) }} Zeichen, Buchstaben und Ziffern)</label>
            <input id="u-password" type="password" name="password" autocomplete="new-password" required>
            <label for="u-password2">Startpasswort wiederholen</label>
            <input id="u-password2" type="password" name="password_confirmation" autocomplete="new-password" required>
            <p class="hub-help">Die Zwei-Faktor-Authentifizierung richtet der Benutzer bei der ersten Anmeldung selbst ein. Das Startpasswort ist auf einem sicheren Weg zu übergeben, nicht per E-Mail im Klartext.</p>
            <div class="hub-form-actions">
                <button type="submit" class="hub-button">Anlegen</button>
                <a class="hub-button hub-button-link" href="{{ route('admin.users.index') }}">Abbrechen</a>
            </div>
        </form>
    </div>
@endsection
