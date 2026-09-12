@extends('security::layouts.app', ['title' => 'Aktive Sitzungen'])

@section('content')
<section class="hub-card">
    <h1>Aktive Sitzungen</h1>

    @unless ($databaseDriver)
        <p class="hub-alert hub-alert-warning">Der Session-Treiber ist nicht auf database eingestellt. Die Sitzungsübersicht ist nur mit SESSION_DRIVER=database vollständig.</p>
    @endunless

    <div class="hub-table-wrapper">
        <table class="hub-table">
            <thead>
            <tr>
                <th>Letzte Aktivität</th>
                <th>IP-Adresse</th>
                <th>Gerät</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($sessions as $session)
                <tr>
                    <td>{{ $session['last_activity']->timezone('Europe/Berlin')->format('d.m.Y H:i') }}</td>
                    <td>{{ $session['ip_address'] ?? 'unbekannt' }}</td>
                    <td class="hub-break">{{ \Illuminate\Support\Str::limit($session['user_agent'] ?? 'unbekannt', 80) }}</td>
                    <td>{!! $session['is_current'] ? '<strong>Diese Sitzung</strong>' : 'aktiv' !!}</td>
                </tr>
            @empty
                <tr><td colspan="4">Keine Sitzungen vorhanden.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <form method="post" action="{{ route('security.sessions.destroy-others') }}" class="hub-inline-form">
        @csrf
        @method('DELETE')
        <button type="submit" class="hub-button hub-button-danger">Alle anderen Sitzungen beenden</button>
    </form>
</section>
@endsection
