<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Immoware Hub' }}</title>
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
</head>
<body class="hub-body">
<header class="hub-header">
    <div class="hub-brand">Immoware Hub</div>
    @auth
        <nav class="hub-nav">
            <span class="hub-user">{{ auth()->user()->name }} ({{ auth()->user()->role->label() }})</span>
            <a href="{{ route('security.two-factor.setup') }}">Zwei-Faktor</a>
            <a href="{{ route('security.sessions.index') }}">Sitzungen</a>
            <form method="post" action="{{ route('security.logout') }}" class="hub-inline-form">
                @csrf
                <button type="submit" class="hub-button hub-button-secondary">Abmelden</button>
            </form>
        </nav>
    @endauth
</header>
<main class="hub-main">
    @if (session('status'))
        <div class="hub-alert hub-alert-info">{{ session('status') }}</div>
    @endif
    @if ($errors->any())
        <div class="hub-alert hub-alert-error">
            <ul>
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif
    {{ $slot ?? '' }}
    @yield('content')
</main>
<footer class="hub-footer">Hausverwaltung Müller GmbH, Immoware Hub</footer>
</body>
</html>
