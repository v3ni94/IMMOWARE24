@extends('layouts.admin', ['title' => 'Mapping'])

@section('content')
<p class="hub-muted hub-page-meta">Feldmappings sind versioniert. Eine Änderung erzeugt eine neue Version, die alte wird retired. Mapping ist reproduzierbar: archivierte Nutzlasten lassen sich mit jeder Version erneut abbilden.</p>

<section class="hub-section" aria-labelledby="map-active">
    <h2 id="map-active">Aktive Versionen</h2>
    <x-admin.data-table :columns="['Entität', 'Quellformat', 'Aktive Version', 'Regeln', 'Aktiviert', 'Aktionen']" :rows="$active">
        @foreach (\App\Modules\Sync\Enums\SyncEntity::cases() as $entity)
            @php($mapping = $active[$entity->value])
            <tr>
                <td>{{ $entity->label() }} <code class="hub-mono">{{ $entity->value }}</code></td>
                <td><code class="hub-mono">{{ $entity->sourceFormat() }}</code></td>
                <td>
                    @if ($mapping)
                        <a href="{{ route('admin.mapping.show', ['id' => $mapping->getKey()]) }}">v{{ $mapping->getAttribute('version') }}</a>
                    @else
                        <span class="hub-muted">kein aktives Mapping (Default über hub:sync-Seeder anlegen)</span>
                    @endif
                </td>
                <td>{{ $mapping ? count((array) $mapping->getAttribute('mapping')) : 0 }}</td>
                <td>{{ $mapping?->getAttribute('activated_at')?->format('d.m.Y H:i') }}</td>
                <td>
                    @if ($canManage)
                        <a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.mapping.create', ['entity_type' => $entity->value, 'source_format' => $entity->sourceFormat()]) }}">Neue Version</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>

<section class="hub-section" aria-labelledby="map-all">
    <h2 id="map-all">Alle Versionen</h2>
    <form method="get" action="{{ route('admin.mapping.index') }}" class="hub-form hub-form-row">
        <div>
            <label for="f-entity">Entität</label>
            <select id="f-entity" name="entity_type">
                <option value="">alle</option>
                @foreach (\App\Modules\Sync\Enums\SyncEntity::cases() as $entity)
                    <option value="{{ $entity->value }}" @selected($filters['entity_type'] === $entity->value)>{{ $entity->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="f-status">Status</label>
            <select id="f-status" name="status">
                <option value="">alle</option>
                @foreach (['active', 'retired', 'draft'] as $status)
                    <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                @endforeach
            </select>
        </div>
        <div class="hub-form-actions"><button type="submit" class="hub-button hub-button-secondary">Filtern</button></div>
    </form>

    <x-admin.data-table :columns="['Version', 'Entität', 'Quellformat', 'Status', 'Regeln', 'Aktiviert', 'Retired', 'Vergleich']" :rows="$mappings" empty="Keine Mapping-Versionen vorhanden.">
        @foreach ($mappings as $mapping)
            <tr>
                <td><a href="{{ route('admin.mapping.show', ['id' => $mapping->getKey()]) }}">v{{ $mapping->getAttribute('version') }}</a></td>
                <td><code class="hub-mono">{{ $mapping->getAttribute('entity_type') }}</code></td>
                <td><code class="hub-mono">{{ $mapping->getAttribute('source_format') }}</code></td>
                <td><x-admin.status-badge :status="match ($mapping->getAttribute('status')) { 'active' => 'ok', 'draft' => 'warn', default => 'disabled' }" :label="$mapping->getAttribute('status')" /></td>
                <td>{{ count((array) $mapping->getAttribute('mapping')) }}</td>
                <td>{{ $mapping->getAttribute('activated_at')?->format('d.m.Y H:i') }}</td>
                <td>{{ $mapping->getAttribute('retired_at')?->format('d.m.Y H:i') }}</td>
                <td>
                    @if ($mapping->getAttribute('previous_version_id'))
                        <a href="{{ route('admin.mapping.compare', ['a' => $mapping->getAttribute('previous_version_id'), 'b' => $mapping->getKey()]) }}">mit Vorversion</a>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
</section>
@endsection
