@extends('layouts.admin', ['title' => 'API'])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ $docsUrl }}" target="_blank" rel="noopener">API-Dokumentation</a>
    <a class="hub-button" href="{{ route('admin.api.create') }}">API-Key anlegen</a>
@endsection
@section('content')
    @unless ($apiEnabled)
        <div class="hub-alert hub-alert-warning">Die API-Key-Authentifizierung ist deaktiviert (HUB_API_KEYS_ENABLED=false). Schlüssel können verwaltet werden, Anfragen an /api/v1 werden bis zur Aktivierung abgelehnt.</div>
    @endunless

    @if ($revealed !== null)
        <div class="hub-card" data-key-reveal>
            <h2>Schlüssel für {{ $revealed['name'] }}</h2>
            <p>Dieser Schlüssel wird nur jetzt angezeigt. Gespeichert wird ausschließlich sein SHA-256-Hash. Verwendung als <code class="hub-mono">Authorization: Bearer &lt;Schlüssel&gt;</code>.</p>
            <pre class="hub-mono hub-pre hub-break">{{ $revealed['key'] }}</pre>
        </div>
    @endif

    <div class="hub-card-grid hub-section">
        <div class="hub-card">
            <x-admin.key-value title="Rate-Limits" :items="$rateLimits" />
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Dokumentation" :items="[
                'API-Version' => (string) config('hub.api.version', 'v1'),
                'Dokumentation' => $docsUrl,
                'OpenAPI' => $openapiUrl,
                'Maximale Laufzeit eines Schlüssels' => $maxMonths.' Monate',
            ]" />
        </div>
    </div>

    <form method="get" action="{{ route('admin.api.index') }}" class="hub-form hub-card">
        <div class="hub-form-row">
            <div>
                <label for="f-state">Anzeige</label>
                <select id="f-state" name="state">
                    <option value="active" @selected($state === 'active')>Gültige und abgelaufene</option>
                    <option value="revoked" @selected($state === 'revoked')>Widerrufene</option>
                    <option value="all" @selected($state === 'all')>Alle</option>
                </select>
            </div>
        </div>
        <div class="hub-form-actions"><button type="submit" class="hub-button">Anzeigen</button></div>
    </form>

    <x-admin.data-table :rows="$keys" :columns="['Name', 'Prefix', 'Scopes', 'IP-Allowlist', 'Ablauf', 'Letzte Nutzung', 'Status', 'Angelegt von', '']" empty="Keine API-Keys vorhanden.">
        @foreach ($keys as $key)
            @php
                $expired = $key->expires_at !== null && $key->expires_at->isPast();
                $revoked = $key->revoked_at !== null;
            @endphp
            <tr>
                <td>{{ $key->name }}</td>
                <td><code class="hub-mono">{{ config('hub.security.api_keys.key_prefix', 'hub_live') }}_{{ $key->prefix }}_…</code></td>
                <td>@foreach ((array) $key->scopes as $scope)<code class="hub-mono">{{ $scope }}</code> @endforeach</td>
                <td>{{ is_array($key->allowed_ips) && $key->allowed_ips !== [] ? implode(', ', $key->allowed_ips) : 'alle' }}</td>
                <td>{{ GermanDate::formatDateTime($key->expires_at) ?? 'kein Ablauf' }}</td>
                <td>{{ GermanDate::formatDateTime($key->last_used_at) ?? 'noch nie' }}</td>
                <td>
                    @if ($revoked)
                        <x-admin.status-badge status="disabled" label="Widerrufen" />
                    @elseif ($expired)
                        <x-admin.status-badge status="warn" label="Abgelaufen" />
                    @else
                        <x-admin.status-badge status="ok" label="Gültig" />
                    @endif
                </td>
                <td>{{ $key->createdBy?->name ?? 'keine Angabe' }}</td>
                <td>
                    @unless ($revoked)
                        <x-admin.confirm-form :action="route('admin.api.revoke', ['key' => $key->getKey()])" label="Widerrufen" note-field="reason" description="Der Schlüssel wird dauerhaft ungültig. Ein Widerruf kann nicht rückgängig gemacht werden." />
                    @endunless
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
