@extends('layouts.admin', ['title' => 'Sync-Historie'])

@php
    $runBadge = static fn ($status) => match ($status) { 'succeeded' => 'ok', 'running', 'pending' => 'unknown', 'skipped' => 'disabled', default => 'fail' };
@endphp

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.sync.index') }}">Zum Sync-Monitor</a>
@endsection

@section('content')
<form method="get" action="{{ route('admin.sync.runs') }}" class="hub-form hub-form-row">
    <div>
        <label for="f-connection">Connection</label>
        <select id="f-connection" name="connection_id">
            <option value="">alle</option>
            @foreach ($connectionOptions as $id => $name)
                <option value="{{ $id }}" @selected($filters['connection_id'] === $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="f-entity">Entität</label>
        <select id="f-entity" name="entity_type">
            <option value="">alle</option>
            @foreach (\App\Modules\Sync\Enums\SyncEntity::cases() as $entity)
                <option value="{{ $entity->value }}" @selected($filters['entity_type'] === $entity->value)>{{ $entity->label() }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="f-status">Status</label>
        <select id="f-status" name="status">
            <option value="">alle</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label for="f-type">Typ</label>
        <select id="f-type" name="run_type">
            <option value="">alle</option>
            @foreach ($runTypes as $type)
                <option value="{{ $type }}" @selected($filters['run_type'] === $type)>{{ $type }}</option>
            @endforeach
        </select>
    </div>
    <div class="hub-form-actions">
        <button type="submit" class="hub-button hub-button-secondary">Filtern</button>
    </div>
</form>

<x-admin.data-table :columns="['Lauf', 'Connection', 'Entität', 'Typ', 'Auslöser', 'Status', 'Gestartet', 'Dauer', 'Verarbeitet', 'Neu', 'Geändert', 'Gelöscht', 'Fehler', 'Fehlermeldung']" :rows="$runs" empty="Keine Läufe für diese Auswahl.">
    @foreach ($runs as $run)
        @php($c = array_merge(['processed' => 0, 'created' => 0, 'updated' => 0, 'deleted' => 0, 'failed' => 0], (array) $run->getAttribute('counters')))
        <tr>
            <td><a href="{{ route('admin.sync.runs.show', ['id' => $run->getKey()]) }}">#{{ $run->getKey() }}</a></td>
            <td>{{ $connectionNames[$run->getAttribute('connection_id')] ?? $run->getAttribute('connection_id') }}</td>
            <td><code class="hub-mono">{{ $run->getAttribute('entity_type') }}</code></td>
            <td>{{ $run->getAttribute('run_type') }}</td>
            <td>{{ $run->getAttribute('trigger_source') }}</td>
            <td><x-admin.status-badge :status="$runBadge($run->status?->value)" :label="$run->status?->value" /></td>
            <td>{{ $run->getAttribute('started_at')?->format('d.m.Y H:i:s') }} UTC</td>
            <td>{{ $run->getAttribute('duration_ms') !== null ? number_format((int) $run->getAttribute('duration_ms'), 0, ',', '.').' ms' : 'läuft' }}</td>
            <td>{{ number_format((int) $c['processed'], 0, ',', '.') }}</td>
            <td>{{ number_format((int) $c['created'], 0, ',', '.') }}</td>
            <td>{{ number_format((int) $c['updated'], 0, ',', '.') }}</td>
            <td>{{ number_format((int) $c['deleted'], 0, ',', '.') }}</td>
            <td>{{ number_format((int) $c['failed'], 0, ',', '.') }}</td>
            <td class="hub-small">{{ \Illuminate\Support\Str::limit((string) $run->getAttribute('error_summary'), 120) }}</td>
        </tr>
    @endforeach
</x-admin.data-table>
@endsection
