@extends('layouts.admin', ['title' => 'Importe'])
@php
    use App\Core\Support\GermanDate;
    $badge = static fn (string $status): string => match ($status) {
        'imported' => 'ok',
        'received', 'processing' => 'unknown',
        'quarantined' => 'warn',
        'failed' => 'fail',
        default => 'unknown',
    };
    $statusLabel = static fn (string $status): string => match ($status) {
        'received' => 'Empfangen',
        'quarantined' => 'Quarantäne',
        'processing' => 'In Verarbeitung',
        'imported' => 'Importiert',
        'failed' => 'Fehlgeschlagen',
        default => $status,
    };
@endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.imports.schedules.index') }}">Exportrhythmen @if ($overdue > 0) ({{ $overdue }} überfällig) @endif</a>
@endsection
@section('content')
    <div class="hub-stat-grid hub-section">
        @foreach ($statuses as $status)
            <x-admin.stat-card :label="$statusLabel($status->value)" :value="(int) ($counts[$status->value] ?? 0)" :status="$badge($status->value)" :href="route('admin.imports.index', ['status' => $status->value])" />
        @endforeach
    </div>

    <form method="get" action="{{ route('admin.imports.index') }}" class="hub-form hub-card">
        <div class="hub-form-row">
            <div>
                <label for="filter-status">Status</label>
                <select id="filter-status" name="status">
                    <option value="">Alle</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status->value }}" @selected($filter['status'] === $status->value)>{{ $statusLabel($status->value) }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-type">Exporttyp</label>
                <select id="filter-type" name="export_type">
                    <option value="">Alle</option>
                    @foreach ($types as $type)
                        <option value="{{ $type->value }}" @selected($filter['export_type'] === $type->value)>{{ $type->label() }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="hub-form-actions">
            <button type="submit" class="hub-button">Filtern</button>
            <a class="hub-button hub-button-link" href="{{ route('admin.imports.index') }}">Zurücksetzen</a>
        </div>
    </form>

    <x-admin.data-table :rows="$files" :columns="['Empfangen', 'Datei', 'Exporttyp', 'Verbindung', 'Status', 'Zeilen', 'Fehler', '']" empty="Keine Importdateien vorhanden.">
        @foreach ($files as $file)
            <tr>
                <td>{{ GermanDate::formatDateTime($file->received_at) }}</td>
                <td><a href="{{ route('admin.imports.show', ['file' => $file->getKey()]) }}">{{ $file->original_filename }}</a><br><small class="hub-muted">{{ $file->file_type }}, {{ number_format((int) $file->size_bytes, 0, ',', '.') }} Byte</small></td>
                <td>{{ $file->export_type !== null && \App\Modules\Imports\Enums\ExportType::tryFrom($file->export_type) !== null ? \App\Modules\Imports\Enums\ExportType::from($file->export_type)->label() : ($file->export_type ?? 'keine Angabe') }}</td>
                <td>{{ $file->connection?->name ?? 'keine Angabe' }}</td>
                <td><x-admin.status-badge :status="$badge((string) $file->status)" :label="$statusLabel((string) $file->status)" /></td>
                <td class="hub-num">{{ $file->rows_imported !== null ? number_format((int) $file->rows_imported, 0, ',', '.') : '' }}@if ($file->rows_total !== null) / {{ number_format((int) $file->rows_total, 0, ',', '.') }}@endif</td>
                <td class="hub-num">{{ $file->rows_failed !== null ? number_format((int) $file->rows_failed, 0, ',', '.') : '' }}</td>
                <td>
                    @if ((string) $file->status === 'quarantined')
                        <a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.imports.quarantine', ['file' => $file->getKey()]) }}">Quarantäne</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
