@extends('layouts.admin', ['title' => 'Sync-Lauf #'.$run->getKey()])

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.sync.runs', ['connection_id' => $run->getAttribute('connection_id'), 'entity_type' => $run->getAttribute('entity_type')]) }}">Zur Historie</a>
@endsection

@section('content')
<section class="hub-section">
    <x-admin.key-value title="Lauf" :items="[
        'Connection' => $run->connection?->getAttribute('name') ?? ('#'.$run->getAttribute('connection_id')),
        'Entität' => $run->getAttribute('entity_type'),
        'Typ' => $run->getAttribute('run_type'),
        'Modus' => $run->mode?->value,
        'Auslöser' => $run->getAttribute('trigger_source').($run->startedBy ? ' ('.$run->startedBy->getAttribute('name').')' : ''),
        'Status' => $run->status?->value,
        'Phase' => $run->getAttribute('phase'),
        'Gestartet' => $run->getAttribute('started_at'),
        'Beendet' => $run->getAttribute('finished_at'),
        'Dauer' => $run->getAttribute('duration_ms') !== null ? number_format((int) $run->getAttribute('duration_ms'), 0, ',', '.').' ms' : null,
        'Chunks' => $run->getAttribute('chunks'),
        'Health vor Lauf' => $run->getAttribute('health_ok_before'),
        'Correlation-ID' => $run->getAttribute('correlation_id'),
        'Fehler' => $run->getAttribute('error_summary'),
    ]" />
</section>

<section class="hub-section" aria-labelledby="run-counters">
    <h2 id="run-counters">Zähler</h2>
    <div class="hub-stat-grid">
        <x-admin.stat-card label="Verarbeitet" :value="(int) $counters['processed']" />
        <x-admin.stat-card label="Neu" :value="(int) $counters['created']" />
        <x-admin.stat-card label="Geändert" :value="(int) $counters['updated']" />
        <x-admin.stat-card label="Gelöscht" :value="(int) $counters['deleted']" />
        <x-admin.stat-card label="Fehler" :value="(int) $counters['failed']" :status="$counters['failed'] > 0 ? 'fail' : null" />
        <x-admin.stat-card label="Requests" :value="(int) $counters['requests']" />
    </div>
    @if ($run->getAttribute('cursor_before') || $run->getAttribute('cursor_after'))
        <div class="hub-card-grid">
            <x-admin.json-view :data="$run->getAttribute('cursor_before')" title="Cursor vor dem Lauf" />
            <x-admin.json-view :data="$run->getAttribute('cursor_after')" title="Cursor nach dem Lauf" />
        </div>
    @endif
</section>

<section class="hub-section" aria-labelledby="run-events">
    <h2 id="run-events">Ereignisse (sync_events)</h2>
    @if ($actionCounts !== [])
        <p class="hub-muted">@foreach ($actionCounts as $action => $total)<code class="hub-mono">{{ $action }}</code>: {{ number_format((int) $total, 0, ',', '.') }}@if (! $loop->last), @endif @endforeach</p>
    @endif
    <x-admin.data-table :columns="['Zeitpunkt', 'Entität', 'Datensatz', 'Aktion', 'Erkannt durch', 'Prüfsumme alt', 'Prüfsumme neu', 'Payload']" :rows="$events" empty="Keine Ereignisse in diesem Lauf.">
        @foreach ($events as $event)
            <tr>
                <td>{{ $event->getAttribute('occurred_at')?->format('d.m.Y H:i:s') }} UTC</td>
                <td><code class="hub-mono">{{ $event->getAttribute('entity_type') }}</code></td>
                <td>#{{ $event->getAttribute('entity_id') }}</td>
                <td>{{ $event->getAttribute('action') }}</td>
                <td>{{ $event->getAttribute('detected_by') }}</td>
                <td><code class="hub-mono">{{ $event->getAttribute('old_checksum') ? mb_substr((string) $event->getAttribute('old_checksum'), 0, 12).'…' : '' }}</code></td>
                <td><code class="hub-mono">{{ $event->getAttribute('new_checksum') ? mb_substr((string) $event->getAttribute('new_checksum'), 0, 12).'…' : '' }}</code></td>
                <td>{{ $event->getAttribute('payload_id') ? '#'.$event->getAttribute('payload_id') : '' }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>
@endsection
