@extends('layouts.admin', ['title' => 'Auditeintrag '.$entry->getKey()])
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.audit.index') }}">Zur Liste</a>
@endsection
@section('content')
    <div class="hub-card-grid">
        <div class="hub-card">
            <x-admin.key-value title="Eintrag" :items="[
                'ID' => $entry->getKey(),
                'Zeitpunkt (UTC)' => $entry->occurred_at?->format('d.m.Y H:i:s.u'),
                'Akteur' => $entry->actor_type.($entry->actor_id !== null ? ' '.$entry->actor_id : '').($actorName !== null ? ' ('.$actorName.')' : ''),
                'Quelle' => $entry->source instanceof \BackedEnum ? $entry->source->value : $entry->source,
                'Aktion' => $entry->action,
                'Entität' => $entry->entity_type !== null ? $entry->entity_type.' #'.$entry->entity_id : null,
                'Verbindung' => $entry->connection_id,
                'Mandant' => $entry->organization_id,
                'Correlation-ID' => $entry->correlation_id,
                'IP-Hash' => $entry->ip_address_hash !== null ? mb_substr((string) $entry->ip_address_hash, 0, 16).'…' : null,
            ]" />
        </div>
        <div class="hub-card">
            <x-admin.key-value title="Hash-Kette" :items="[
                'prev_hash' => $entry->prev_hash,
                'row_hash' => $entry->row_hash,
            ]">
                <div class="hub-kv-row">
                    <dt>Prüfung</dt>
                    <dd><x-admin.status-badge :status="$chainOk ? 'ok' : 'fail'" :label="$chainOk ? 'Zeile konsistent mit Vorgänger' : 'Zeile passt nicht zum Vorgänger'" /></dd>
                </div>
            </x-admin.key-value>
        </div>
    </div>

    @if ($showDiff)
        <div class="hub-card-grid">
            <div class="hub-card"><x-admin.json-view :data="$entry->before_json" title="Vorher (maskiert)" /></div>
            <div class="hub-card"><x-admin.json-view :data="$entry->after_json" title="Nachher (maskiert)" /></div>
        </div>
    @else
        <div class="hub-alert hub-alert-info">Ihre Rolle sieht die Änderungsdetails (vorher/nachher) dieses Eintrags nicht.</div>
    @endif

    @if ($related->isNotEmpty())
        <div class="hub-section">
            <h2>Weitere Einträge derselben Correlation-ID</h2>
            <x-admin.data-table :rows="$related" :columns="['ID', 'Zeit (UTC)', 'Aktion', 'Entität']">
                @foreach ($related as $item)
                    <tr>
                        <td class="hub-num"><a href="{{ route('admin.audit.show', ['log' => $item->getKey()]) }}">{{ $item->getKey() }}</a></td>
                        <td>{{ $item->occurred_at?->format('d.m.Y H:i:s') }}</td>
                        <td><code class="hub-mono">{{ $item->action }}</code></td>
                        <td>{{ $item->entity_type }}@if ($item->entity_id !== null) #{{ $item->entity_id }}@endif</td>
                    </tr>
                @endforeach
            </x-admin.data-table>
        </div>
    @endif
@endsection
