@extends('security::layouts.app', ['title' => 'Erneute Bestätigung'])

@section('content')
<section class="hub-card hub-card-narrow">
    <h1>Erneute Bestätigung</h1>
    <p>Diese Aktion ist sicherheitskritisch. Bitte bestätigen Sie Ihre Identität mit Ihrem Passwort oder einem aktuellen Zwei-Faktor-Code. Die Bestätigung gilt {{ $minutes }} Minuten.</p>
    <form method="post" action="{{ route('security.confirm.store') }}" class="hub-form">
        @csrf
        <label for="password">Passwort</label>
        <input id="password" name="password" type="password" autocomplete="current-password" autofocus>
        <button type="submit" class="hub-button">Mit Passwort bestätigen</button>
    </form>
    <details class="hub-details">
        <summary>Stattdessen Zwei-Faktor-Code verwenden</summary>
        <form method="post" action="{{ route('security.confirm.store') }}" class="hub-form">
            @csrf
            <label for="code">Bestätigungscode</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="8" autocomplete="one-time-code">
            <button type="submit" class="hub-button hub-button-secondary">Mit Code bestätigen</button>
        </form>
    </details>
</section>
@endsection
