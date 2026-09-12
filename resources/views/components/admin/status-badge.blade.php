@props([
    // ok, warn, fail, unknown, disabled
    'status' => 'unknown',
    'label' => null,
])
@php
    $allowed = ['ok', 'warn', 'fail', 'unknown', 'disabled'];
    $level = in_array($status, $allowed, true) ? $status : 'unknown';
    $text = $label ?? match ($level) {
        'ok' => 'OK',
        'warn' => 'Warnung',
        'fail' => 'Fehler',
        'disabled' => 'Deaktiviert',
        default => 'Unbekannt',
    };
@endphp
<span {{ $attributes->class(['hub-badge', 'hub-badge-'.$level]) }}>{{ $text }}</span>
