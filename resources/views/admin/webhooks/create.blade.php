@extends('layouts.admin', ['title' => 'Webhook-Endpunkt anlegen'])
@section('content')
    <div class="hub-card">
        <form method="post" action="{{ route('admin.webhooks.store') }}" class="hub-form">
            @csrf
            @include('admin::webhooks._fields', ['endpoint' => null])
            <label class="hub-checkbox"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked(old('active', '1') === '1')> Sofort aktiv</label>
            <div class="hub-form-actions">
                <button type="submit" class="hub-button">Anlegen</button>
                <a class="hub-button hub-button-link" href="{{ route('admin.webhooks.index') }}">Abbrechen</a>
            </div>
        </form>
    </div>
@endsection
