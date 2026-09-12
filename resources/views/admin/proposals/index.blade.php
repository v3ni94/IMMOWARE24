@extends('layouts.admin', ['title' => 'Änderungsvorschläge'])

@section('content')
<p class="hub-muted hub-page-meta">Der Hub schreibt keine Stammdaten nach Immoware24. Änderungswünsche werden hier erfasst, manuell in Immoware24 umgesetzt, als übertragen markiert und durch den nächsten Sync bestätigt.</p>

<form method="get" action="{{ route('admin.proposals.index') }}" class="hub-form hub-form-row">
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
        <label for="f-entity">Entität</label>
        <input id="f-entity" type="text" name="entity_type" maxlength="40" value="{{ $filters['entity_type'] }}">
    </div>
    <div class="hub-form-actions"><button type="submit" class="hub-button hub-button-secondary">Filtern</button></div>
</form>

<x-admin.data-table :columns="['#', 'Entität', 'Datensatz', 'Feld', 'Alt', 'Neu', 'Status', 'Beantragt von', 'Angelegt', 'Übertragen']" :rows="$proposals" empty="Keine Änderungsvorschläge für diese Auswahl.">
    @foreach ($proposals as $proposal)
        <tr>
            <td><a href="{{ route('admin.proposals.show', ['id' => $proposal->getKey()]) }}">#{{ $proposal->getKey() }}</a></td>
            <td><code class="hub-mono">{{ $proposal->getAttribute('entity_type') }}</code></td>
            <td>#{{ $proposal->getAttribute('entity_id') }}</td>
            <td><code class="hub-mono">{{ $proposal->getAttribute('field') }}</code></td>
            <td class="hub-small">{{ \Illuminate\Support\Str::limit((string) $proposal->getAttribute('old_value'), 60) }}</td>
            <td class="hub-small">{{ \Illuminate\Support\Str::limit((string) $proposal->getAttribute('new_value'), 60) }}</td>
            <td><x-admin.status-badge :status="match ($proposal->status?->value) { 'open' => 'warn', 'transferred' => 'unknown', 'confirmed' => 'ok', default => 'disabled' }" :label="$statusLabels[$proposal->status?->value] ?? $proposal->status?->value" /></td>
            <td>{{ $proposal->requestedBy?->getAttribute('name') ?? 'System' }}</td>
            <td>{{ $proposal->getAttribute('created_at')?->format('d.m.Y H:i') }}</td>
            <td>{{ $proposal->getAttribute('transferred_at')?->format('d.m.Y H:i') }}@if ($proposal->transferredBy) <span class="hub-small hub-muted">({{ $proposal->transferredBy->getAttribute('name') }})</span>@endif</td>
        </tr>
    @endforeach
</x-admin.data-table>
@endsection
