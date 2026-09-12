@props([
    // App\Modules\Cases\Enums\CaseStatus oder Wert
    'processing',
    // App\Modules\Cases\Enums\CommunicationStatus oder Wert
    'communication',
    // App\Modules\Actions\Enums\ActionStatus oder Wert
    'business',
])
@php
    $p = $processing instanceof \App\Modules\Cases\Enums\CaseStatus ? $processing : \App\Modules\Cases\Enums\CaseStatus::tryFrom((string) $processing);
    $c = $communication instanceof \App\Modules\Cases\Enums\CommunicationStatus ? $communication : \App\Modules\Cases\Enums\CommunicationStatus::tryFrom((string) $communication);
    $b = $business instanceof \App\Modules\Actions\Enums\ActionStatus ? $business : \App\Modules\Actions\Enums\ActionStatus::tryFrom((string) $business);
@endphp
<dl {{ $attributes->class(['mail-status-triple']) }}>
    <div class="mail-status mail-status-processing">
        <dt>Bearbeitung</dt>
        <dd><span class="hub-badge mail-status-badge mail-status-{{ $p?->value ?? 'unknown' }}">{{ $p?->label() ?? 'Unbekannt' }}</span></dd>
    </div>
    <div class="mail-status mail-status-communication">
        <dt>Kommunikation</dt>
        <dd><span class="hub-badge mail-status-badge mail-status-{{ $c?->value ?? 'unknown' }}">{{ $c?->label() ?? 'Unbekannt' }}</span></dd>
    </div>
    <div class="mail-status mail-status-business">
        <dt>Ergebnis</dt>
        <dd><span class="hub-badge mail-status-badge mail-status-{{ $b?->value ?? 'unknown' }}">{{ $b?->label() ?? 'Unbekannt' }}</span></dd>
    </div>
</dl>
