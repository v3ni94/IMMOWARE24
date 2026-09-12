@extends('layouts.mail', ['title' => $title])
@php use App\Modules\MailUi\Services\OrgSettings; @endphp
@section('content')
    @include('mail::admin._tabs')
    <ol class="mail-wizard-steps">
        @foreach ($steps as $key => $label)
            <li @class(['is-active' => $key === $step, 'is-done' => in_array($key, $done, true)])>
                <a href="{{ route('mail.admin.setup.show', ['step' => $key]) }}" @if ($key === $step) aria-current="step" @endif><span aria-hidden="true">{{ in_array($key, $done, true) ? '●' : '○' }}</span> {{ $loop->iteration }}. {{ $label }}{{ in_array($key, $done, true) ? ' (erledigt)' : '' }}</a>
            </li>
        @endforeach
    </ol>

    <section class="hub-card mail-wizard">
        @if ($step === 'organization')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'organization']) }}" class="hub-form">
                @csrf
                <label for="w-org">Anzeigename der Organisation</label><input id="w-org" name="display_name" type="text" value="{{ old('display_name', $settings['organization']['display_name'] ?? $organization?->name) }}" required>
                <label for="w-le">Standardgesellschaft</label><select id="w-le" name="default_legal_entity_code">@foreach ($legalEntities as $code => $name)<option value="{{ $code }}" @selected(($settings['organization']['default_legal_entity_code'] ?? '') === $code)>{{ $name }}</option>@endforeach</select>
                <p class="hub-hint">Zeitzone der Darstellung: Europe/Berlin, Speicherung UTC.</p>
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
        @elseif ($step === 'team')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'team']) }}" class="hub-form">
                @csrf
                <label for="w-team">Teamname</label><input id="w-team" name="name" type="text" value="{{ old('name', $teams->first()?->name ?? 'Verwaltung') }}" required>
                <label for="w-lead">Teamleitung</label><select id="w-lead" name="lead_user_id"><option value="">offen</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                <label for="w-esc">Eskalationsempfänger</label><select id="w-esc" name="escalation_user_id"><option value="">offen</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                <label for="w-members">Mitglieder (Sachbearbeitung)</label><select id="w-members" name="members[]" multiple size="6">@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
            @if ($teams->isNotEmpty())<p class="hub-small">Vorhandene Teams: {{ $teams->pluck('name')->implode(', ') }}</p>@endif
        @elseif ($step === 'hours')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'hours']) }}" class="hub-form">
                @csrf
                <label for="w-cal">Name des Arbeitskalenders</label><input id="w-cal" name="name" type="text" value="{{ $calendar?->name ?? 'Standard' }}" required>
                @foreach ($days as $key => $label)
                    @php $existing = $calendar?->weekly_hours_json[$key] ?? null; $default = in_array($key, ['mon', 'tue', 'wed', 'thu', 'fri'], true); @endphp
                    <div class="hub-form-row mail-hours-row">
                        <div><label for="w-{{ $key }}-s">{{ $label }} von</label><input id="w-{{ $key }}-s" name="hours[{{ $key }}][start]" type="time" value="{{ $existing['start'] ?? ($default ? '08:00' : '') }}"></div>
                        <div><label for="w-{{ $key }}-e">bis</label><input id="w-{{ $key }}-e" name="hours[{{ $key }}][end]" type="time" value="{{ $existing['end'] ?? ($default ? '16:30' : '') }}"></div>
                    </div>
                @endforeach
                <p class="hub-hint">Vorgabe Mo bis Fr 08:00 bis 16:30, zu bestätigen.</p>
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
        @elseif ($step === 'holidays')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'holidays']) }}" class="hub-form">
                @csrf
                <label for="w-hol">Feiertage, je Zeile "TT.MM.JJJJ; Bezeichnung"</label>
                <textarea id="w-hol" name="holidays" rows="10" placeholder="01.01.2027; Neujahr&#10;03.10.2026; Tag der Deutschen Einheit"></textarea>
                <label for="w-region">Region</label><input id="w-region" name="region" type="text" value="NW" maxlength="8">
                <p class="hub-hint">Feiertage werden nicht automatisch ermittelt; die Liste ist zu prüfen. Vorhanden: {{ $calendar?->holidays()->count() ?? 0 }}.</p>
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
        @elseif ($step === 'mailboxes')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'mailboxes']) }}" class="hub-form">
                @csrf
                <label for="w-mb-label">Bezeichnung</label><input id="w-mb-label" name="label" type="text" value="{{ old('label') }}" required>
                <label for="w-mb-mail">Gmail-Adresse des Postfachs (kein Alias)</label><input id="w-mb-mail" name="email_address" type="email" value="{{ old('email_address') }}" required>
                <label for="w-mb-team">Team</label><select id="w-mb-team" name="team_id"><option value="">keins</option>@foreach ($teams as $team)<option value="{{ $team->getKey() }}">{{ $team->name }}</option>@endforeach</select>
                <label for="w-mb-le">Gesellschaft</label><select id="w-mb-le" name="legal_entity_code">@foreach ($legalEntities as $code => $name)<option value="{{ $code }}">{{ $name }}</option>@endforeach</select>
                <label for="w-mb-alias">Aliasse (Send-as-Adressen, Komma getrennt, gleiche Gesellschaft)</label><input id="w-mb-alias" name="aliases" type="text" value="{{ old('aliases') }}">
                <p class="hub-hint">Ein Alias ist kein Postfach. Die OAuth-Verbindung erfolgt später unter Integrationen; bis dahin "Nicht eingerichtet".</p>
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
            <ul class="mail-list">@foreach ($mailboxes as $mailbox)<li>{{ $mailbox->label }} {{ $mailbox->email_address }} ({{ $mailbox->legal_entity_code }}), Aliasse: {{ $mailbox->aliases->pluck('send_as_email')->implode(', ') ?: 'keine' }}</li>@endforeach</ul>
        @elseif ($step === 'responsibilities')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'responsibilities']) }}" class="hub-form">
                @csrf
                @if ($properties->isEmpty())
                    <p class="hub-muted">Keine Objekte im Spiegel. Schritt kann übersprungen und später unter Administration nachgeholt werden.</p>
                @else
                    <label for="w-prop">Objekt</label><select id="w-prop" name="property_id"><option value="">keins</option>@foreach ($properties as $property)<option value="{{ $property->getKey() }}">{{ $property->name }}{{ $property->city ? ', '.$property->city : '' }}</option>@endforeach</select>
                    <label for="w-r-team">Team</label><select id="w-r-team" name="team_id"><option value="">keins</option>@foreach ($teams as $team)<option value="{{ $team->getKey() }}">{{ $team->name }}</option>@endforeach</select>
                    <label for="w-r-user">Person</label><select id="w-r-user" name="user_id"><option value="">keine</option>@foreach ($users as $u)<option value="{{ $u->getKey() }}">{{ $u->name }}</option>@endforeach</select>
                @endif
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
            <p class="hub-small">Hinterlegt: {{ $responsibilities->count() }} Zuständigkeiten.</p>
        @elseif ($step === 'alerts')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'alerts']) }}" class="hub-form">
                @csrf
                <label for="w-al-users">Alarmempfänger (Personen)</label><select id="w-al-users" name="user_ids[]" multiple size="6">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::ESCALATION_RECIPIENTS]['user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <label for="w-al-mails">Zusätzliche E-Mail-Adressen</label><input id="w-al-mails" name="emails" type="text" value="{{ implode(', ', (array) ($settings[OrgSettings::ESCALATION_RECIPIENTS]['emails'] ?? [])) }}">
                <label for="w-al-phone">Telefonhinweis</label><input id="w-al-phone" name="phone_note" type="text" value="{{ $settings[OrgSettings::ESCALATION_RECIPIENTS]['phone_note'] ?? '' }}">
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
        @elseif ($step === 'approvers')
            <form method="post" action="{{ route('mail.admin.setup.store', ['step' => 'approvers']) }}" class="hub-form">
                @csrf
                <label for="w-ap-std">Standardfreigaben</label><select id="w-ap-std" name="standard_user_ids[]" multiple size="6">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::APPROVERS]['standard_user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <label for="w-ap-bank">Bankdatenfreigaben</label><select id="w-ap-bank" name="bank_user_ids[]" multiple size="6">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::APPROVERS]['bank_user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <p class="hub-hint">Vier Augen: Autor und Freigebende sind nie dieselbe Person. Wirksam nur mit Team-Rolle approver oder lead.</p>
                <button type="submit" class="hub-button mail-primary">Speichern und weiter</button>
            </form>
        @else
            <h2>Feature-Flags (nur Anzeige)</h2>
            <table class="hub-table"><thead><tr><th scope="col">Flag</th><th scope="col">Zustand</th></tr></thead><tbody>
                @foreach ($flags as $flag => $enabled)<tr><td><code>{{ $flag }}</code></td><td><span class="hub-badge {{ $enabled ? 'hub-badge-warn' : 'hub-badge-disabled' }}">{{ $enabled ? 'aktiv' : 'aus' }}</span></td></tr>@endforeach
            </tbody></table>
            <p class="hub-hint">Flags werden nur über die Umgebung gesetzt und erst nach dokumentierter Freigabe der Geschäftsführung aktiviert (docs/mail/10-implementierungsliste.md). Die Einrichtung ist damit abgeschlossen; alle Schritte sind jederzeit unter Administration änderbar.</p>
        @endif
        <div class="hub-form-actions">
            @if ($previous)<a class="hub-button hub-button-link" href="{{ route('mail.admin.setup.show', ['step' => $previous]) }}">Zurück</a>@endif
            @if ($next)<a class="hub-button hub-button-link" href="{{ route('mail.admin.setup.show', ['step' => $next]) }}">Überspringen</a>@endif
        </div>
    </section>
@endsection
