@extends('layouts.admin', ['title' => 'Benutzer '.$user->name])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.users.index') }}">Zur Liste</a>
@endsection
@section('content')
    <div class="hub-card-grid">
        <div class="hub-card">
            <form method="post" action="{{ route('admin.users.update', ['user' => $user->getKey()]) }}" class="hub-form">
                @csrf
                @method('PUT')
                <label for="u-name">Name</label>
                <input id="u-name" type="text" name="name" maxlength="120" value="{{ old('name', $user->name) }}" required>
                <label for="u-email">E-Mail</label>
                <input id="u-email" type="email" value="{{ $user->email }}" disabled>
                <label for="u-role">Rolle</label>
                @if ($canChangeRole)
                    <select id="u-role" name="role">
                        @foreach ($assignable as $role)
                            <option value="{{ $role->value }}" @selected(old('role', $user->role->value) === $role->value)>{{ $role->label() }}</option>
                        @endforeach
                    </select>
                @else
                    <input id="u-role" type="text" value="{{ $user->role->label() }}" disabled>
                    <p class="hub-help">{{ $isSelf ? 'Die eigene Rolle kann nicht geändert werden.' : 'Diese Rolle darf von Ihrer Rolle nicht geändert werden (Vier-Augen-Prinzip, Rollen Owner und Administrator vergibt nur die Geschäftsführung).' }}</p>
                @endif
                @unless ($isSelf)
                    <label class="hub-checkbox"><input type="hidden" name="disabled" value="0"><input type="checkbox" name="disabled" value="1" @checked(old('disabled', $user->isDisabled() ? '1' : '0') === '1')> Konto deaktiviert (keine Anmeldung möglich)</label>
                @endunless
                <label for="u-password">Neues Passwort (leer lassen, um es beizubehalten)</label>
                <input id="u-password" type="password" name="password" autocomplete="new-password">
                <label for="u-password2">Neues Passwort wiederholen</label>
                <input id="u-password2" type="password" name="password_confirmation" autocomplete="new-password">
                <div class="hub-form-actions"><button type="submit" class="hub-button">Speichern</button></div>
            </form>
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Konto" :items="[
                'Rolle' => $user->role->label(),
                'Zustand' => $user->isDisabled() ? 'deaktiviert seit '.GermanDate::formatDateTime($user->disabled_at) : 'aktiv',
                'Gesperrt bis' => $user->isLocked() ? GermanDate::formatDateTime($user->locked_until) : null,
                'Fehlversuche' => (int) $user->failed_login_count,
                'Letzte Anmeldung' => GermanDate::formatDateTime($user->last_login_at),
                '2FA eingerichtet am' => GermanDate::formatDateTime($user->totp_confirmed_at),
                'Wiederherstellungscodes übrig' => $remainingRecoveryCodes,
                'Angelegt am' => GermanDate::formatDateTime($user->created_at),
            ]" />

            @if ($user->isLocked() || (int) $user->failed_login_count > 0)
                <form method="post" action="{{ route('admin.users.unlock', ['user' => $user->getKey()]) }}" class="hub-inline-form">
                    @csrf
                    <button type="submit" class="hub-button hub-button-secondary">Sperre aufheben</button>
                </form>
            @endif

            @if ($user->hasConfirmedTotp())
                <x-admin.confirm-form :action="route('admin.users.reset-two-factor', ['user' => $user->getKey()])" label="2FA zurücksetzen" note-field="reason" description="TOTP-Secret und Wiederherstellungscodes werden gelöscht. Der Benutzer muss die Zwei-Faktor-Authentifizierung bei der nächsten Anmeldung neu einrichten. Vorher die Identität des Benutzers auf einem zweiten Kanal prüfen." />
            @else
                <p class="hub-help">Zwei-Faktor-Authentifizierung ist nicht eingerichtet.</p>
            @endif
        </div>
    </div>
@endsection
