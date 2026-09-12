@extends('layouts.mail', ['title' => $title])
@section('content')
    @include('mail::admin._tabs')
    <p class="hub-hint">Ein Alias ist kein Postfach: Aliasse sind Absenderadressen eines Postfachs, sie haben keine eigene Verbindung und keine eigenen Rechte. Gesellschaften (HVM, MHAG) werden nie vermischt.</p>
    <div class="hub-card-grid">
        @foreach ($mailboxes as $mailbox)
            <section class="hub-card" aria-label="Postfach {{ $mailbox->label }}">
                <h2>{{ $mailbox->label }} <small class="hub-muted">{{ $mailbox->email_address }}</small></h2>
                <p><span class="hub-badge {{ $mailbox->status === 'active' && $mailbox->oauth_refresh_token !== null ? 'hub-badge-ok' : ($mailbox->status === 'degraded' ? 'hub-badge-fail' : (in_array($mailbox->status, ['revoked', 'configured', 'active'], true) ? 'hub-badge-warn' : 'hub-badge-disabled')) }}">{{ match ((string) $mailbox->status) { 'active' => $mailbox->oauth_refresh_token !== null ? 'Verbunden' : 'Reauth nötig', 'configured' => 'Autorisierung unvollständig', 'degraded' => 'Fehler', 'revoked' => 'Reauth nötig', default => 'Nicht eingerichtet' } }}</span>
                    <span class="hub-badge">{{ $legalEntities[$mailbox->legal_entity_code] ?? $mailbox->legal_entity_code }}</span> <span class="hub-badge">Team: {{ $mailbox->team?->name ?? 'keins' }}</span></p>
                <form method="post" action="{{ route('mail.admin.mailboxes.update', $mailbox) }}" class="hub-form">
                    @csrf @method('PUT')
                    <div class="hub-form-row">
                        <div><label for="mb-label-{{ $mailbox->getKey() }}">Bezeichnung</label><input id="mb-label-{{ $mailbox->getKey() }}" name="label" type="text" value="{{ $mailbox->label }}" required></div>
                        <div><label for="mb-team-{{ $mailbox->getKey() }}">Team</label><select id="mb-team-{{ $mailbox->getKey() }}" name="team_id"><option value="">keins</option>@foreach ($teams as $team)<option value="{{ $team->getKey() }}" @selected($mailbox->team_id === $team->getKey())>{{ $team->name }}</option>@endforeach</select></div>
                        <div><label for="mb-le-{{ $mailbox->getKey() }}">Gesellschaft</label><select id="mb-le-{{ $mailbox->getKey() }}" name="legal_entity_code">@foreach ($legalEntities as $code => $name)<option value="{{ $code }}" @selected($mailbox->legal_entity_code === $code)>{{ $name }}</option>@endforeach</select></div>
                        <div><label class="hub-checkbox"><input type="hidden" name="import_enabled" value="0"><input type="checkbox" name="import_enabled" value="1" @checked($mailbox->import_enabled)> Import aktiv (wirkt nur mit MAIL_IMPORT_ENABLED)</label></div>
                    </div>
                    <button type="submit" class="hub-button hub-button-secondary hub-button-small">Postfach speichern</button>
                </form>

                <h3>Aliasse (Alias ist kein Postfach)</h3>
                <ul class="mail-list">
                    @forelse ($mailbox->aliases as $alias)
                        <li>{{ $alias->send_as_email }} @if ($alias->display_name)({{ $alias->display_name }})@endif <span class="hub-badge">{{ $legalEntities[$alias->legal_entity_code] ?? $alias->legal_entity_code }}</span>
                            <span class="hub-badge {{ $alias->verification_status === 'accepted' ? 'hub-badge-ok' : 'hub-badge-warn' }}">Verifikation: {{ $alias->verification_status }}</span>@if ($alias->is_default)<span class="hub-badge hub-badge-ok">Standard</span>@endif
                            <form method="post" action="{{ route('mail.admin.mailboxes.aliases.destroy', [$mailbox, $alias]) }}" class="hub-inline-form">@csrf @method('DELETE')<button type="submit" class="hub-button hub-button-danger hub-button-small">Entfernen</button></form></li>
                    @empty
                        <li class="hub-muted">Keine Aliasse.</li>
                    @endforelse
                </ul>
                <form method="post" action="{{ route('mail.admin.mailboxes.aliases.store', $mailbox) }}" class="hub-form">
                    @csrf
                    <div class="hub-form-row">
                        <div><label for="al-mail-{{ $mailbox->getKey() }}">Send-as-Adresse</label><input id="al-mail-{{ $mailbox->getKey() }}" name="send_as_email" type="email" required></div>
                        <div><label for="al-name-{{ $mailbox->getKey() }}">Anzeigename</label><input id="al-name-{{ $mailbox->getKey() }}" name="display_name" type="text"></div>
                        <div><label for="al-le-{{ $mailbox->getKey() }}">Gesellschaft</label><select id="al-le-{{ $mailbox->getKey() }}" name="legal_entity_code">@foreach ($legalEntities as $code => $name)<option value="{{ $code }}" @selected($mailbox->legal_entity_code === $code)>{{ $name }}</option>@endforeach</select></div>
                        <div><label for="al-sig-{{ $mailbox->getKey() }}">Signaturschlüssel (CI-Skill)</label><input id="al-sig-{{ $mailbox->getKey() }}" name="signature_key" type="text" maxlength="60"></div>
                        <div><label class="hub-checkbox"><input type="checkbox" name="is_default" value="1"> Standardabsender</label></div>
                    </div>
                    <button type="submit" class="hub-button hub-button-small">Alias speichern</button>
                </form>

                <h3>Postfachrechte</h3>
                <div class="hub-table-wrapper"><table class="hub-table">
                    <thead><tr><th scope="col">Nutzer</th><th scope="col">Lesen</th><th scope="col">Entwurf</th><th scope="col">Senden</th><th scope="col">Zuweisen</th><th scope="col">Bankdaten</th></tr></thead>
                    <tbody>
                    @forelse ($mailbox->permissions as $permission)
                        <tr><td>{{ $permission->user?->name ?? 'Nutzer #'.$permission->user_id }}</td>
                            @foreach (['can_read', 'can_draft', 'can_send', 'can_assign', 'can_view_bank_data'] as $flag)<td>{{ $permission->{$flag} ? 'ja' : 'nein' }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="6" class="hub-table-empty">Keine Postfachrechte. Auch Administratoren sehen ohne Recht keine Inhalte.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <form method="post" action="{{ route('mail.admin.mailboxes.permissions.update', $mailbox) }}" class="hub-form">
                    @csrf
                    <div class="hub-form-row">
                        <div><label for="pm-user-{{ $mailbox->getKey() }}">Nutzer</label><select id="pm-user-{{ $mailbox->getKey() }}" name="user_id" required>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select></div>
                        @foreach (['can_read' => 'Lesen', 'can_draft' => 'Entwurf', 'can_send' => 'Senden', 'can_assign' => 'Zuweisen', 'can_view_bank_data' => 'Bankdaten'] as $flag => $label)
                            <div><label class="hub-checkbox"><input type="checkbox" name="{{ $flag }}" value="1"> {{ $label }}</label></div>
                        @endforeach
                    </div>
                    <button type="submit" class="hub-button hub-button-small">Postfachrecht setzen (alle leer = entfernen)</button>
                </form>
            </section>
        @endforeach

        <section class="hub-card">
            <h2>Neues Postfach</h2>
            <form method="post" action="{{ route('mail.admin.mailboxes.store') }}" class="hub-form">
                @csrf
                <label for="nm-label">Bezeichnung</label><input id="nm-label" name="label" type="text" value="{{ old('label') }}" required>
                <label for="nm-mail">E-Mail-Adresse (Gmail-Konto, kein Alias)</label><input id="nm-mail" name="email_address" type="email" value="{{ old('email_address') }}" required>
                <label for="nm-team">Team</label><select id="nm-team" name="team_id"><option value="">keins</option>@foreach ($teams as $team)<option value="{{ $team->getKey() }}">{{ $team->name }}</option>@endforeach</select>
                <label for="nm-le">Gesellschaft</label><select id="nm-le" name="legal_entity_code">@foreach ($legalEntities as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                <button type="submit" class="hub-button">Postfach anlegen</button>
                <p class="hub-hint">Nach dem Anlegen: Nicht eingerichtet. Die OAuth-Verbindung erfolgt unter Integrationen.</p>
            </form>
        </section>
    </div>
@endsection
