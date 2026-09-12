@extends('layouts.admin', ['title' => 'Mapping '.$mapping->getAttribute('entity_type').' v'.$mapping->getAttribute('version')])

@section('actions')
    @if ($canManage)
        <a class="hub-button" href="{{ route('admin.mapping.create', ['entity_type' => $mapping->getAttribute('entity_type'), 'source_format' => $mapping->getAttribute('source_format')]) }}">Neue Version</a>
    @endif
    <a class="hub-button hub-button-secondary" href="{{ route('admin.mapping.index') }}">Alle Mappings</a>
@endsection

@section('content')
<section class="hub-section">
    <x-admin.key-value :items="[
        'Entität' => $mapping->getAttribute('entity_type'),
        'Quellsystem' => $mapping->getAttribute('source_system'),
        'Quellformat' => $mapping->getAttribute('source_format'),
        'Version' => 'v'.$mapping->getAttribute('version'),
        'Status' => $mapping->getAttribute('status'),
        'Angelegt von' => $mapping->createdBy?->getAttribute('name') ?? 'System (Default)',
        'Aktiviert von' => $mapping->activatedBy?->getAttribute('name'),
        'Aktiviert am' => $mapping->getAttribute('activated_at'),
        'Retired am' => $mapping->getAttribute('retired_at'),
        'Vorversion' => $mapping->previousVersion ? 'v'.$mapping->previousVersion->getAttribute('version') : null,
        'Notiz' => $mapping->getAttribute('notes'),
    ]" />
</section>

<section class="hub-section" aria-labelledby="map-rules">
    <h2 id="map-rules">Regeln (Immoware-Feld, Hub-Feld, Transform)</h2>
    <x-admin.data-table :columns="['#', 'Immoware-Feld', 'Hub-Feld', 'Transform']" :rows="$rules">
        @foreach ($rules as $index => $rule)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td><code class="hub-mono">{{ $rule['source_field'] ?? '' }}</code></td>
                <td><code class="hub-mono">{{ $rule['target_field'] ?? '' }}</code></td>
                <td>{{ ($rule['transform'] ?? null) ?: 'keine' }}</td>
            </tr>
        @endforeach
    </x-admin.data-table>
    @if ($mapping->getAttribute('key_schema'))
        <x-admin.json-view :data="$mapping->getAttribute('key_schema')" title="Schlüsselschema (externe ID, Prüfsummen-Ausschlüsse)" />
    @endif
</section>

<section class="hub-section" aria-labelledby="map-versions">
    <h2 id="map-versions">Versionen vergleichen</h2>
    <form method="get" action="{{ route('admin.mapping.compare') }}" class="hub-form hub-form-row">
        <input type="hidden" name="a" value="{{ $mapping->getKey() }}">
        <div>
            <label for="cmp-b">v{{ $mapping->getAttribute('version') }} vergleichen mit</label>
            <select id="cmp-b" name="b">
                @foreach ($versions as $version)
                    @continue($version->getKey() === $mapping->getKey())
                    <option value="{{ $version->getKey() }}">v{{ $version->getAttribute('version') }} ({{ $version->getAttribute('status') }})</option>
                @endforeach
            </select>
        </div>
        <div class="hub-form-actions"><button type="submit" class="hub-button hub-button-secondary" @disabled($versions->count() < 2)>Vergleichen</button></div>
    </form>
</section>
@endsection
