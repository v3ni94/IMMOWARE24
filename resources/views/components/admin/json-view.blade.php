@props([
    // Array, JSON-String oder null. Geheimnisse werden serverseitig über SecretMasker maskiert.
    'data' => null,
    'title' => null,
    // Zeichen, ab denen der Block eingeklappt dargestellt wird
    'collapseAfter' => 2000,
])
@inject('masker', \App\Core\Support\SecretMasker::class)
@php
    $decoded = $data;
    if (is_string($data)) {
        $tmp = json_decode($data, true);
        $decoded = json_last_error() === JSON_ERROR_NONE ? $tmp : $data;
    }
    if (is_array($decoded)) {
        $masked = $masker->maskArray($decoded);
        $text = json_encode($masked, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $text = $text === false ? '' : $text;
    } elseif ($decoded === null) {
        $text = '';
    } else {
        $text = $masker->maskString((string) $decoded);
    }
    $long = mb_strlen($text) > $collapseAfter;
@endphp
<div {{ $attributes->class(['hub-json']) }}>
    @if ($title)
        <div class="hub-json-title">{{ $title }}</div>
    @endif
    @if ($text === '')
        <p class="hub-muted">Keine Daten.</p>
    @elseif ($long)
        <details class="hub-json-collapsible">
            <summary>{{ number_format(mb_strlen($text), 0, ',', '.') }} Zeichen anzeigen</summary>
            <pre class="hub-mono hub-pre">{{ $text }}</pre>
        </details>
    @else
        <pre class="hub-mono hub-pre">{{ $text }}</pre>
    @endif
</div>
