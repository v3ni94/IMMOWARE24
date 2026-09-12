@extends('layouts.admin', ['title' => 'Import '.$file->original_filename])
@php
    use App\Core\Support\GermanDate;
    $exportType = $file->export_type !== null ? \App\Modules\Imports\Enums\ExportType::tryFrom((string) $file->export_type) : null;
@endphp
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.imports.index') }}">Zur Liste</a>
    @if ((string) $file->status === 'quarantined')
        <a class="hub-button hub-button-secondary" href="{{ route('admin.imports.quarantine', ['file' => $file->getKey()]) }}">Quarantäne bearbeiten</a>
    @endif
@endsection
@section('content')
    <div class="hub-card-grid">
        <div class="hub-card">
            <x-admin.key-value title="Datei" :items="[
                'Dateiname' => $file->original_filename,
                'Dateityp' => $file->file_type,
                'Exporttyp' => $exportType?->label() ?? $file->export_type,
                'Status' => $file->status,
                'Quarantänegrund' => $file->quarantine_reason,
                'Größe' => number_format((int) $file->size_bytes, 0, ',', '.').' Byte',
                'SHA-256' => $file->content_hash,
                'Header-Fingerprint' => $file->header_fingerprint,
                'Format' => $file->format !== null ? $file->format->format_key.' v'.$file->format->version.' ('.$file->format->status.')' : null,
                'Vollexport' => (bool) $file->is_full_export,
                'Stichtag' => GermanDate::format($file->as_of_date),
                'Exportiert am' => GermanDate::formatDateTime($file->exported_at),
                'Exportiert von' => $file->exported_by,
            ]" />
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Verarbeitung" :items="[
                'Verbindung' => $file->connection?->name,
                'Empfangen' => GermanDate::formatDateTime($file->received_at),
                'Verarbeitet' => GermanDate::formatDateTime($file->processed_at),
                'Zeilen gesamt' => $file->rows_total,
                'Zeilen importiert' => $file->rows_imported,
                'Zeilen fehlgeschlagen' => $file->rows_failed,
                'Zeilen abgewiesen' => $file->rows_rejected,
                'Zeilen Duplikate' => $file->rows_duplicate,
                'Sync-Lauf' => $file->sync_run_id,
                'Hochgeladen von' => $file->uploadedBy?->name,
                'Zusammenfassung' => $file->error_summary,
            ]" />
        </div>
    </div>

    <div class="hub-section">
        <h2>Fehlerliste ({{ count($errors_list) }})</h2>
        <x-admin.data-table :rows="$errors_list" :columns="['Zeile', 'Grund']" empty="Keine Fehler protokolliert.">
            @foreach ($errors_list as $error)
                <tr>
                    <td class="hub-num">{{ is_array($error) && isset($error['line']) && $error['line'] !== null ? $error['line'] : '' }}</td>
                    <td>{{ is_array($error) ? ($error['reason'] ?? $error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE)) : (string) $error }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    </div>

    @if ($file->metadata)
        <div class="hub-section">
            <x-admin.json-view :data="$file->metadata" title="Sidecar-Metadaten" />
        </div>
    @endif
@endsection
