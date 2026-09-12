@extends('layouts.admin', ['title' => 'Neue Mapping-Version: '.$entity->label()])

@section('content')
<p class="hub-muted hub-page-meta">Aktive Version: {{ $current ? 'v'.$current->getAttribute('version') : 'keine' }}. Die neue Version wird zunächst geprüft und verglichen, aktiviert wird sie erst nach Bestätigung.</p>

<form method="post" action="{{ route('admin.mapping.review') }}" class="hub-form hub-card">
    @csrf
    <input type="hidden" name="entity_type" value="{{ $entity->value }}">
    <div class="hub-form-row">
        <div>
            <label for="source_format">Quellformat</label>
            <input id="source_format" type="text" name="source_format" maxlength="40" required value="{{ old('source_format', $sourceFormat) }}">
        </div>
        <div>
            <label for="notes">Notiz (Grund der Änderung)</label>
            <input id="notes" type="text" name="notes" maxlength="2000" value="{{ old('notes') }}">
        </div>
    </div>
    <label for="rules_text">Regeln, eine je Zeile: <code>immoware_feld =&gt; hub_feld | transform</code> (Transform optional, Zeilen mit # sind Kommentare)</label>
    <textarea id="rules_text" name="rules_text" rows="20" class="hub-mono" required>{{ $rulesText }}</textarea>
    <p class="hub-help">Zulässige Transformationen: @foreach ($transforms as $transform)<code class="hub-mono">{{ $transform }}</code>@if (! $loop->last), @endif @endforeach</p>
    <div class="hub-form-actions">
        <button type="submit" class="hub-button">Prüfen und vergleichen</button>
        <a class="hub-button hub-button-secondary" href="{{ route('admin.mapping.index') }}">Abbrechen</a>
    </div>
</form>
@endsection
