@extends('layouts.admin', ['title' => 'Connection: '.$connection->getAttribute('name')])

@php
    $status = (string) $connection->getAttribute('status');
    $badge = match ($status) { 'active' => 'ok', 'degraded' => 'warn', 'error' => 'fail', default => 'disabled' };
    $credentials = is_array($connection->getAttribute('credentials')) ? $connection->getAttribute('credentials') : [];
    $fmt = static fn ($value) => $value instanceof \DateTimeInterface ? $value->format('d.m.Y H:i:s').' UTC' : null;
@endphp

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.connections.edit', ['id' => $connection->getKey()]) }}">Bearbeiten</a>
    <form method="post" action="{{ route('admin.connections.probe', ['id' => $connection->getKey()]) }}" class="hub-inline-form">
        @csrf
        <button type="submit" class="hub-button">Immoware-Schnittstelle prüfen</button>
    </form>
@endsection

@section('content')
<section class="hub-section">
    <x-admin.key-value title="Stammdaten" :items="[
        'Bezeichnung' => $connection->getAttribute('name'),
        'Connector-Typ' => ($connectorTypes[$connection->getAttribute('connector_type')] ?? $connection->getAttribute('connector_type')),
        'Zweck' => $connection->getAttribute('purpose') === 'write' ? 'Schreiben (create-only Posteingang)' : 'Lesen',
        'Freigabe-URL' => $connection->getAttribute('base_url'),
        'Technischer Nutzer' => $connection->technicalUser !== null ? $connection->technicalUser->getAttribute('label').' ('.$connection->technicalUser->getAttribute('username').')' : null,
        'Benutzername (Connection)' => $credentials['username'] ?? null,
        'Freigabe-Passwort' => isset($credentials['password']) ? 'hinterlegt (wird nie angezeigt)' : 'nicht hinterlegt',
        'Auth-Schema' => $connection->getAttribute('auth_scheme'),
        'Abrufintervall' => number_format((int) $connection->getAttribute('poll_interval_seconds'), 0, ',', '.').' Sekunden',
        'Rate-Limit' => number_format((float) $connection->getAttribute('rate_limit_rps'), 2, ',', '.').' Requests je Sekunde',
        'Angelegt' => $connection->getAttribute('created_at'),
        'Geändert' => $connection->getAttribute('updated_at'),
    ]" />
</section>

<section class="hub-section" aria-labelledby="conn-status">
    <h2 id="conn-status">Status und Schreibfreigabe</h2>
    <p><x-admin.status-badge :status="$badge" :label="$statusLabels[$status] ?? $status" /></p>
    <x-admin.key-value :items="[
        'Status' => $statusLabels[$status] ?? $status,
        'Degraded-Grund' => $connection->getAttribute('degraded_reason'),
        'Health' => $connection->getAttribute('last_health_ok') === null ? 'nicht geprüft' : ($connection->getAttribute('last_health_ok') ? 'erreichbar' : 'nicht erreichbar'),
        'Letzter Health-Check' => $connection->getAttribute('last_health_at'),
        'Schreibfreigabe' => $connection->getAttribute('write_enabled') ? 'freigegeben (Vier-Augen-Prinzip)' : 'gesperrt (Standard)',
        'Beantragt von' => $connection->writeEnabledBy?->getAttribute('name'),
        'Bestätigt von' => $connection->writeConfirmedBy?->getAttribute('name'),
        'Freigegeben am' => $connection->getAttribute('write_enabled_at'),
        'Erlaubter Schreibpfad' => $connection->getAttribute('allowed_write_prefix'),
        'Gekoppelte Lese-Connection' => $connection->pairedReadConnection?->getAttribute('name'),
    ]" />
    <p class="hub-help">Die Schreibfreigabe wird nicht über diese Seite gesetzt. Sie erfordert zwei Personen (Administrator beantragt, Geschäftsführung bestätigt) und ein hinterlegtes Freigabedokument.</p>

    <div class="hub-form-actions">
        @if ($status === 'paused')
            <x-admin.confirm-form :action="route('admin.connections.status', ['id' => $connection->getKey()])" label="Connection aktivieren" :danger="false" description="Die Connection nimmt am Zeitplan teil und ruft Daten von Immoware24 ab.">
                <input type="hidden" name="transition" value="activate">
            </x-admin.confirm-form>
        @else
            <x-admin.confirm-form :action="route('admin.connections.status', ['id' => $connection->getKey()])" label="Connection pausieren" description="Alle Jobs dieser Connection laufen leer, keine weiteren Abrufe." note-field="note">
                <input type="hidden" name="transition" value="pause">
            </x-admin.confirm-form>
        @endif
        @if ($status === 'degraded' && $canClearDegraded)
            <x-admin.confirm-form :action="route('admin.connections.status', ['id' => $connection->getKey()])" label="Degraded zurücksetzen" description="Setzt die Connection auf aktiv. Nur nach geprüfter Ursache (Server-Fingerprint, Ordnerstruktur)." note-field="note">
                <input type="hidden" name="transition" value="clear_degraded">
            </x-admin.confirm-form>
        @elseif ($status === 'degraded')
            <p class="hub-help">Degraded darf nur die Geschäftsführung (Owner) zurücksetzen.</p>
        @endif
    </div>
