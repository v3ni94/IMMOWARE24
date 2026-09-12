@extends('layouts.admin', ['title' => 'Rohpayload '.$label.' #'.$model->getKey()])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.records.'.$entity.'.show', ['id' => $model->getKey()]) }}">Zum Datensatz</a>
@endsection
@section('content')
    <p class="hub-page-meta">Archivierte Nutzlasten (external_payloads) zu External ID <code class="hub-mono">{{ $model->getAttribute('external_id') }}</code>. Inhalte sind bereits beim Archivieren maskiert und werden hier erneut maskiert dargestellt. Nutzlasten über {{ number_format(\App\Modules\Sync\Models\ExternalPayload::INLINE_LIMIT_BYTES / 1024, 0, ',', '.') }} KB oder binäre Inhalte werden nicht angezeigt.</p>

    @if ($payloads === null)
        <div class="hub-alert hub-alert-info">Zu diesem Datensatz ist keine externe ID hinterlegt, daher kann keine Nutzlast zugeordnet werden.</div>
    @else
        <x-admin.data-table :rows="$payloads" :columns="['ID', 'Empfangen', 'Typ', 'Größe', 'HTTP', 'ETag', 'Inhalts-Hash', 'Personenbezug', '']" empty="Keine archivierten Nutzlasten zu dieser External ID.">
            @foreach ($payloads as $payload)
                <tr @if ($selected !== null && (int) $selected->getKey() === (int) $payload->getKey()) style="background: var(--hub-surface-2);" @endif>
                    <td class="hub-num">{{ $payload->getKey() }}</td>
                    <td>{{ GermanDate::formatDateTime($payload->received_at) }}</td>
                    <td><code class="hub-mono">{{ $payload->payload_type }}</code></td>
                    <td class="hub-num">{{ number_format((int) $payload->size_bytes, 0, ',', '.') }} B</td>
                    <td class="hub-num">{{ $payload->http_status ?? '' }}</td>
                    <td class="hub-small hub-break">{{ $payload->remote_etag }}</td>
                    <td><code class="hub-mono">{{ mb_substr((string) $payload->content_hash, 0, 16) }}…</code></td>
                    <td>{{ $payload->contains_personal_data ? ($payload->pseudonymized_at !== null ? 'pseudonymisiert' : 'ja') : 'nein' }}</td>
                    <td><a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.records.'.$entity.'.payload', ['id' => $model->getKey(), 'payload' => $payload->getKey()]) }}">Anzeigen</a></td>
                </tr>
            @endforeach
        </x-admin.data-table>

        @if ($selected !== null)
            <div class="hub-card">
                <x-admin.key-value title="Nutzlast #{{ $selected->getKey() }}" :items="[
                    'Sync-Lauf' => $selected->sync_run_id,
                    'Remote geändert' => GermanDate::formatDateTime($selected->remote_last_modified),
                    'Ablage' => $selected->storage_key !== null ? 'Storage-Disk' : 'inline',
                ]" />
                @if ($content !== null)
                    <x-admin.json-view :data="$content" title="Inhalt (maskiert)" />
                @else
                    <p class="hub-muted">Inhalt wird nicht angezeigt (zu groß, binär oder nicht mehr vorhanden).</p>
                @endif
                @if ($selected->import_metadata)
                    <x-admin.json-view :data="$selected->import_metadata" title="Metadaten" />
                @endif
            </div>
        @endif
    @endif
@endsection
