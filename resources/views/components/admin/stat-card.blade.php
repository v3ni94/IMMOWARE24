@props([
    'label',
    'value' => null,
    'hint' => null,
    'status' => null,
    'href' => null,
])
@php
    $display = is_int($value) || is_float($value) ? number_format((float) $value, 0, ',', '.') : ($value ?? '0');
@endphp
<div {{ $attributes->class(['hub-stat', 'hub-stat-'.$status => $status !== null]) }}>
    <div class="hub-stat-label">{{ $label }}</div>
    <div class="hub-stat-value">
        @if ($href)
            <a href="{{ $href }}">{{ $display }}</a>
        @else
            {{ $display }}
        @endif
    </div>
    @if ($hint)
        <div class="hub-stat-hint">{{ $hint }}</div>
    @endif
    @if (trim($slot) !== '')
        <div class="hub-stat-extra">{{ $slot }}</div>
    @endif
</div>
