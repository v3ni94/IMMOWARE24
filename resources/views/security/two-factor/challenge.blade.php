@extends('security::layouts.app', ['title' => 'Zwei-Faktor-Bestätigung'])

@section('content')
<section class="hub-card hub-card-narrow">
    <h1>Zwei-Faktor-Bestätigung</h1>
    <p>Geben Sie den sechsstelligen Code aus Ihrer Authenticator-App ein.</p>
    <form method="post" action="{{ route('security.two-factor.verify') }}" class="hub-form">
        @csrf
        <label for="code">Bestätigungscode</label>
        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9 ]*" maxlength="8" autocomplete="one-time-code" autofocus>
        <button type="submit" class="hub-button">Bestätigen</button>
    </form>
    <details class="hub-details">
        <summary>Wiederherstellungscode verwenden</summary>
        <form method="post" action="{{ route('security.two-factor.verify') }}" class="hub-form">
            @csrf
            <label for="recovery_code">Wiederherstellungscode</label>
            <input id="recovery_code" name="recovery_code" type="text" maxlength="32" autocomplete="off">
            <button type="submit" class="hub-button hub-button-secondary">Mit Wiederherstellungscode anmelden</button>
        </form>
        <p class="hub-hint">Jeder Wiederherstellungscode ist nur einmal gültig.</p>
    </details>
</section>
@endsection
