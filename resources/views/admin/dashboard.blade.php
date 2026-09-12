@extends('layouts.admin', ['title' => 'Dashboard'])

@section('content')
<p class="hub-muted hub-page-meta">Stand {{ $generated_at->format('d.m.Y H:i:s') }} UTC. Alle Zahlen beziehen sich auf den Spiegel im Hub, führendes System ist Immoware24.</p>

<section class="hub-section" aria-labelledby="dash-connections">
    <h2 id="dash-connections">Immoware-Verbindung</h2>
    <x-admin.data-table :columns="['Verbindung', 'Typ', 'Zweck', 'Status', 'Health', 'Letzter erfolgreicher Sync', 'Letzte Probe']" :rows="$connections" empty="Noch keine Connection angelegt. Phase 0 (Zugang und Probe) ist nicht begonnen.">
        @foreach ($connections as $row)
            <tr>
                <td>
                    {{ $row['name'] }}
                    @if ($row['write_enabled'])
                        <x-admin.status-badge status="warn" label="Schreiben aktiv" />
                    @endif
                </td>
                <td><code class="hub-mono">{{ $row['connector_type'] }}</code></td>
                <td>{{ $row['purpose'] === 'write' ? 'Schreiben' : 'Lesen' }}</td>
                <td>
                    <x-admin.status-badge :status="$row['badge']" :label="$row['status']" />
                    @if ($row['degraded_reason'])
                        <div class="hub-muted hub-small">{{ $row['degraded_reason'] }}</div>
                    @endif
                </td>
                <td>
                    @if ($row['last_health_ok'] === null)
                        <x-admin.status-badge status="unknown" label="nicht geprüft" />
                    @else
                        <x-admin.status-badge :status="$row['last_health_ok'] ? 'ok' : 'fail'" :label="$row['last_health_ok'] ? 'erreichbar' : 'nicht erreichbar'" />
                        @if ($row['last_health_at'])
                            <div class="hub-muted hub-small">{{ $row['last_health_at']->format('d.m.Y H:i') }} UTC</div>
                        @endif
                    @endif
                </td>
                <td>{{ $row['last_success_at'] ? $row['last_success_at']->format('d.m.Y H:i:s').' UTC' : 'noch kein erfolgreicher Lauf' }}</td>
                <td>{{ $row['last_probe_at'] ? $row['last_probe_at']->format('d.m.Y H:i').' UTC' : 'keine Probe' }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>

<section class="hub-section" aria-labelledby="dash-counters">
    <h2 id="dash-counters">Spiegelbestand</h2>
    <div class="hub-stat-grid">
        <x-admin.stat-card label="Objekte" :value="$counters['properties']" data-counter="properties" />
        <x-admin.stat-card label="Einheiten" :value="$counters['units']" data-counter="units" />
        <x-admin.stat-card label="Kontakte" :value="$counters['contacts']" data-counter="contacts" />
        <x-admin.stat-card label="Verträge" :value="$counters['contracts']" data-counter="contracts" />
        <x-admin.stat-card label="Dokumente" :value="$counters['documents']" data-counter="documents" />
    </div>
</section>

<section class="hub-section" aria-labelledby="dash-issues">
    <h2 id="dash-issues">Offene Vorgänge und Betrieb</h2>
    <div class="hub-stat-grid">
        <x-admin.stat-card label="Offene Fehler (DLQ)" :value="$issues['dlq_open']" :status="$issues['dlq_open'] > 0 ? 'fail' : 'ok'" :href="\Route::has('admin.dlq.index') ? route('admin.dlq.index') : null" data-counter="dlq_open" />
        <x-admin.stat-card label="Offene Konflikte" :value="$issues['conflicts_open']" :status="$issues['conflicts_open'] > 0 ? 'warn' : 'ok'" :href="\Route::has('admin.conflicts.index') ? route('admin.conflicts.index') : null" data-counter="conflicts_open" />
        <x-admin.stat-card label="Queue-Tiefe" :value="(int) ($metrics['queue_depth'] ?? 0)" hint="Metrik queue_depth, Sync-Modul" data-counter="queue_depth" />
        <x-admin.stat-card label="Fehlgeschlagene Jobs" :value="(int) ($metrics['failed_jobs'] ?? 0)" :status="($metrics['failed_jobs'] ?? 0) > 0 ? 'warn' : null" data-counter="failed_jobs" />
        <x-admin.stat-card label="Sync-Fehler (kumuliert)" :value="(int) ($metrics['sync_errors'] ?? 0)" hint="Metrik sync_errors" />
        <x-admin.stat-card label="Rate-Limit-Antworten (429)" :value="(int) ($metrics['429_count'] ?? 0)" hint="Metrik 429_count" />
    </div>
</section>

<section class="hub-section" aria-labelledby="dash-stale">
    <h2 id="dash-stale">Datenalter</h2>
    @if ($stale === [])
        <p class="hub-muted">Keine veralteten Bestände auf aktiven Connections.</p>
    @else
        <div class="hub-alert hub-alert-warning">{{ count($stale) }} Bestand/Bestände gelten als veraltet (stale). Der Datenspiegel weicht möglicherweise vom Stand in Immoware24 ab.</div>
        <x-admin.data-table :columns="['Verbindung', 'Entität', 'Letzter Erfolg', 'Alter', 'Schwelle']" :rows="$stale">
            @foreach ($stale as $hint)
                <tr>
                    <td>{{ $hint['connection'] }}</td>
                    <td><code class="hub-mono">{{ $hint['entity_type'] }}</code></td>
                    <td>{{ $hint['last_success_at'] ? \Carbon\CarbonImmutable::parse($hint['last_success_at'])->format('d.m.Y H:i:s').' UTC' : 'noch nie' }}</td>
                    <td>{{ $hint['age_seconds'] !== null ? number_format((int) ($hint['age_seconds'] / 60), 0, ',', '.').' Minuten' : 'unbekannt' }}</td>
                    <td>{{ number_format((int) ($hint['threshold_seconds'] / 60), 0, ',', '.') }} Minuten</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    @endif
</section>

@if ($recent_failures !== [])
<section class="hub-section" aria-labelledby="dash-failures">
    <h2 id="dash-failures">Zuletzt fehlgeschlagene Läufe</h2>
    <x-admin.data-table :columns="['Lauf', 'Verbindung', 'Entität', 'Status', 'Gestartet', 'Fehler']" :rows="$recent_failures">
        @foreach ($recent_failures as $run)
            <tr>
                <td>#{{ $run->getKey() }}</td>
                <td>{{ $run->getAttribute('connection_id') }}</td>
                <td><code class="hub-mono">{{ $run->getAttribute('entity_type') }}</code></td>
                <td><x-admin.status-badge status="fail" :label="$run->status?->value ?? 'failed'" /></td>
                <td>{{ $run->started_at?->format('d.m.Y H:i:s') }} UTC</td>
                <td class="hub-small">{{ \Illuminate\Support\Str::limit((string) $run->getAttribute('error_summary'), 160) }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>
@endif
@endsection
