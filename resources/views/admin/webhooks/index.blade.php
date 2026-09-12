@extends('layouts.admin', ['title' => 'Webhooks'])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.webhooks.deliveries.index') }}">Zustellungen</a>
    <a class="hub-button hub-button-secondary" href="{{ route('admin.webhooks.dlq.index') }}">DLQ @if ($deadTotal > 0) ({{ $deadTotal }}) @endif</a>
    <a class="hub-button" href="{{ route('admin.webhooks.create') }}">Endpunkt anlegen</a>
@endsection
@section('content')
    @unless ($enabled)
        <div class="hub-alert hub-alert-warning">Ausgehende Webhooks sind deaktiviert (HUB_WEBHOOKS_ENABLED=false). Endpunkte können angelegt werden, Ereignisse werden erst nach Aktivierung zugestellt.</div>
    @endunless

    @if ($revealed !== null)
        <div class="hub-card" data-secret-reveal>
            <h2>Secret für {{ $revealed['name'] }}</h2>
            <p>Dieses Secret wird nur jetzt angezeigt und verschlüsselt gespeichert. Es dient dem Empfänger zur Prüfung der HMAC-SHA256-Signatur im Header {{ config('hub.webhooks.signature.header', 'X-Hub-Signature') }}.</p>
            <pre class="hub-mono hub-pre hub-break">{{ $revealed['secret'] }}</pre>
        </div>
    @endif

    <x-admin.data-table :rows="$endpoints" :columns="['Name', 'URL', 'Ereignisse', 'Status', 'Zugestellt', 'DLQ', 'Angelegt', '']" empty="Keine Webhook-Endpunkte registriert.">
        @foreach ($endpoints as $endpoint)
            <tr>
                <td>{{ $endpoint->name }}</td>
                <td class="hub-break"><code class="hub-mono">{{ $endpoint->url }}</code></td>
                <td>
                    @foreach ((array) $endpoint->events as $event)
                        <code class="hub-mono">{{ $event }}</code>
                    @endforeach
                </td>
                <td>
                    @if ($endpoint->active)
                        <x-admin.status-badge status="ok" label="Aktiv" />
                    @else
                        <x-admin.status-badge status="disabled" label="Deaktiviert" />
                    @endif
                </td>
                <td class="hub-num">{{ number_format((int) $endpoint->delivered_count, 0, ',', '.') }}</td>
                <td class="hub-num">{{ number_format((int) $endpoint->dead_count, 0, ',', '.') }}</td>
                <td>{{ GermanDate::formatDateTime($endpoint->created_at) }}</td>
                <td>
                    <a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.webhooks.edit', ['endpoint' => $endpoint->getKey()]) }}">Bearbeiten</a>
                    @if ($endpoint->active)
                        <x-admin.confirm-form :action="route('admin.webhooks.deactivate', ['endpoint' => $endpoint->getKey()])" label="Deaktivieren" note-field="reason" description="Der Endpunkt erhält keine Ereignisse mehr. Offene Zustellungen werden übersprungen." />
                    @else
                        <form method="post" action="{{ route('admin.webhooks.activate', ['endpoint' => $endpoint->getKey()]) }}" class="hub-inline-form">
                            @csrf
                            <button type="submit" class="hub-button hub-button-small hub-button-secondary">Aktivieren</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
