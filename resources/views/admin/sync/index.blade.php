@extends('layouts.admin', ['title' => 'Synchronisation'])

@php
    $fmt = static fn ($value) => $value instanceof \DateTimeInterface ? $value->format('d.m.Y H:i:s').' UTC' : null;
    $runBadge = static fn ($status) => match ($status) { 'succeeded' => 'ok', 'running', 'pending' => 'unknown', 'skipped' => 'disabled', default => 'fail' };
@endphp

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.sync.runs') }}">Historie aller Läufe</a>
@endsection

@section('content')
<p class="hub-muted hub-page-meta">Stand je Lese-Connection und Entität. Manuelle Läufe werden als Job in die Queue sync eingeplant (trigger_source manual); Full Syncs halten einen Lock je Connection und Entität.</p>

@if ($rows === [])
    <p class="hub-muted">Keine Lese-Connection mit DAV-Adapter vorhanden. Dateiimport und REST-API-Slot werden nicht über den Sync-Monitor gesteuert.</p>
@endif

@foreach ($rows as $row)
    @php($run = $row['last_run'])
    <section class="hub-section hub-card" aria-labelledby="sync-{{ $row['connection']->getKey() }}-{{ $row['entity']->value }}" data-sync-row="{{ $row['connection']->getKey() }}:{{ $row['entity']->value }}">
        <h2 id="sync-{{ $row['connection']->getKey() }}-{{ $row['entity']->value }}">
            <a href="{{ route('admin.connections.show', ['id' => $row['connection']->getKey()]) }}">{{ $row['connection']->getAttribute('name') }}</a>,
            {{ $row['entity']->label() }} <code class="hub-mono">{{ $row['entity']->value }}</code>
            @if ($row['running'])<x-admin.status-badge status="unknown" label="läuft" />@endif
            @if ($row['locked'])<x-admin.status-badge status="warn" label="Full-Sync-Lock gehalten" />@endif
            @if (! $row['runnable'])<x-admin.status-badge status="disabled" label="Connection pausiert" />@endif
        </h2>

        <div class="hub-stat-grid">
            <x-admin.stat-card label="Letzter erfolgreicher Lauf" :value="$row['last_success_at']?->format('d.m.Y H:i') ?? 'noch nie'" :hint="$row['age_label']" :status="$row['age']['stale'] ? 'warn' : 'ok'" />
            <x-admin.stat-card label="Datenalter" :value="$row['age_label']" :hint="'Schwelle '.number_format((int) ($row['age']['threshold_seconds'] / 60), 0, ',', '.').' Minuten'.($row['age']['stale_since'] ? ', veraltet seit '.\Carbon\CarbonImmutable::parse($row['age']['stale_since'])->format('d.m.Y H:i') : '')" :status="$row['age']['stale'] ? 'warn' : null" />
            <x-admin.stat-card label="Verarbeitet (letzter Lauf)" :value="(int) $row['counters']['processed']" />
            <x-admin.stat-card label="Neu" :value="(int) $row['counters']['created']" />
            <x-admin.stat-card label="Geändert" :value="(int) $row['counters']['updated']" />
            <x-admin.stat-card label="Gelöscht (deleted_at)" :value="(int) $row['counters']['deleted']" />
            <x-admin.stat-card label="Fehler" :value="(int) $row['counters']['failed']" :status="$row['counters']['failed'] > 0 ? 'fail' : null" />
        </div>

        @if ($row['age']['stale'])
            <div class="hub-alert hub-alert-warning">Bestand veraltet (stale). Der Spiegel weicht möglicherweise vom Stand in Immoware24 ab.</div>
        @endif

        <x-admin.key-value :items="[
            'Letzter Lauf' => $run !== null ? '#'.$run->getKey().' ('.$run->getAttribute('run_type').', '.($run->status?->value ?? '').')' : 'noch keiner',
            'Gestartet' => $run?->getAttribute('started_at'),
            'Dauer' => $run !== null && $run->getAttribute('duration_ms') !== null ? number_format((int) $run->getAttribute('duration_ms'), 0, ',', '.').' ms' : null,
            'Fehlermeldung' => $run?->getAttribute('error_summary'),
            'Strategie' => $row['age']['cursor_present'] ? 'Cursor vorhanden (inkrementell möglich)' : 'kein Cursor, nächster Lauf vollständig',
        ]" />
        @if ($run !== null)
            <p><a href="{{ route('admin.sync.runs.show', ['id' => $run->getKey()]) }}">Details zum letzten Lauf</a>, <a href="{{ route('admin.sync.runs', ['connection_id' => $row['connection']->getKey(), 'entity_type' => $row['entity']->value]) }}">Historie</a></p>
        @endif

        @if ($canRun)
            <div class="hub-form-actions">
                <form method="post" action="{{ route('admin.sync.start') }}" class="hub-inline-form">
                    @csrf
                    <input type="hidden" name="connection_id" value="{{ $row['connection']->getKey() }}">
                    <input type="hidden" name="entity_type" value="{{ $row['entity']->value }}">
                    <input type="hidden" name="mode" value="incremental">
                    <button type="submit" class="hub-button" @disabled(! $row['runnable'])>Incremental Sync starten</button>
                </form>
                <x-admin.confirm-form :action="route('admin.sync.start')" label="Full Sync starten" :danger="false" description="Vollständiger Abgleich aller Einträge der Freigabe. Läuft im Nachtfenster planmäßig, ein manueller Start belastet Immoware24 sofort. Wenn ein Full Sync läuft, wird der Start als übersprungen protokolliert.">
                    <input type="hidden" name="connection_id" value="{{ $row['connection']->getKey() }}">
                    <input type="hidden" name="entity_type" value="{{ $row['entity']->value }}">
                    <input type="hidden" name="mode" value="full">
                </x-admin.confirm-form>
            </div>

            <details class="hub-details">
                <summary>Bootstrap-Assistent (Erstimport in Stufen 1, 10, 100, 1000, alle)</summary>
                <p class="hub-help">Jede Stufe ist ein eigener Lauf (run_type bootstrap_accept). Stufen 1 bis 1000 laufen sofort mit genau einem Chunk, Stufe alle wird als Full Sync in die Queue eingeplant. Die nächste Stufe erst nach bestandener Vorstufe starten (Fehlerquote höchstens {{ number_format((float) config('hub.sync.bootstrap.max_error_rate', 0.05) * 100, 0, ',', '.') }} %).</p>
                <x-admin.data-table :columns="['Stufe', 'Lauf', 'Status', 'Verarbeitet', 'Fehler', 'Gestartet']" :rows="$row['bootstrap']" empty="Noch keine Bootstrap-Stufe ausgeführt.">
                    @foreach ($row['bootstrap'] as $stage)
                        @php($counters = (array) $stage['run']->getAttribute('counters'))
                        <tr>
                            <td>{{ $stage['stage'] }}</td>
                            <td><a href="{{ route('admin.sync.runs.show', ['id' => $stage['run']->getKey()]) }}">#{{ $stage['run']->getKey() }}</a></td>
                            <td><x-admin.status-badge :status="$stage['passed'] ? 'ok' : 'fail'" :label="$stage['passed'] ? 'bestanden' : ($stage['run']->status?->value ?? 'offen')" /></td>
                            <td>{{ number_format((int) ($counters['processed'] ?? 0), 0, ',', '.') }}</td>
                            <td>{{ number_format((int) ($counters['failed'] ?? 0), 0, ',', '.') }}</td>
                            <td>{{ $fmt($stage['run']->getAttribute('started_at')) }}</td>
                        </tr>
                    @endforeach
                </x-admin.data-table>
                <x-admin.confirm-form :action="route('admin.sync.bootstrap')" label="Bootstrap-Stufe starten" :danger="false" description="Die gewählte Stufe wird als eigener Lauf ausgeführt und protokolliert.">
                    <input type="hidden" name="connection_id" value="{{ $row['connection']->getKey() }}">
                    <input type="hidden" name="entity_type" value="{{ $row['entity']->value }}">
                    <label for="stage-{{ $row['connection']->getKey() }}-{{ $row['entity']->value }}">Stufe</label>
                    <select id="stage-{{ $row['connection']->getKey() }}-{{ $row['entity']->value }}" name="stage">
                        @foreach ($stages as $stage)
                            <option value="{{ $stage }}">{{ $stage }}</option>
                        @endforeach
                    </select>
                </x-admin.confirm-form>
            </details>
        @else
            <p class="hub-help">Läufe starten erfordert das Recht sync.run.</p>
        @endif
    </section>
@endforeach

@if ($connections->hasPages())
    <nav class="hub-pagination" aria-label="Seiten">
        @if (! $connections->onFirstPage())<a class="hub-pagination-link" href="{{ $connections->previousPageUrl() }}">Zurück</a>@endif
        <span class="hub-pagination-info">Seite {{ $connections->currentPage() }} von {{ $connections->lastPage() }}</span>
        @if ($connections->hasMorePages())<a class="hub-pagination-link" href="{{ $connections->nextPageUrl() }}">Weiter</a>@endif
    </nav>
@endif
@endsection
