@extends('layouts.admin', ['title' => 'Mapping-Version prüfen: '.$entity->label()])

@section('content')
<p class="hub-muted hub-page-meta">Regeln gültig ({{ count($rules) }}). Vergleich mit der aktiven Version {{ $current ? 'v'.$current->getAttribute('version') : '(keine)' }}. Die neue Version erhält die nächste Versionsnummer und wird mit der Bestätigung aktiviert.</p>

@include('admin::mapping._diff', ['diff' => $diff])

<div class="hub-form-actions">
    <x-admin.confirm-form :action="route('admin.mapping.store')" label="Version aktivieren" description="Die neue Mapping-Version wird aktiv, die bisherige retired. Laufende Syncs nutzen ab dem nächsten Chunk die neue Version.">
        <input type="hidden" name="entity_type" value="{{ $entity->value }}">
        <input type="hidden" name="source_format" value="{{ $sourceFormat }}">
        <input type="hidden" name="notes" value="{{ $notes }}">
        <textarea name="rules_text" hidden>{{ $rulesText }}</textarea>
    </x-admin.confirm-form>
    <a class="hub-button hub-button-secondary" href="{{ route('admin.mapping.create', ['entity_type' => $entity->value, 'source_format' => $sourceFormat]) }}">Zurück zur Bearbeitung</a>
</div>
@endsection
