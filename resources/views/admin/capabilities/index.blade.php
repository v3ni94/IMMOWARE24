@extends('layouts.admin', ['title' => 'Capabilities'])

@section('content')
<p class="hub-muted hub-page-meta">Fähigkeiten werden gemessen (Probe), nicht angenommen. Eine Fähigkeit ist nur aktivierbar, wenn ihr Belegstatus verifiziert oder getestet ist, das Config-Flag sie freigibt und sie nicht hart gesperrt ist. Diese Seite ist lesend.</p>

<form method="get" action="{{ route('admin.capabilities.index') }}" class="hub-form hub-form-row">
    <div>
        <label for="filter-connection">Connection</label>
        <select id="filter-connection" name="connection_id">
            <option value="">alle</option>
            @foreach ($connectionOptions as $id => $name)
                <option value="{{ $id }}" @selected($selectedConnection === $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="hub-form-actions">
        <button type="submit" class="hub-button hub-button-secondary">Filtern</button>
    </div>
</form>

@if ($connections->count() === 0)
    <p class="hub-muted">Keine Connection vorhanden. Capabilities entstehen mit der ersten Probe einer Connection.</p>
@endif

@foreach ($connections as $connection)
    <section class="hub-section" aria-labelledby="cap-{{ $connection->getKey() }}">
        <h2 id="cap-{{ $connection->getKey() }}"><a href="{{ route('admin.connections.show', ['id' => $connection->getKey()]) }}">{{ $connection->getAttribute('name') }}</a> <small class="hub-muted">({{ $connection->getAttribute('connector_type') }})</small></h2>
        <x-admin.data-table :columns="['Schlüssel', 'Status', 'Quelle', 'Zuletzt geprüft', 'Config-Flag', 'Aktivierbar', 'Verfügbar']" :rows="$tables[$connection->getKey()]">
            @foreach ($tables[$connection->getKey()] as $row)
                <tr data-capability="{{ $row['key'] }}" @class(['is-hard-locked' => $row['hard_locked']])>
                    <td>
                        <code class="hub-mono">{{ $row['key'] }}</code>
                        @if ($row['connector'])<div class="hub-small hub-muted">{{ $row['connector'] }}</div>@endif
                    </td>
                    <td>
                        <x-admin.status-badge :status="match ($row['status']) { 'verified', 'tested' => 'ok', 'documented' => 'warn', 'unavailable' => 'fail', null => 'unknown', default => 'unknown' }" :label="$row['status_label']" />
                    </td>
                    <td>
                        @if ($row['source'] === 'hard_locked')
                            <x-admin.status-badge status="fail" label="hard_locked" />
                        @elseif ($row['source'] === 'probe')
                            probe
                        @else
                            config
                        @endif
                    </td>
                    <td>
                        {{ $row['tested_at']?->format('d.m.Y H:i:s') ?? 'nie' }}@if ($row['tested_at']) UTC @endif
                        @if ($row['tested_by'])<div class="hub-small hub-muted">{{ $row['tested_by'] }}</div>@endif
                    </td>
                    <td>{{ $row['config_allowed'] ? 'freigegeben' : 'gesperrt' }}</td>
                    <td>{{ $row['activatable'] ? 'ja' : 'nein' }}</td>
                    <td>
                        @if ($row['hard_locked'])
                            <span class="hub-muted" title="{{ $row['reason'] }}">nie</span>
                        @else
                            {{ $row['available'] ? 'ja' : 'nein' }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.data-table>
    </section>
@endforeach

@if ($connections->hasPages())
    <nav class="hub-pagination" aria-label="Seiten">
        @if (! $connections->onFirstPage())<a class="hub-pagination-link" href="{{ $connections->previousPageUrl() }}">Zurück</a>@endif
        <span class="hub-pagination-info">Seite {{ $connections->currentPage() }} von {{ $connections->lastPage() }}</span>
        @if ($connections->hasMorePages())<a class="hub-pagination-link" href="{{ $connections->nextPageUrl() }}">Weiter</a>@endif
    </nav>
@endif

<section class="hub-section" aria-labelledby="cap-locks">
    <h2 id="cap-locks">Harte Sperren (nicht aufhebbar)</h2>
    <p class="hub-muted">Diese Fähigkeiten können unabhängig von Datenbank und Konfiguration nie aktiv werden. Ein true in den genannten Flags lässt die Anwendung beim Start mit Exception abbrechen (BootGuard).</p>
    <x-admin.data-table :columns="['Schlüssel', 'Begründung', 'Gesperrte Flags']" :rows="$hardLocks">
        @foreach ($hardLocks as $lock)
            <tr>
                <td><code class="hub-mono">{{ $lock['key'] }}</code></td>
                <td>{{ $lock['reason'] }}</td>
                <td>@foreach ($lock['flags'] as $flag)<code class="hub-mono hub-break">{{ $flag }}</code> @endforeach</td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>
@endsection
