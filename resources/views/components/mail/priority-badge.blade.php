@props([
    // App\Modules\Cases\Enums\Priority oder Wert p0..p3
    'priority',
])
@php
    $enum = $priority instanceof \App\Modules\Cases\Enums\Priority ? $priority : \App\Modules\Cases\Enums\Priority::tryFrom(strtolower((string) $priority));
    $value = $enum?->value ?? 'p3';
    $symbol = match ($value) { 'p0' => '!!', 'p1' => '!', 'p2' => '•', default => '·' };
@endphp
<span {{ $attributes->class(['hub-badge', 'mail-priority', 'mail-priority-'.$value]) }} title="{{ $enum?->label() ?? 'Unbekannt' }}"><span aria-hidden="true">{{ $symbol }}</span> {{ $enum?->short() ?? '?' }}</span>
