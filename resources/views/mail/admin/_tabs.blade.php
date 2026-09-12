@php $current = request()->route()?->getName() ?? ''; @endphp
<nav class="mail-tabs" aria-label="Administration">
    @foreach ((array) config('hub.mailui.admin_sections', []) as $section)
        @if (\Illuminate\Support\Facades\Route::has($section['route']))
            <a href="{{ route($section['route']) }}" @class(['mail-tab', 'is-active' => str_starts_with($current, substr($section['route'], 0, (int) strrpos($section['route'], '.')))]) @if (str_starts_with($current, substr($section['route'], 0, (int) strrpos($section['route'], '.')))) aria-current="page" @endif>{{ $section['label'] }}</a>
        @endif
    @endforeach
</nav>
