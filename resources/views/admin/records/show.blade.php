@extends('layouts.admin', ['title' => $label.' #'.$model->getKey()])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.records.'.$entity.'.payload', ['id' => $model->getKey()]) }}">Rohpayload ({{ $payloadCount }})</a>
@endsection
@section('content')
    @if ($model->getAttribute('deleted_at') !== null)
        <div class="hub-alert hub-alert-warning">Dieser Datensatz ist im Spiegel als gelöscht markiert (deleted_at {{ GermanDate::formatDateTime($model->getAttribute('deleted_at')) }}). Spiegeldaten werden nie hart gelöscht.</div>
    @endif
    <div class="hub-card-grid">
        <div class="hub-card">
            <x-admin.provenance :model="$model" :connector="$connectorName" :mapping-version="$mappingVersion" />
            <p class="hub-help">Immoware24 ist Master. Änderungen an diesem Datensatz erfolgen in Immoware24 oder über einen Änderungsvorschlag, nie direkt im Hub. Veraltet ab {{ number_format($staleThreshold / 3600, 1, ',', '.') }} Stunden ohne erfolgreichen Sync.</p>
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Fachliche Daten" :items="$attributes" />
        </div>
    </div>
@endsection
