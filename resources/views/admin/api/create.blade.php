@extends('layouts.admin', ['title' => 'API-Key anlegen'])
@section('content')
    <div class="hub-card">
        <form method="post" action="{{ route('admin.api.store') }}" class="hub-form">
            @csrf
            <label for="k-name">Bezeichnung</label>
            <input id="k-name" type="text" name="name" maxlength="120" value="{{ old('name') }}" required>
            <fieldset>
                <legend>Scopes</legend>
                @php $selected = (array) old('scopes', []); @endphp
                @foreach ($scopes as $scope)
                    <label class="hub-checkbox"><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, $selected, true))> <code class="hub-mono">{{ $scope }}</code></label>
                @endforeach
            </fieldset>
            <label for="k-expires">Ablaufdatum (höchstens {{ $maxMonths }} Monate)</label>
            <input id="k-expires" type="date" name="expires_at" value="{{ old('expires_at', $defaultExpiry) }}" max="{{ $maxExpiry }}" required>
            <label for="k-ips">IP-Allowlist (optional, IPv4 oder IPv6, einzeln oder CIDR, durch Komma getrennt)</label>
            <textarea id="k-ips" name="allowed_ips" rows="2">{{ old('allowed_ips') }}</textarea>
            <div class="hub-form-actions">
                <button type="submit" class="hub-button">Schlüssel erzeugen</button>
                <a class="hub-button hub-button-link" href="{{ route('admin.api.index') }}">Abbrechen</a>
            </div>
        </form>
    </div>
@endsection
