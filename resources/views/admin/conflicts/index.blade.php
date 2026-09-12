@extends('layouts.admin', ['title' => 'Konflikte'])

@section('content')
<p class="hub-muted hub-page-meta">Konflikte entstehen, wenn Spiegel und Immoware24 voneinander abweichen (beide geändert, Schlüsselkollision). Immoware24 bleibt Master; eine Auflösung schreibt nie nach Immoware24.</p>

<form method="get" action="{{ route('admin.conflicts.index') }}" class="hub-form hub-form-row">
    <div>
        <label for="f-status">Status</label>
        <select id="f-status" name="status">
            <option value="open" @selected($filters['status'] === 'open')>offen (open, in_progress)</option>
            <option value="resolved" @selected($filters['status'] === 'resolved')>aufgelöst</option>
            <option value="all" @selected($filters['status'] === 'all')>alle</option>
        </select>
    </div>
    <div>
        <label for="f-entity">Entität</label>
        <input id="f-entity" type="text" name="entity_type" maxlength="40" value="{{ $filters['entity_type'] }}" placeholder="contact, document, ...">
    </div>
    <div>
        <label for="f-type">Konflikttyp</label>
        <input id="f-type" type="text" name="conflict_type" maxlength="40" value="{{ $filters['conflict_type'] }}">
    </div>
    <div>
        <label for="f-connection">Connection</label>
        <select id="f-connection" name="connection_id">
            <option value="">alle</option>
            @foreach ($connectionOptions as $id => $name)
                <option value="{{ $id }}" @selected($filters['connection_id'] === $id)>{{ $name }}</option>
            @endforeach
        </select>
    </div>
    <div class="hub-form-actions"><button type="submit" class="hub-button hub-button-secondary">Filtern</button></div>
</form>

<x-admin.data-table :columns="['#', 'Connection', 'Entität', 'Datensatz', 'Typ', 'Zustand', 'Status', 'Vorkommen', 'Zuletzt gesehen', 'Angelegt']" :rows="$conflicts" empty="Keine Konflikte für diese Auswahl.">
    @foreach ($conflicts as $conflict)
        <tr>
            <td><a href="{{ route('admin.conflicts.show', ['id' => $conflict->getKey()]) }}">#{{ $conflict->getKey() }}</a></td>
            <td>{{ $connectionNames[$conflict->getAttribute('connection_id')] ?? $conflict->getAttribute('connection_id') }}</td>
            <td><code class="hub-mono">{{ $conflict->getAttribute('entity_type') }}</code></td>
            <td>{{ $conflict->getAttribute('entity_id') !== null ? '#'.$conflict->getAttribute('entity_id') : 'kein Datensatz' }}</td>
            <td>{{ $conflict->getAttribute('conflict_type') }}</td>
            <td>{{ $conflict->conflict_state?->value ?? $conflict->getAttribute('conflict_state') }}</td>
            <td><x-admin.status-badge :status="in_array($conflict->getAttribute('status'), \App\Modules\Sync\Models\Conflict::OPEN_STATUSES, true) ? 'warn' : 'ok'" :label="$statusLabels[$conflict->getAttribute('status')] ?? $conflict->getAttribute('status')" /></td>
            <td>{{ number_format((int) $conflict->getAttribute('occurrences'), 0, ',', '.') }}</td>
            <td>{{ $conflict->getAttribute('last_seen_at')?->format('d.m.Y H:i') }}</td>
            <td>{{ $conflict->getAttribute('created_at')?->format('d.m.Y H:i') }}</td>
        </tr>
    @endforeach
</x-admin.data-table>
@endsection
