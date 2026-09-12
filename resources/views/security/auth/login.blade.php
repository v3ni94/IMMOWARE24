@extends('security::layouts.app', ['title' => 'Anmeldung, Immoware Hub'])

@section('content')
<section class="hub-card hub-card-narrow">
    <h1>Anmeldung</h1>
    <form method="post" action="{{ route('security.login.store') }}" class="hub-form">
        @csrf
        <label for="email">E-Mail-Adresse</label>
        <input id="email" name="email" type="email" value="{{ old('email') }}" required autocomplete="username" autofocus>

        <label for="password">Passwort</label>
        <input id="password" name="password" type="password" required autocomplete="current-password">

        <label class="hub-checkbox">
            <input type="checkbox" name="remember" value="1"> Angemeldet bleiben
        </label>

        <button type="submit" class="hub-button">Anmelden</button>
    </form>
    <p class="hub-hint">Nach fünf Fehlversuchen wird das Konto vorübergehend gesperrt.</p>
</section>
@endsection
