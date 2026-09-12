@extends('layouts.admin', ['title' => 'Änderungsvorschlag #'.$proposal->getKey()])

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.proposals.index') }}">Zur Liste</a>
@endsection

@section('content')
<section class="hub-section">
    <x-admin.key-value :items="[
        'Status' => $statusLabels[$proposal->status?->value] ?? $proposal->status?->value,
        'Entität' => $proposal->getAttribute('entity_type'),
        'Datensatz' => '#'.$proposal->getAttribute('entity_id'),
        'Connection' => $proposal->connection?->getAttribute('name'),
        'Feld' => $proposal->getAttribute('field'),
        'Begründung' => $proposal->getAttribute('reason'),
        'Beantragt von' => $proposal->requestedBy?->getAttribute('name') ?? 'System',
        'Angelegt' => $proposal->getAttribute('created_at'),
        'Übertragen am' => $proposal->getAttribute('transferred_at'),
        'Übertragen von' => $proposal->transferredBy?->getAttribute('name'),
        'Bestätigt durch Lauf' => $proposal->getAttribute('confirmed_by_sync_run_id') ? '#'.$proposal->getAttribute('confirmed_by_sync_run_id') : null,
        'Bestätigt am' => $proposal->getAttribute('confirmed_at'),
        'Abgelehnt am' => $proposal->getAttribute('rejected_at'),
        'Correlation-ID' => $proposal->getAttribute('correlation_id'),
    ]" />
</section>

<section class="hub-section" aria-labelledby="prop-values">
    <h2 id="prop-values">Wert alt gegen neu</h2>
    <div class="hub-card-grid">
        <div class="hub-card">
            <h3>Aktueller Wert (Spiegel)</h3>
            <pre class="hub-mono hub-pre">{{ $proposal->getAttribute('old_value') ?? 'leer' }}</pre>
        </div>
        <div class="hub-card">
            <h3>Gewünschter Wert (in Immoware24 zu setzen)</h3>
            <pre class="hub-mono hub-pre">{{ $proposal->getAttribute('new_value') ?? 'leer' }}</pre>
        </div>
    </div>
</section>

<section class="hub-section" aria-labelledby="prop-transfer">
    <h2 id="prop-transfer">Übertragung nach Immoware24</h2>
    @if ($proposal->status === \App\Modules\Sync\Enums\ProposedChangeStatus::Open)
        @if ($canTransfer)
            <p class="hub-help">Erst markieren, nachdem die Änderung in Immoware24 tatsächlich vorgenommen wurde. Benutzer und Zeitpunkt werden gespeichert.</p>
            <x-admin.confirm-form :action="route('admin.proposals.transfer', ['id' => $proposal->getKey()])" label="Als in Immoware24 übertragen markieren" :danger="false" description="Der Vorschlag wechselt auf übertragen. Der nächste Sync bestätigt den neuen Wert oder meldet eine Abweichung." note-field="note" />
        @else
            <p class="hub-help">Markieren erfordert das Recht conflicts.resolve (Rolle Operator oder Administrator).</p>
        @endif
    @else
        <p class="hub-muted">Der Vorschlag ist nicht mehr offen.</p>
    @endif
</section>
@endsection
