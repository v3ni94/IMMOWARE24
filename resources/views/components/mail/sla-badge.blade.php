@props([
    // green, yellow, red
    'color' => 'green',
    // Text, z. B. "Antwort bis 14:30" oder Restzeit
    'label' => null,
    // running, paused, met, breached, cancelled
    'state' => 'running',
])
@php
    $allowed = ['green', 'yellow', 'red'];
    $level = in_array($color, $allowed, true) ? $color : 'green';
    $symbol = match ($level) { 'red' => '■', 'yellow' => '▲', default => '●' };
    $text = $label ?? match ($level) { 'red' => 'Überfällig', 'yellow' => 'Bald fällig', default => 'Im Ziel' };
    $stateText = match ($state) { 'paused' => ' (pausiert)', 'met' => ' (erfüllt)', 'breached' => ' (verletzt)', 'cancelled' => ' (aufgehoben)', default => '' };
@endphp
<span {{ $attributes->class(['hub-badge', 'mail-sla', 'mail-sla-'.$level]) }} role="status"><span aria-hidden="true">{{ $symbol }}</span> {{ $text }}{{ $stateText }}</span>
