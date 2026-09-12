@extends('layouts.admin', ['title' => 'Webhook-Endpunkt bearbeiten'])
@section('content')
    <div class="hub-card">
        <form method="post" action="{{ route('admin.webhooks.update', ['endpoint' => $endpoint->getKey()]) }}" class="hub-form">
            @csrf
            @method('PUT')
            @include('admin::webhooks._fields', ['endpoint' => $endpoint])
            <p class="hub-help">Das Secret wird nicht angezeigt. Für ein neues Secret einen neuen Endpunkt anlegen und den alten deaktivieren.</p>
            <div class="hub-form-actions">
                <button type="submit" class="hub-button">Speichern</button>
                <a class="hub-button hub-button-link" href="{{ route('admin.webhooks.index') }}">Abbrechen</a>
            </div>
        </form>
    </div>
@endsection
