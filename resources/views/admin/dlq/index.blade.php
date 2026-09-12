@extends('layouts.admin', ['title' => 'Fehlerqueue'])

@section('content')
<p class="hub-muted hub-page-meta">Endgültig gescheiterte Jobs und einzelne fehlgeschlagene Datensätze. Payloads sind maskiert gespeichert. Eine Wiederaufnahme verarbeitet nur den gewählten Eintrag.</p>

<form method="get" action="{{ route('admin.dlq.index') }}" class="hub-form hub-form-row">
    <div>
        <label for="f-status">Status</label>
        <select id="f-status" name="status">
            <option value="all" @selected(! in_array($filters['status'], $statuses, true))>alle</option>
            @foreach ($statuses as $status)
                <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $statusLabels[$status] ?? $status }}</option>
            @endforeach
        </select>
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
        <label for="f-job">Job-Klasse enthält</label>
        <input id="f-job" type="text" name="job_class" maxlength="120" value="{{ $filters['job_class'] }}">
    </div>
    <div class="hub-form-actions"><button type="submit" class="hub-button hub-button-secondary">Filtern</button></div>
</form>

<x-admin.data-table :columns="['#', 'Fehlgeschlagen', 'Job', 'Connection', 'Entität', 'Queue', 'Status', 'Versuche', 'Fehler']" :rows="$items" empty="Keine Einträge für diese Auswahl.">
    @foreach ($items as $item)
        <tr>
            <td><a href="{{ route('admin.dlq.show', ['id' => $item->getKey()]) }}">#{{ $item->getKey() }}</a></td>
            <td>{{ $item->getAttribute('failed_at')?->format('d.m.Y H:i:s') }} UTC</td>
            <td><code class="hub-mono">{{ class_basename((string) $item->getAttribute('job_class')) }}</code></td>
            <td>{{ $item->getAttribute('connection_id') !== null ? ($connectionNames[$item->getAttribute('connection_id')] ?? $item->getAttribute('connection_id')) : 'keine' }}</td>
            <td><code class="hub-mono">{{ $item->getAttribute('entity_type') }}</code></td>
            <td>{{ $item->getAttribute('queue') }}</td>
            <td><x-admin.status-badge :status="match ($item->status?->value) { 'open', 'failed' => 'fail', 'retrying' => 'warn', 'replayed' => 'ok', default => 'disabled' }" :label="$statusLabels[$item->status?->value] ?? $item->status?->value" /></td>
            <td>{{ (int) $item->getAttribute('attempts') }}</td>
            <td class="hub-small">{{ \Illuminate\Support\Str::limit(strtok((string) $item->getAttribute('exception'), "\n") ?: '', 140) }}</td>
        </tr>
    @endforeach
</x-admin.data-table>
@endsection
