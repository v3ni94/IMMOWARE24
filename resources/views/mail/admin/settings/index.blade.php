@extends('layouts.mail', ['title' => $title])
@php use App\Modules\MailUi\Services\OrgSettings; @endphp
@section('content')
    @include('mail::admin._tabs')
    <div class="hub-card-grid">
        <section class="hub-card">
            <h2>Eskalationsempfänger</h2>
            <form method="post" action="{{ route('mail.admin.settings.update', OrgSettings::ESCALATION_RECIPIENTS) }}" class="hub-form">
                @csrf @method('PUT')
                <label for="es-users">Personen</label>
                <select id="es-users" name="user_ids[]" multiple size="5">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::ESCALATION_RECIPIENTS]['user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <label for="es-mails">Zusätzliche E-Mail-Adressen (Komma getrennt)</label><input id="es-mails" name="emails" type="text" value="{{ implode(', ', (array) ($settings[OrgSettings::ESCALATION_RECIPIENTS]['emails'] ?? [])) }}">
                <label for="es-phone">Telefonhinweis (kein Versandkanal)</label><input id="es-phone" name="phone_note" type="text" value="{{ $settings[OrgSettings::ESCALATION_RECIPIENTS]['phone_note'] ?? '' }}">
                <button type="submit" class="hub-button">Speichern</button>
            </form>
        </section>
        <section class="hub-card">
            <h2>Bereitschaft (Notfälle P0)</h2>
            <form method="post" action="{{ route('mail.admin.settings.update', OrgSettings::ON_CALL) }}" class="hub-form">
                @csrf @method('PUT')
                <label class="hub-checkbox"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked((bool) ($settings[OrgSettings::ON_CALL]['enabled'] ?? false))> Bereitschaft eingerichtet</label>
                <label for="oc-users">Bereitschaftspersonen</label>
                <select id="oc-users" name="user_ids[]" multiple size="5">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::ON_CALL]['user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <label for="oc-hours">Zeiten</label><input id="oc-hours" name="hours_note" type="text" value="{{ $settings[OrgSettings::ON_CALL]['hours_note'] ?? '' }}" placeholder="z. B. außerhalb Mo bis Fr 08:00 bis 16:30">
                <label for="oc-phone">Erreichbarkeit</label><input id="oc-phone" name="phone_note" type="text" value="{{ $settings[OrgSettings::ON_CALL]['phone_note'] ?? '' }}">
                <button type="submit" class="hub-button">Speichern</button>
            </form>
        </section>
        <section class="hub-card">
            <h2>Kostenlimit KI</h2>
            <form method="post" action="{{ route('mail.admin.settings.update', OrgSettings::AI_BUDGET) }}" class="hub-form">
                @csrf @method('PUT')
                <label for="ai-month">Monatslimit in Cent</label><input id="ai-month" name="monthly_limit_cents" type="number" min="0" value="{{ $settings[OrgSettings::AI_BUDGET]['monthly_limit_cents'] ?? 0 }}" required>
                <label for="ai-case">Limit je Vorgang in Cent</label><input id="ai-case" name="per_case_limit_cents" type="number" min="0" value="{{ $settings[OrgSettings::AI_BUDGET]['per_case_limit_cents'] ?? 0 }}">
                <label for="ai-warn">Warnung ab Prozent</label><input id="ai-warn" name="warn_percent" type="number" min="1" max="99" value="{{ $settings[OrgSettings::AI_BUDGET]['warn_percent'] ?? 80 }}">
                <p class="hub-hint">KI ist {{ ($flags['ai'] ?? false) ? 'aktiv (MAIL_AI_ENABLED=true)' : 'aus (MAIL_AI_ENABLED=false)' }}. Vorschläge wirken nie ohne Bestätigung.</p>
                <button type="submit" class="hub-button">Speichern</button>
            </form>
        </section>
        <section class="hub-card">
            <h2>Aufbewahrung (Tage)</h2>
            <form method="post" action="{{ route('mail.admin.settings.update', OrgSettings::RETENTION) }}" class="hub-form">
                @csrf @method('PUT')
                @foreach (['messages_days' => 'Nachrichten', 'attachments_days' => 'Anhänge', 'closed_cases_days' => 'Geschlossene Vorgänge', 'ai_runs_days' => 'KI-Läufe'] as $key => $label)
                    <label for="rt-{{ $key }}">{{ $label }}</label><input id="rt-{{ $key }}" name="{{ $key }}" type="number" min="7" max="3650" value="{{ $settings[OrgSettings::RETENTION][$key] ?? '' }}" required>
                @endforeach
                <p class="hub-hint">Fristen sind mit Steuerberater und Datenschutz abzustimmen; die Anwendung des Löschlaufs übernimmt ein separater Befehl.</p>
                <button type="submit" class="hub-button">Speichern</button>
            </form>
        </section>
        <section class="hub-card">
            <h2>Freigabeberechtigte</h2>
            <form method="post" action="{{ route('mail.admin.settings.update', OrgSettings::APPROVERS) }}" class="hub-form">
                @csrf @method('PUT')
                <label for="apv-std">Standardfreigaben</label>
                <select id="apv-std" name="standard_user_ids[]" multiple size="5">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::APPROVERS]['standard_user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <label for="apv-bank">Bankdatenfreigaben</label>
                <select id="apv-bank" name="bank_user_ids[]" multiple size="5">@foreach ($users as $u)<option value="{{ $u->getKey() }}" @selected(in_array($u->getKey(), (array) ($settings[OrgSettings::APPROVERS]['bank_user_ids'] ?? []), true))>{{ $u->name }}</option>@endforeach</select>
                <p class="hub-hint">Wirksam wird die Freigabe nur mit Systemrolle, Team-Rolle approver oder lead und Postfachrecht.</p>
                <button type="submit" class="hub-button">Speichern</button>
            </form>
        </section>
        <section class="hub-card">
            <h2>Feature-Flags (nur Anzeige)</h2>
            <ul>@foreach ($flags as $flag => $enabled)<li><code>{{ $flag }}</code>: <span class="hub-badge {{ $enabled ? 'hub-badge-warn' : 'hub-badge-disabled' }}">{{ $enabled ? 'aktiv' : 'aus' }}</span></li>@endforeach</ul>
            <p class="hub-hint">Flags werden ausschließlich über die Umgebung gesetzt und erfordern eine dokumentierte Freigabe der Geschäftsführung.</p>
        </section>
    </div>
@endsection
