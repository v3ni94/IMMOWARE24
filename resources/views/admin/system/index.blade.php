@extends('layouts.admin', ['title' => 'System'])
@php
    $level = static fn (string $status): string => match ($status) {
        'ok' => 'ok',
        'warn', 'degraded' => 'warn',
        'fail', 'down' => 'fail',
        default => 'unknown',
    };
@endphp
@section('content')
    <div class="hub-card-grid hub-section">
        <div class="hub-card">
            <x-admin.key-value title="Versionen" :items="$versions" />
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Umgebung" :items="[
                'APP_ENV' => $environment,
                'Queue-Verbindung' => $queueConnection.' ('.$queueDriver.')',
                'Queues' => implode(', ', array_values($queues)),
            ]" />
        </div>
        <div class="hub-card">
            <div class="hub-kv-title">Health-Übersicht</div>
            <p><x-admin.status-badge :status="$level($health['status'])" :label="'Gesamt: '.$health['status']" /></p>
            <dl class="hub-kv">
                @foreach ($health['checks'] as $name => $check)
                    <div class="hub-kv-row">
                        <dt>{{ $name }}</dt>
                        <dd><x-admin.status-badge :status="$level($check['status'])" :label="$check['status']" /></dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div class="hub-section">
        <h2>Feature-Flags</h2>
        <p class="hub-help">Aktive Schreibpfade sind als Warnung markiert. Hart gesperrte Flags dürfen nie true sein; ein true verhindert den Start der Anwendung (BootGuard).</p>
        <x-admin.data-table :rows="$flags" :columns="['Flag', 'Umgebungsvariable', 'Wert', 'Bewertung', 'Hinweis']">
            @foreach ($flags as $flag)
                <tr data-flag="{{ $flag['key'] }}">
                    <td><code class="hub-mono">{{ $flag['key'] }}</code></td>
                    <td><code class="hub-mono">{{ $flag['env'] }}</code></td>
                    <td><code class="hub-mono">{{ $flag['value'] ? 'true' : 'false' }}</code></td>
                    <td><x-admin.status-badge :status="$flag['level']" :label="match ($flag['level']) { 'ok' => 'OK', 'warn' => 'Schreibpfad aktiv', 'fail' => 'Verletzung', default => 'Unbekannt' }" /></td>
                    <td class="hub-small">{{ $flag['hint'] }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    </div>

    <div class="hub-section">
        <h2>hub:doctor</h2>
        <x-admin.data-table :rows="$doctor" :columns="['Prüfung', 'Status', 'Detail']" empty="Keine Ausgabe.">
            @foreach ($doctor as $row)
                <tr data-doctor="{{ $row['check'] }}">
                    <td><code class="hub-mono">{{ $row['check'] }}</code></td>
                    <td><x-admin.status-badge :status="$level($row['status'])" :label="strtoupper($row['status'])" /></td>
                    <td class="hub-small hub-break">{{ $row['detail'] }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    </div>

    <div class="hub-section">
        <h2>Health-Details</h2>
        <x-admin.json-view :data="$health['checks']" title="Prüfungen (database, queue, immoware)" />
    </div>

    <div class="hub-section">
        <h2>Zeitpläne</h2>
        <p class="hub-help">Konfigurierte Cron-Ausdrücke der Synchronisation (config/hub/sync.php, Zeitzone {{ (string) config('hub.sync.schedule.timezone', 'UTC') }}). Die vollständige, im Scheduler registrierte Liste liefert php artisan schedule:list.</p>
        <x-admin.data-table :rows="array_filter($configuredCrons, 'is_string')" :columns="['Zeitplan', 'Ausdruck']">
            @foreach ($configuredCrons as $key => $expression)
                @if (is_string($expression))
                    <tr><td>{{ $key }}</td><td><code class="hub-mono">{{ $expression }}</code></td></tr>
                @endif
            @endforeach
        </x-admin.data-table>
        @if ($schedule !== [])
            <x-admin.data-table :rows="$schedule" :columns="['Befehl', 'Ausdruck', 'Beschreibung']" caption="Im Prozess registrierte Zeitpläne">
                @foreach ($schedule as $row)
                    <tr><td><code class="hub-mono">{{ $row['command'] }}</code></td><td><code class="hub-mono">{{ $row['expression'] }}</code></td><td>{{ $row['description'] }}</td></tr>
                @endforeach
            </x-admin.data-table>
        @endif
    </div>
@endsection
