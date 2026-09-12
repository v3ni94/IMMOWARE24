<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.', '.$productName : $productName }}</title>
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
    <link rel="stylesheet" href="{{ asset('css/mail.css') }}">
</head>
<body class="hub-body mail-body" data-display-timezone="{{ $displayTimezone }}">
<a class="hub-skip" href="#hub-content">Zum Inhalt springen</a>
<header class="hub-header">
    <button type="button" class="hub-nav-toggle" data-hub-toggle="hub-sidebar" aria-controls="hub-sidebar" aria-expanded="false" aria-label="Navigation ein- oder ausblenden">Menü</button>
    <a class="hub-brand" href="{{ route('mail.dashboard') }}">{{ $productName }}</a>
    <span class="hub-env hub-env-{{ $environment }}" title="Umgebung">{{ $environment }}</span>
    @if ($environment !== 'production' || $sendLocked)
        <span class="mail-banner-locked" role="status">{{ $environment === 'production' ? 'Versand gesperrt (MAIL_GMAIL_SEND_ENABLED=false)' : 'Staging: Versand gesperrt' }}</span>
    @endif
    @auth
        <nav class="hub-nav" aria-label="Benutzer">
            <span class="hub-user">{{ auth()->user()->name }} <small>({{ auth()->user()->role->label() }})</small></span>
            <a href="{{ route('security.two-factor.setup') }}">Zwei-Faktor</a>
            <a href="{{ route('security.sessions.index') }}">Sitzungen</a>
            <form method="post" action="{{ route('security.logout') }}" class="hub-inline-form">
                @csrf
                <button type="submit" class="hub-button hub-button-secondary hub-button-small">Abmelden</button>
            </form>
        </nav>
    @endauth
</header>
<div class="hub-shell">
    <aside id="hub-sidebar" class="hub-sidebar">
        <nav class="hub-sidenav" aria-label="Bereiche">
            <ul>
                @foreach ($navigation as $item)
                    <li>
                        @if ($item['available'])
                            <a href="{{ $item['url'] }}" @class(['hub-sidenav-link', 'is-active' => $item['active']]) @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                        @else
                            <span class="hub-sidenav-link is-disabled" title="Bereich in Aufbau">{{ $item['label'] }} <small>in Aufbau</small></span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </nav>
        <details class="mail-shortcuts">
            <summary>Tastaturkürzel</summary>
            <dl>
                @foreach ($shortcuts as $key => $label)
                    <div><dt><kbd>{{ $key }}</kbd></dt><dd>{{ $label }}</dd></div>
                @endforeach
            </dl>
        </details>
    </aside>
    <main id="hub-content" class="hub-main mail-main">
        @if (isset($title))
            <div class="hub-page-header">
                <h1>{{ $title }}</h1>
                @isset($actions)
                    <div class="hub-page-actions">{{ $actions }}</div>
                @endisset
                @hasSection('actions')
                    <div class="hub-page-actions">@yield('actions')</div>
                @endif
            </div>
        @endif

        @foreach (['status' => 'success', 'success' => 'success', 'info' => 'info', 'warning' => 'warning', 'error' => 'error'] as $key => $level)
            @if (session($key))
                <div class="hub-alert hub-alert-{{ $level }}" role="status">{{ session($key) }}</div>
            @endif
        @endforeach
        @if ($errors->any())
            <div class="hub-alert hub-alert-error" role="alert">
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
</div>
<footer class="hub-footer">{{ $operatorName }}, {{ $productName }}. Zeiten in {{ $displayTimezone }}. Gelesen ist nicht bearbeitet, beantwortet ist nicht erledigt.</footer>
<dialog id="mail-shortcut-help" class="mail-help" aria-labelledby="mail-help-title">
    <h2 id="mail-help-title">Tastaturkürzel</h2>
    <dl>
        @foreach ($shortcuts as $key => $label)
            <div><dt><kbd>{{ $key }}</kbd></dt><dd>{{ $label }}</dd></div>
        @endforeach
        <div><dt><kbd>Esc</kbd></dt><dd>Hilfe schließen</dd></div>
    </dl>
    <form method="dialog"><button type="submit" class="hub-button hub-button-secondary">Schließen</button></form>
</dialog>
<script src="{{ asset('js/hub.js') }}" defer></script>
<script src="{{ asset('js/mail.js') }}" defer></script>
</body>
</html>
