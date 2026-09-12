@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; @endphp
@section('content')
    <p class="hub-hint">Gmail ist Mailsystem, Immoware24 Fachsystem, Lexware Office Rechnungsprogramm, Google Drive Dokumentenquelle. Nicht konfigurierte Integrationen erscheinen als "Nicht eingerichtet". Ein technischer Fehler wird nie als Erfolg gezeigt.</p>
    <div class="hub-card-grid">
        @foreach ($rows as $row)
            @php $state = $states[$row['state']]; @endphp
            <section class="hub-card mail-integration" aria-label="{{ $row['title'] }}">
                <h2>{{ $row['title'] }}</h2>
                <p><span class="hub-badge hub-badge-{{ $state['level'] }}"><span aria-hidden="true">{{ $state['symbol'] }}</span> {{ $state['label'] }}</span>
                    @if ($row['state_reason'])<small class="hub-muted">{{ $row['state_reason'] }}</small>@endif</p>
                <dl class="hub-kv">
                    <div class="hub-kv-row"><dt>Berechtigungen (Scopes)</dt><dd>@if ($row['scopes'] === [])<span class="hub-muted">keine erteilt</span>@else<ul class="mail-scopes">@foreach ($row['scopes'] as $scope)<li><code>{{ $scope }}</code></li>@endforeach</ul>@endif</dd></div>
                    <div class="hub-kv-row"><dt>Capabilities</dt><dd>@if ($row['capabilities'] === [])<span class="hub-muted">keine</span>@else @foreach ($row['capabilities'] as $name => $value)<span class="hub-badge">{{ $name }}: {{ $value }}</span> @endforeach @endif</dd></div>
                    <div class="hub-kv-row"><dt>Letzter erfolgreicher Abgleich</dt><dd>{{ GermanDate::formatDateTime($row['last_success_at']) ?? 'noch nie' }}</dd></div>
                    @if (is_array($row['lag']))
                        <div class="hub-kv-row"><dt>Rückstand</dt><dd>@foreach ($row['lag'] as $name => $value)<div>{{ $name }}: {{ $value }}</div>@endforeach</dd></div>
                    @endif
                    <div class="hub-kv-row"><dt>Fehler</dt><dd>{{ $row['error'] ?? 'keiner' }}</dd></div>
                </dl>
                <div class="hub-form-actions">
                    @foreach ($row['actions'] as $action)
                        @if ($action['url'] !== null)
                            <form method="post" action="{{ $action['url'] }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button {{ $action['label'] === 'Widerrufen' ? 'hub-button-danger' : '' }} hub-button-small">{{ $action['label'] }}</button></form>
                        @else
                            <button type="button" class="hub-button hub-button-secondary hub-button-small" disabled aria-disabled="true" title="Route des Fachmoduls nicht vorhanden">{{ $action['label'] }} (nicht verfügbar)</button>
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
    <details class="hub-details"><summary>Feature-Flags (Umgebung, nur Anzeige)</summary>
        <ul>@foreach ($flags as $flag => $enabled)<li><code>{{ $flag }}</code>: <span class="hub-badge {{ $enabled ? 'hub-badge-warn' : 'hub-badge-disabled' }}">{{ $enabled ? 'aktiv' : 'aus' }}</span></li>@endforeach</ul>
    </details>
@endsection