</section>

<section class="hub-section" aria-labelledby="conn-probe">
    <h2 id="conn-probe">Probe und Sync-Strategie</h2>
    @if ($probeChecks === [])
        <p class="hub-muted">Noch keine Probe ausgeführt. Die Probe prüft Auth-Challenge, OPTIONS, PROPFIND, ETag-Stabilität und REPORT sync-collection ohne Schreibzugriff.</p>
    @else
        <x-admin.key-value :items="[
            'Ergebnis' => ($probeResult['ok'] ?? false) ? '✓ bestanden' : '✗ nicht bestanden',
            'Ausgeführt' => $strategy['ran_at'],
            'Strategie (persistiert)' => $strategy['strategy'] ?? 'keine',
            'etag_stable' => $strategy['etag_stable'],
            'sync_token_supported' => $strategy['sync_token_supported'],
            'Server-Fingerprint' => $connection->getAttribute('server_fingerprint') ? mb_substr((string) $connection->getAttribute('server_fingerprint'), 0, 16).'…' : null,
            'Fingerprint geändert' => (bool) ($probeResult['fingerprint_changed'] ?? false),
        ]" />
        <ul class="hub-probe-list" style="list-style:none;padding:0;">
            @foreach ($probeChecks as $check)
                <li class="hub-mono" data-probe-check="{{ $check['key'] }}">
                    <span aria-label="{{ $check['status'] }}">{{ $check['symbol'] }}</span>
                    <strong>{{ $check['label'] }}</strong>
                    {{ $check['message'] }}@if ($check['latency_ms'] !== null) ({{ number_format($check['latency_ms'], 0, ',', '.') }} ms)@endif
                </li>
            @endforeach
        </ul>
        <p class="hub-help">Legende: ✓ bestanden, ✗ fehlgeschlagen, ⚠ übersprungen oder nicht unterstützt, ? unbekannt. Die ETag-Stabilität wird im Web-Request mit höchstens 5 Sekunden Wartezeit geprüft; die Prüfung mit konfigurierter Wartezeit liefert <code>php artisan hub:probe</code>.</p>
        @if (! empty($probeResult['facts']))
            <x-admin.json-view :data="$probeResult['facts']" title="Gemessene Serverfakten" />
        @endif
    @endif
    <p><a href="{{ route('admin.capabilities.index', ['connection_id' => $connection->getKey()]) }}">Capabilities dieser Connection</a></p>
</section>

<section class="hub-section" aria-labelledby="conn-runs">
    <h2 id="conn-runs">Letzte Sync-Läufe</h2>
    <x-admin.data-table :columns="['Lauf', 'Entität', 'Typ', 'Status', 'Gestartet', 'Dauer', 'Fehler']" :rows="$recentRuns" empty="Noch keine Läufe.">
        @foreach ($recentRuns as $run)
            <tr>
                <td><a href="{{ route('admin.sync.runs.show', ['id' => $run->getKey()]) }}">#{{ $run->getKey() }}</a></td>
                <td><code class="hub-mono">{{ $run->getAttribute('entity_type') }}</code></td>
                <td>{{ $run->getAttribute('run_type') }}</td>
                <td><x-admin.status-badge :status="match ($run->status?->value) { 'succeeded' => 'ok', 'running', 'pending' => 'unknown', 'skipped' => 'disabled', default => 'fail' }" :label="$run->status?->value" /></td>
                <td>{{ $fmt($run->getAttribute('started_at')) }}</td>
                <td>{{ $run->getAttribute('duration_ms') !== null ? number_format((int) $run->getAttribute('duration_ms'), 0, ',', '.').' ms' : 'läuft' }}</td>
                <td class="hub-small">{{ \Illuminate\Support\Str::limit((string) $run->getAttribute('error_summary'), 120) }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>
@endsection
