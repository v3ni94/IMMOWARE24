@php
    $value = static fn (string $field, mixed $default = null) => old($field, $connection?->getAttribute($field) ?? $default);
    $credentials = is_array($connection?->getAttribute('credentials')) ? $connection->getAttribute('credentials') : [];
@endphp
<form method="post" action="{{ $action }}" class="hub-form hub-card">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div class="hub-form-row">
        <div>
            <label for="name">Bezeichnung</label>
            <input id="name" type="text" name="name" maxlength="120" required value="{{ $value('name') }}">
        </div>
        <div>
            <label for="connector_type">Connector-Typ</label>
            <select id="connector_type" name="connector_type" required>
                @foreach ($connectorTypes as $key => $label)
                    <option value="{{ $key }}" @selected($value('connector_type', 'webdav_documents') === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="purpose">Zweck</label>
            <select id="purpose" name="purpose">
                <option value="read" @selected($value('purpose', 'read') === 'read')>Lesen</option>
                <option value="write" @selected($value('purpose', 'read') === 'write')>Schreiben (Posteingang, create-only)</option>
            </select>
        </div>
    </div>

    <label for="base_url">Freigabe-URL (https)</label>
    <input id="base_url" type="url" name="base_url" maxlength="2048" placeholder="https://..." value="{{ $value('base_url') }}">
    <p class="hub-help">Freigabe-Link aus dem Konfigurationsportal config.dav.immoware24.de. Wird verschlüsselt gespeichert.</p>

    <div class="hub-form-row">
        <div>
            <label for="technical_user_id">Technischer Nutzer (optional)</label>
            <select id="technical_user_id" name="technical_user_id">
                <option value="">keiner, Zugangsdaten direkt an der Connection</option>
                @foreach ($technicalUsers as $id => $label)
                    <option value="{{ $id }}" @selected((string) $value('technical_user_id') === (string) $id)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="username">Benutzername</label>
            <input id="username" type="text" name="username" maxlength="200" autocomplete="off" value="{{ old('username', $credentials['username'] ?? '') }}">
        </div>
        <div>
            <label for="password">Freigabe-Passwort</label>
            <input id="password" type="password" name="password" maxlength="1024" autocomplete="new-password" placeholder="{{ $connection !== null && isset($credentials['password']) ? 'hinterlegt, leer lassen für unverändert' : '' }}">
            <p class="hub-help">Schreibfeld. Das Passwort wird nie angezeigt und nur bei Eingabe überschrieben.</p>
        </div>
    </div>

    <div class="hub-form-row">
        <div>
            <label for="poll_interval_seconds">Abrufintervall (Sekunden)</label>
            <input id="poll_interval_seconds" type="number" name="poll_interval_seconds" min="60" max="86400" required value="{{ $value('poll_interval_seconds', 1800) }}">
        </div>
        <div>
            <label for="rate_limit_rps">Rate-Limit (Requests je Sekunde)</label>
            <input id="rate_limit_rps" type="number" step="0.25" min="0.25" max="10" name="rate_limit_rps" required value="{{ $value('rate_limit_rps', '2.00') }}">
        </div>
        <div>
            <label for="allowed_write_prefix">Erlaubter Schreibpfad (nur Schreib-Connection)</label>
            <input id="allowed_write_prefix" type="text" name="allowed_write_prefix" maxlength="512" value="{{ $value('allowed_write_prefix') }}">
        </div>
    </div>

    <div class="hub-form-actions">
        <button type="submit" class="hub-button">{{ $submitLabel }}</button>
        <a class="hub-button hub-button-secondary" href="{{ $connection !== null ? route('admin.connections.show', ['id' => $connection->getKey()]) : route('admin.connections.index') }}">Abbrechen</a>
    </div>
</form>
