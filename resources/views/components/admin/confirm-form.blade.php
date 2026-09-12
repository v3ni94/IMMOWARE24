@props([
    'action',
    'method' => 'POST',
    // Beschriftung der Schaltfläche
    'label' => 'Ausführen',
    // Beschreibung der Folgen, wird im Dialog angezeigt
    'description' => 'Diese Aktion kann nicht rückgängig gemacht werden.',
    // Bestätigungswort, das eingegeben werden muss
    'confirmWord' => null,
    'field' => 'confirmation',
    'danger' => true,
    // Feld für einen Pflichtkommentar (Auditbegründung), null deaktiviert
    'noteField' => null,
])
@php
    $word = $confirmWord ?? (string) config('hub.admin.confirm_word', 'BESTÄTIGEN');
    $verb = strtoupper($method);
    $spoofed = ! in_array($verb, ['GET', 'POST'], true);
    $id = 'confirm-'.substr(md5($action.$label), 0, 8);
@endphp
<form method="{{ $spoofed ? 'POST' : $verb }}" action="{{ $action }}" {{ $attributes->class(['hub-confirm-form']) }} data-hub-confirm data-hub-confirm-word="{{ $word }}" data-hub-confirm-field="{{ $field }}">
    @csrf
    @if ($spoofed)
        @method($verb)
    @endif
    {{ $slot }}
    <details class="hub-confirm" id="{{ $id }}">
        <summary class="hub-button {{ $danger ? 'hub-button-danger' : 'hub-button-secondary' }}">{{ $label }}</summary>
        <div class="hub-confirm-body" role="group" aria-labelledby="{{ $id }}-title">
            <p id="{{ $id }}-title" class="hub-confirm-text">{{ $description }}</p>
            @if ($noteField)
                <label for="{{ $id }}-note">Begründung (wird im Auditlog gespeichert)</label>
                <textarea id="{{ $id }}-note" name="{{ $noteField }}" rows="2" required></textarea>
            @endif
            <label for="{{ $id }}-word">Zur Bestätigung <strong>{{ $word }}</strong> eingeben</label>
            <input id="{{ $id }}-word" type="text" name="{{ $field }}" autocomplete="off" required pattern="{{ preg_quote($word, '/') }}" data-hub-confirm-input>
            <button type="submit" class="hub-button {{ $danger ? 'hub-button-danger' : '' }}" data-hub-confirm-submit>{{ $label }} bestätigen</button>
        </div>
    </details>
</form>
