@props([
    // Modell mit Herkunftsblock (HasExternalIdentity) oder null; Einzelwerte überschreiben das Modell
    'model' => null,
    'source' => null,
    'externalId' => null,
    'externalParentId' => null,
    'lastSyncedAt' => null,
    'firstSyncedAt' => null,
    'checksum' => null,
    'syncVersion' => null,
    'connector' => null,
    'mappingVersion' => null,
    'staleSince' => null,
    'compact' => false,
])
@php
    $get = static fn (string $attribute) => $model?->getAttribute($attribute);
    $source ??= $get('source_system');
    $externalId ??= $get('external_id');
    $externalParentId ??= $get('external_parent_id');
    $lastSyncedAt ??= $get('last_synced_at');
    $firstSyncedAt ??= $get('first_synced_at');
    $checksum ??= $get('checksum');
    $syncVersion ??= $get('sync_version');
    $staleSince ??= $get('stale_since');
    $mappingVersion ??= $get('mapping_version');
    if ($connector === null && $model !== null && method_exists($model, 'connection') && $model->relationLoaded('connection')) {
        $connector = $model->getRelation('connection')?->getAttribute('name');
    }
    $fmt = static fn ($value) => $value instanceof \DateTimeInterface ? $value->format('d.m.Y H:i:s').' UTC' : ($value ?: null);
@endphp
<div {{ $attributes->class(['hub-provenance', 'is-compact' => $compact]) }}>
    <div class="hub-provenance-title">Herkunft</div>
    <dl>
        <div><dt>Quelle</dt><dd>{{ $source ?: 'keine Angabe' }}</dd></div>
        <div><dt>External ID</dt><dd><code class="hub-mono">{{ $externalId ?: 'keine Angabe' }}</code></dd></div>
        @if ($externalParentId)
            <div><dt>Übergeordnete ID</dt><dd><code class="hub-mono">{{ $externalParentId }}</code></dd></div>
        @endif
        <div><dt>Letzter Sync</dt><dd>{{ $fmt($lastSyncedAt) ?? 'noch nicht synchronisiert' }}</dd></div>
        @if (! $compact)
            <div><dt>Erster Sync</dt><dd>{{ $fmt($firstSyncedAt) ?? 'keine Angabe' }}</dd></div>
        @endif
        <div><dt>Connector</dt><dd>{{ $connector ?: 'keine Angabe' }}</dd></div>
        <div><dt>Mapping-Version</dt><dd>{{ $mappingVersion !== null ? 'v'.$mappingVersion : 'keine Angabe' }}</dd></div>
        @if (! $compact)
            <div><dt>Sync-Version</dt><dd>{{ $syncVersion ?? 'keine Angabe' }}</dd></div>
            <div><dt>Prüfsumme</dt><dd><code class="hub-mono">{{ $checksum ? mb_substr((string) $checksum, 0, 16).'…' : 'keine Angabe' }}</code></dd></div>
        @endif
        @if ($staleSince)
            <div><dt>Veraltet seit</dt><dd><x-admin.status-badge status="warn" :label="$fmt($staleSince)" /></dd></div>
        @endif
    </dl>
</div>
