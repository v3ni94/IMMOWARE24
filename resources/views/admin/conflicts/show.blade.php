@extends('layouts.admin', ['title' => 'Konflikt #'.$conflict->getKey()])

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.conflicts.index') }}">Zur Konfliktqueue</a>
@endsection

@section('content')
<section class="hub-section">
    <x-admin.key-value :items="[
        'Status' => $statusLabels[$conflict->getAttribute('status')] ?? $conflict->getAttribute('status'),
        'Connection' => $conflict->connection?->getAttribute('name'),
        'Entität' => $conflict->getAttribute('entity_type'),
        'Datensatz' => $conflict->getAttribute('entity_id') !== null ? '#'.$conflict->getAttribute('entity_id') : null,
        'Konflikttyp' => $conflict->getAttribute('conflict_type'),
        'Zustand' => $conflict->conflict_state?->value ?? $conflict->getAttribute('conflict_state'),
        'Erkannt in Lauf' => $conflict->getAttribute('sync_run_id') ? '#'.$conflict->getAttribute('sync_run_id') : null,
        'Vorkommen' => $conflict->getAttribute('occurrences'),
        'Zuletzt gesehen' => $conflict->getAttribute('last_seen_at'),
        'Angelegt' => $conflict->getAttribute('created_at'),
        'Zugewiesen an' => $conflict->assignedTo?->getAttribute('name'),
        'Aufgelöst von' => $conflict->resolvedBy?->getAttribute('name'),
        'Aufgelöst am' => $conflict->getAttribute('resolved_at'),
        'Begründung' => $conflict->getAttribute('resolution_note'),
    ]" />
</section>

<section class="hub-section" aria-labelledby="conf-compare">
    <h2 id="conf-compare">Lokal gegen Immoware24</h2>
    <div class="hub-card-grid">
        <div class="hub-card">
            <h3>Lokal (Spiegel im Hub)</h3>
            <x-admin.json-view :data="$local" />
        </div>
        <div class="hub-card">
            <h3>Remote (Immoware24, archivierte Nutzlast)</h3>
            @if ($remotePayload !== null)
                <p class="hub-small hub-muted">Payload #{{ $remotePayload->getKey() }}, empfangen {{ $remotePayload->getAttribute('received_at')?->format('d.m.Y H:i:s') }} UTC, SHA-256 <code class="hub-mono">{{ mb_substr((string) $remotePayload->getAttribute('content_hash'), 0, 16) }}…</code></p>
            @endif
            <x-admin.json-view :data="$remote" />
        </div>
    </div>
    @if ($proposed)
        <x-admin.json-view :data="$proposed" title="Vorgeschlagene Änderung" />
    @endif
</section>

<section class="hub-section" aria-labelledby="conf-resolve">
    <h2 id="conf-resolve">Auflösung</h2>
    @if (! $isOpen)
        <p class="hub-muted">Der Konflikt ist geschlossen.</p>
    @elseif (! $canResolve)
        <p class="hub-help">Auflösen erfordert das Recht conflicts.resolve (Rolle Operator oder Administrator).</p>
    @else
        <x-admin.confirm-form :action="route('admin.conflicts.resolve', ['id' => $conflict->getKey()])" label="Konflikt auflösen" description="Die Entscheidung wird mit Begründung im Auditlog gespeichert. Immoware24 wird dadurch nicht verändert." note-field="note">
            <fieldset class="hub-form">
                <legend>Entscheidung</legend>
                <label class="hub-checkbox"><input type="radio" name="resolution" value="remote" checked> Immoware24 übernehmen (Master, Spiegel wird beim nächsten Sync angeglichen)</label>
                <label class="hub-checkbox"><input type="radio" name="resolution" value="local"> Lokalen Stand behalten (Immoware24 bleibt unverändert, Rückweg über Änderungsvorschlag)</label>
                <label class="hub-checkbox"><input type="radio" name="resolution" value="manual"> Manuell geklärt (außerhalb des Hubs umgesetzt)</label>
            </fieldset>
        </x-admin.confirm-form>
    @endif
</section>
@endsection
