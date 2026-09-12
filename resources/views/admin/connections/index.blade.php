@extends('layouts.admin', ['title' => 'Immoware-Verbindung'])

@section('actions')
    <a class="hub-button" href="{{ route('admin.connections.create') }}">Neue Connection</a>
@endsection

@section('content')
<p class="hub-muted hub-page-meta">Jede Connection startet pausiert und ohne Schreibfreigabe. Immoware24 bleibt führendes System.</p>

<form method="get" action="{{ route('admin.connections.index') }}" class="hub-form hub-form-row">
    <div>
        <label for="filter-status">Status</label>
        <select id="filter-status" name="status">
            <option value="">alle</option>
            @foreach ($statusLabels as $value => $label)
                <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="filter-type">Connector-Typ</label>
        <select id="filter-type" name="connector_type">
            <option value="">alle</option>
            @foreach ($connectorTypes as $value => $label)
                <option value="{{ $value }}" @selected($filters['connector_type'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="hub-form-actions">
        <button type="submit" class="hub-button hub-button-secondary">Filtern</button>
    </div>
</form>

<x-admin.data-table :columns="['Bezeichnung', 'Typ', 'Zweck', 'Technischer Nutzer', 'Status', 'Schreiben', 'Letzte Probe', 'Strategie']" :rows="$connections" empty="Noch keine Connection angelegt.">
    @foreach ($connections as $connection)
        @php($probe = (array) ($connection->getAttribute('probe_result') ?? []))
        <tr>
            <td><a href="{{ route('admin.connections.show', ['id' => $connection->getKey()]) }}">{{ $connection->getAttribute('name') }}</a></td>
            <td><code class="hub-mono">{{ $connection->getAttribute('connector_type') }}</code></td>
            <td>{{ $connection->getAttribute('purpose') === 'write' ? 'Schreiben' : 'Lesen' }}</td>
            <td>{{ $connection->technicalUser?->getAttribute('username') ?? 'keine Angabe' }}</td>
            <td>
                <x-admin.status-badge :status="match ($connection->getAttribute('status')) { 'active' => 'ok', 'degraded' => 'warn', 'error' => 'fail', default => 'disabled' }" :label="$statusLabels[$connection->getAttribute('status')] ?? $connection->getAttribute('status')" />
            </td>
            <td>
                @if ($connection->getAttribute('write_enabled'))
                    <x-admin.status-badge status="warn" label="freigegeben" />
                @else
                    <span class="hub-muted">gesperrt</span>
                @endif
            </td>
            <td>{{ $connection->getAttribute('last_probe_at')?->format('d.m.Y H:i') ?? 'keine Probe' }}</td>
            <td>{{ $probe['strategy'] ?? 'keine' }}</td>
        </tr>
    @endforeach
</x-admin.data-table>
@endsection
