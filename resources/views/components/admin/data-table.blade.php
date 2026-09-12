@props([
    // Spaltenüberschriften als Liste oder als assoziatives Array key => Label
    'columns' => [],
    // Iterable der Zeilen (Collection, Paginator oder Array), nur für Leerprüfung und Pagination;
    // die <tr>-Zeilen rendert der Aufrufer im Slot
    'rows' => [],
    // Paginator mit links(), optional
    'paginator' => null,
    'empty' => 'Keine Einträge vorhanden.',
    'caption' => null,
])
@php
    $pager = $paginator ?? ($rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows : null);
    $items = $rows instanceof \Illuminate\Contracts\Pagination\Paginator ? $rows->items() : $rows;
    $count = is_countable($items) ? count($items) : iterator_count($items);
@endphp
<div {{ $attributes->class(['hub-table-wrapper']) }}>
    <table class="hub-table">
        @if ($caption)
            <caption>{{ $caption }}</caption>
        @endif
        @if ($columns !== [])
            <thead>
            <tr>
                @foreach ($columns as $key => $column)
                    <th scope="col">{{ $column }}</th>
                @endforeach
            </tr>
            </thead>
        @endif
        <tbody>
        @if ($count === 0)
            <tr><td class="hub-table-empty" colspan="{{ max(1, count($columns)) }}">{{ $empty }}</td></tr>
        @else
            {{ $slot }}
        @endif
        </tbody>
    </table>
    @if ($pager !== null && $pager->hasPages())
        <nav class="hub-pagination" aria-label="Seiten">
            @if ($pager->onFirstPage())
                <span class="hub-pagination-link is-disabled">Zurück</span>
            @else
                <a class="hub-pagination-link" href="{{ $pager->previousPageUrl() }}" rel="prev">Zurück</a>
            @endif
            <span class="hub-pagination-info">
                Seite {{ $pager->currentPage() }}@if ($pager instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) von {{ $pager->lastPage() }}, {{ number_format($pager->total(), 0, ',', '.') }} Einträge @endif
            </span>
            @if ($pager->hasMorePages())
                <a class="hub-pagination-link" href="{{ $pager->nextPageUrl() }}" rel="next">Weiter</a>
            @else
                <span class="hub-pagination-link is-disabled">Weiter</span>
            @endif
        </nav>
    @endif
</div>
