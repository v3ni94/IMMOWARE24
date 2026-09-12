@extends('security::layouts.app', ['title' => 'Zwei-Faktor-Authentifizierung'])

@section('content')
<section class="hub-card">
    <h1>Zwei-Faktor-Authentifizierung</h1>

    @if ($confirmed)
        <p class="hub-status hub-status-ok">Die Zwei-Faktor-Authentifizierung ist aktiv.</p>
        <p>Verbleibende Wiederherstellungscodes: <strong>{{ $remainingCodes }}</strong></p>

        <form method="post" action="{{ route('security.two-factor.recovery-codes') }}" class="hub-inline-form">
            @csrf
            <button type="submit" class="hub-button hub-button-secondary">Neue Wiederherstellungscodes erzeugen</button>
        </form>

        <form method="post" action="{{ route('security.two-factor.disable') }}" class="hub-inline-form" onsubmit="return confirm('Zwei-Faktor-Authentifizierung wirklich deaktivieren?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="hub-button hub-button-danger">Deaktivieren</button>
        </form>
    @else
        <ol class="hub-steps">
            <li>Öffnen Sie Ihre Authenticator-App und fügen Sie ein neues Konto hinzu.</li>
            <li>Geben Sie den folgenden Schlüssel manuell ein oder verwenden Sie den Einrichtungslink.</li>
            <li>Bestätigen Sie die Einrichtung mit dem aktuell angezeigten Code.</li>
        </ol>

        <dl class="hub-definitions">
            <dt>Schlüssel (Base32)</dt>
            <dd><code class="hub-secret">{{ chunk_split($secret, 4, ' ') }}</code></dd>
            <dt>Einrichtungslink</dt>
            <dd><a href="{{ $otpauthUri }}" class="hub-break">{{ $otpauthUri }}</a></dd>
        </dl>

        <form method="post" action="{{ route('security.two-factor.confirm') }}" class="hub-form">
            @csrf
            <label for="code">Bestätigungscode</label>
            <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="8" autocomplete="one-time-code" required autofocus>
            <button type="submit" class="hub-button">Aktivieren</button>
        </form>
    @endif
</section>
@endsection
