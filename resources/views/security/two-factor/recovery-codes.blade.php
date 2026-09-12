@extends('security::layouts.app', ['title' => 'Wiederherstellungscodes'])

@section('content')
<section class="hub-card">
    <h1>Wiederherstellungscodes</h1>
    <p class="hub-alert hub-alert-warning">Diese Codes werden nur jetzt angezeigt. Bewahren Sie sie sicher auf. Jeder Code ist genau einmal verwendbar.</p>
    <ul class="hub-code-list">
        @foreach ($codes as $code)
            <li><code>{{ $code }}</code></li>
        @endforeach
    </ul>
    <a class="hub-button" href="{{ route('security.two-factor.setup') }}">Weiter</a>
</section>
@endsection
