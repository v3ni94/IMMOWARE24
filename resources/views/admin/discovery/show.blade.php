@extends('layouts.admin', ['title' => 'Request '.$entry->getKey()])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.discovery.index') }}">Zur Liste</a>
@endsection
@section('content')
    <div class="hub-alert hub-alert-warning" role="alert">Produktivdaten des Mandanten ({{ $environment }}). Header sind maskiert, Request-Bodies wurden nicht gespeichert.</div>
    <div class="hub-card-grid">
        <div class="hub-card">
            <x-admin.key-value title="Request" :items="[
                'Zeitpunkt' => GermanDate::formatDateTime($entry->requested_at),
                'Verbindung' => $entry->connection?->name,
                'Connector' => $entry->connector_name,
                'Methode' => $entry->method,
                'Pfad' => $entry->path,
                'Pfad-Hash' => $entry->path_hash,
                'Correlation-ID' => $entry->correlation_id,
                'Request-Größe' => $entry->request_bytes !== null ? number_format((int) $entry->request_bytes, 0, ',', '.').' Byte' : null,
            ]" />
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Antwort" :items="[
                'HTTP-Status' => $entry->response_status,
                'Ergebnis' => $entry->outcome,
                'Antwortzeit' => $entry->duration_ms !== null ? number_format((int) $entry->duration_ms, 0, ',', '.').' ms' : null,
                'Antwortgröße' => $entry->response_bytes !== null ? number_format((int) $entry->response_bytes, 0, ',', '.').' Byte' : null,
                'Schema-Fingerprint' => $entry->response_schema_fingerprint,
                'Requests mit gleichem Fingerprint' => $sameFingerprint,
                'Fehlerklasse' => $entry->error_class,
                'Fehlermeldung (maskiert)' => $entry->error_message_masked,
            ]" />
        </div>
    </div>
    <div class="hub-card-grid">
        <div class="hub-card"><x-admin.json-view :data="$entry->request_headers_masked" title="Request-Header (maskiert)" /></div>
        <div class="hub-card"><x-admin.json-view :data="$entry->response_headers" title="Response-Header (maskiert)" /></div>
    </div>
@endsection
