@props([
    // assoziatives Array Label => Wert; Werte null werden als "keine Angabe" gezeigt
    'items' => [],
    'title' => null,
])
<dl {{ $attributes->class(['hub-kv']) }}>
    @if ($title)
        <div class="hub-kv-title">{{ $title }}</div>
    @endif
    @foreach ($items as $label => $value)
        <div class="hub-kv-row">
            <dt>{{ $label }}</dt>
            <dd>
                @if ($value instanceof \Illuminate\Contracts\Support\Htmlable)
                    {{ $value }}
                @elseif ($value instanceof \DateTimeInterface)
                    <time datetime="{{ $value->format(DATE_ATOM) }}">{{ $value->format('d.m.Y H:i:s') }} UTC</time>
                @elseif (is_bool($value))
                    {{ $value ? 'ja' : 'nein' }}
                @elseif ($value === null || $value === '')
                    <span class="hub-muted">keine Angabe</span>
                @elseif (is_array($value))
                    <code class="hub-mono">{{ json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code>
                @else
                    {{ $value }}
                @endif
            </dd>
        </div>
    @endforeach
    {{ $slot }}
</dl>
