<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ isset($title) ? $title.', '.$productName : $productName }}</title>
    <link rel="stylesheet" href="{{ asset('css/hub.css') }}">
</head>
<body class="hub-body hub-admin">
<a class="hub-skip" href="#hub-content">Zum Inhalt springen</a>
<header class="hub-header">
    <button type="button" class="hub-nav-toggle" data-hub-toggle="hub-sidebar" aria-controls="hub-sidebar" aria-expanded="false" aria-label="Navigation ein- oder ausblenden">Menü</button>
    <a class="hub-brand" href="{{ route('admin.dashboard') }}">{{ $productName }}</a>
    <span class="hub-env hub-env-{{ $environment }}" title="Umgebung">{{ $environment }}</span>
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
    </aside>
    <main id="hub-content" class="hub-main">
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
<footer class="hub-footer">{{ $operatorName }}, {{ $productName }}. Immoware24 ist führendes System, der Hub ist Spiegel.</footer>
<script src="{{ asset('js/hub.js') }}" defer></script>
</body>
</html>
