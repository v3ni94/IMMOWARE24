<label for="wh-name">Name</label>
<input id="wh-name" type="text" name="name" maxlength="120" value="{{ old('name', $endpoint?->name) }}" required>
<label for="wh-url">URL (nur https)</label>
<input id="wh-url" type="url" name="url" maxlength="1024" value="{{ old('url', $endpoint?->url) }}" placeholder="https://" required>
<fieldset>
    <legend>Ereignisse</legend>
    @php $selected = (array) old('events', $endpoint?->events ?? []); @endphp
    @foreach ($events as $event => $description)
        <label class="hub-checkbox"><input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, $selected, true))> <code class="hub-mono">{{ $event }}</code> <span class="hub-muted">{{ $description }}</span></label>
    @endforeach
</fieldset>
