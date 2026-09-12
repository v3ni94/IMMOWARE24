@extends('layouts.admin', ['title' => 'Export'])
@php
    use App\Core\Support\GermanDate;
    $badge = static fn (string $status): string => match ($status) {
        'completed' => 'ok',
        'pending', 'running' => 'unknown',
        'failed' => 'fail',
        default => 'unknown',
    };
@endphp
@section('content')
    <div class="hub-card">
        <h2>Neuen Export anfordern</h2>
        <p class="hub-help">Der Export läuft asynchron (Queue {{ $queue }}) und schreibt die Daten des eigenen Mandanten chunked als CSV (Semikolon, UTF-8 mit BOM) oder JSON. Sensible Spalten wie IBAN sind nicht Teil der Spaltenlisten.</p>
        <form method="post" action="{{ route('admin.export.store') }}" class="hub-form">
            @csrf
            <div class="hub-form-row">
                <div>
                    <label for="e-entity">Entität</label>
                    <select id="e-entity" name="entity" required>
                        @foreach ($entities as $entity)
                            <option value="{{ $entity }}" @selected(old('entity') === $entity)>{{ $entity }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="e-format">Format</label>
                    <select id="e-format" name="format" required>
                        @foreach ($formats as $format)
                            <option value="{{ $format->value }}" @selected(old('format', 'csv') === $format->value)>{{ strtoupper($format->value) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="e-col">Filterspalte (optional, Gleichheit)</label>
                    <input id="e-col" type="text" name="filter_column" list="e-columns" value="{{ old('filter_column') }}" pattern="[a-z0-9_]*">
                    <datalist id="e-columns">
                        @foreach ($columns as $entity => $list)
                            @foreach ($list as $column)
                                <option value="{{ $column }}">{{ $entity }}</option>
                            @endforeach
                        @endforeach
                    </datalist>
                </div>
                <div>
                    <label for="e-val">Filterwert (leer bedeutet NULL)</label>
                    <input id="e-val" type="text" name="filter_value" value="{{ old('filter_value') }}">
                </div>
            </div>
            <label class="hub-checkbox"><input type="hidden" name="include_deleted" value="0"><input type="checkbox" name="include_deleted" value="1" @checked(old('include_deleted') === '1')> Soft-gelöschte Datensätze einbeziehen</label>
            <div class="hub-form-actions"><button type="submit" class="hub-button">Export starten</button></div>
        </form>
    </div>

    <x-admin.data-table :rows="$exports" :columns="['ID', 'Entität', 'Format', 'Filter', 'Status', 'Zeilen', 'Größe', 'Angefordert von', 'Angefordert', 'Fertig', '']" empty="Noch keine Exporte.">
        @foreach ($exports as $export)
            <tr>
                <td class="hub-num">{{ $export->getKey() }}</td>
                <td>{{ $export->entity }}</td>
                <td>{{ strtoupper($export->format->value) }}</td>
                <td class="hub-small"><code class="hub-mono">{{ json_encode($export->filter ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></td>
                <td><x-admin.status-badge :status="$badge($export->status->value)" :label="$export->status->value" />@if ($export->error_summary)<br><small class="hub-muted">{{ \Illuminate\Support\Str::limit((string) $export->error_summary, 100) }}</small>@endif</td>
                <td class="hub-num">{{ $export->row_count !== null ? number_format((int) $export->row_count, 0, ',', '.') : '' }}</td>
                <td class="hub-num">{{ $export->size_bytes !== null ? number_format((int) $export->size_bytes, 0, ',', '.').' B' : '' }}</td>
                <td>{{ $export->requestedBy?->name ?? 'keine Angabe' }}</td>
                <td>{{ GermanDate::formatDateTime($export->created_at) }}</td>
                <td>{{ GermanDate::formatDateTime($export->finished_at) }}</td>
                <td>
                    @if ($export->status->value === 'completed' && $mayDownload($export))
                        <a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.export.download', ['export' => $export->getKey()]) }}">Download</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
