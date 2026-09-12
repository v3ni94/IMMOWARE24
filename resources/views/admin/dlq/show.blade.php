@extends('layouts.admin', ['title' => 'Fehlerqueue #'.$item->getKey()])

@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('admin.dlq.index') }}">Zur Fehlerqueue</a>
@endsection

@section('content')
<section class="hub-section">
    <x-admin.key-value :items="[
        'Status' => $statusLabels[$item->status?->value] ?? $item->status?->value,
        'Job-Klasse' => $item->getAttribute('job_class'),
        'Connection' => $item->connection?->getAttribute('name'),
        'Entität' => $item->getAttribute('entity_type'),
        'Queue' => $item->getAttribute('queue'),
        'Correlation-ID' => $item->getAttribute('correlation_id'),
        'Fehlgeschlagen am' => $item->getAttribute('failed_at'),
        'Versuche (Wiederaufnahme)' => (int) $item->getAttribute('attempts'),
        'Zuletzt wiederholt' => $item->getAttribute('replayed_at'),
        'Wiederholt durch' => $item->replayedBy?->getAttribute('name'),
        'Ergebnis' => $item->getAttribute('replay_result'),
        'Umfang der Wiederaufnahme' => $singleRecord ? 'ein einzelner Datensatz (singleRecord)' : 'der ursprüngliche Job mit seinen Argumenten',
    ]" />
</section>

<section class="hub-section" aria-labelledby="dlq-actions">
    <h2 id="dlq-actions">Aktionen</h2>
    @if (! $canMutate)
        <p class="hub-help">Retry und Ignorieren erfordern das Recht sync.run.</p>
    @else
        <div class="hub-form-actions">
            @if ($retryable)
                <x-admin.confirm-form :action="route('admin.dlq.retry', ['id' => $item->getKey()])" label="Retry" :danger="false" description="Der Eintrag wird genau einmal erneut verarbeitet (nur dieser Datensatz bzw. Job). Ein zweites Scheitern bleibt in der Fehlerqueue." />
            @endif
            @if ($item->status !== \App\Modules\Sync\Enums\DlqStatus::Ignored)
                <x-admin.confirm-form :action="route('admin.dlq.ignore', ['id' => $item->getKey()])" label="Ignorieren" description="Der Eintrag wird als ignoriert markiert und nicht erneut verarbeitet." note-field="note" />
            @endif
        </div>
    @endif
</section>

<section class="hub-section" aria-labelledby="dlq-error">
    <h2 id="dlq-error">Fehler</h2>
    <details class="hub-details" open>
        <summary>Fehler ansehen</summary>
        <pre class="hub-mono hub-pre">{{ $item->getAttribute('exception') }}</pre>
    </details>
</section>

<section class="hub-section" aria-labelledby="dlq-payload">
    <h2 id="dlq-payload">Payload (maskiert)</h2>
    <details class="hub-details">
        <summary>Payload ansehen</summary>
        <x-admin.json-view :data="$payload" />
    </details>
</section>

<section class="hub-section" aria-labelledby="dlq-mapping">
    <h2 id="dlq-mapping">Mapping</h2>
    <details class="hub-details">
        <summary>Mapping ansehen</summary>
        @if ($mapping === null)
            <p class="hub-muted">Für diesen Eintrag ist keine Entität mit aktivem Feldmapping hinterlegt.</p>
        @else
            <p>Aktive Version <a href="{{ route('admin.mapping.show', ['id' => $mapping->getKey()]) }}">v{{ $mapping->getAttribute('version') }}</a> für {{ $mapping->getAttribute('entity_type') }} / {{ $mapping->getAttribute('source_format') }}.</p>
            <x-admin.data-table :columns="['Immoware-Feld', 'Hub-Feld', 'Transform']" :rows="(array) $mapping->getAttribute('mapping')">
                @foreach ((array) $mapping->getAttribute('mapping') as $rule)
                    <tr>
                        <td><code class="hub-mono">{{ $rule['source_field'] ?? '' }}</code></td>
                        <td><code class="hub-mono">{{ $rule['target_field'] ?? '' }}</code></td>
                        <td>{{ ($rule['transform'] ?? null) ?: 'keine' }}</td>
                    </tr>
                @endforeach
            </x-admin.data-table>
        @endif
    </details>
</section>
@endsection
