@props([
    'listTitle' => 'Liste',
    'detailTitle' => 'Detail',
    'contextTitle' => 'Kontext',
])
{{-- Dreispalten-Detail: Liste (links), Nachricht oder Vorgang (Mitte), Kontext mit Kontakt, Objekt, Aufgaben, Aktionen (rechts). --}}
<div {{ $attributes->class(['mail-three-pane']) }} data-mail-three-pane>
    <section class="mail-pane mail-pane-list" aria-label="{{ $listTitle }}">
        <h2 class="mail-pane-title">{{ $listTitle }}</h2>
        {{ $list ?? '' }}
    </section>
    <section class="mail-pane mail-pane-detail" aria-label="{{ $detailTitle }}">
        <h2 class="mail-pane-title">{{ $detailTitle }}</h2>
        {{ $detail ?? $slot }}
    </section>
    <aside class="mail-pane mail-pane-context" aria-label="{{ $contextTitle }}">
        <h2 class="mail-pane-title">{{ $contextTitle }}</h2>
        {{ $context ?? '' }}
    </aside>
</div>
