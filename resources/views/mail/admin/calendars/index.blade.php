@extends('layouts.mail', ['title' => $title])
@section('content')
    @include('mail::admin._tabs')
    <p class="hub-hint">Zeitzone Europe/Berlin, Speicherung UTC. Arbeitszeiten und Feiertage sind mit der Geschäftsführung zu bestätigen; die Uhren des Moduls SLA rechnen damit.</p>
    <div class="hub-card-grid">
        @foreach ($calendars as $calendar)
            <section class="hub-card" aria-label="Kalender {{ $calendar->name }}">
                <h2>{{ $calendar->name }} @if ($calendar->is_default)<span class="hub-badge hub-badge-ok">Standard</span>@endif</h2>
                <dl class="hub-kv">
                    @foreach ($days as $key => $label)
                        <div class="hub-kv-row"><dt>{{ $label }}</dt><dd>{{ isset($calendar->weekly_hours_json[$key]) ? $calendar->weekly_hours_json[$key]['start'].' bis '.$calendar->weekly_hours_json[$key]['end'] : 'arbeitsfrei' }}</dd></div>
                    @endforeach
                </dl>
                <h3>Feiertage ({{ $calendar->holidays->count() }})</h3>
                <ul class="mail-list">
                    @forelse ($calendar->holidays->sortBy('holiday_date') as $holiday)
                        <li>{{ \App\Core\Support\GermanDate::format($holiday->holiday_date) }} {{ $holiday->label }} <small class="hub-muted">({{ $holiday->region }})</small>
                            <form method="post" action="{{ route('mail.admin.calendars.holidays.destroy', [$calendar, $holiday]) }}" class="hub-inline-form">@csrf @method('DELETE')<button type="submit" class="hub-button hub-button-danger hub-button-small">Entfernen</button></form></li>
                    @empty
                        <li class="hub-muted">Keine Feiertage hinterlegt.</li>
                    @endforelse
                </ul>
                <form method="post" action="{{ route('mail.admin.calendars.holidays.store', $calendar) }}" class="hub-form">
                    @csrf
                    <div class="hub-form-row">
                        <div><label for="h-date-{{ $calendar->getKey() }}">Datum (TT.MM.JJJJ)</label><input id="h-date-{{ $calendar->getKey() }}" name="holiday_date" type="text" placeholder="01.11.2026" required></div>
                        <div><label for="h-label-{{ $calendar->getKey() }}">Bezeichnung</label><input id="h-label-{{ $calendar->getKey() }}" name="label" type="text" required></div>
                        <div><label for="h-region-{{ $calendar->getKey() }}">Region</label><input id="h-region-{{ $calendar->getKey() }}" name="region" type="text" value="NW" maxlength="8"></div>
                    </div>
                    <button type="submit" class="hub-button hub-button-small">Feiertag speichern</button>
                </form>
            </section>
        @endforeach
        <section class="hub-card">
            <h2>Arbeitskalender anlegen oder ändern</h2>
            <form method="post" action="{{ route('mail.admin.calendars.store') }}" class="hub-form">
                @csrf
                <label for="wc-name">Name</label><input id="wc-name" name="name" type="text" value="Standard" required>
                @foreach ($days as $key => $label)
                    <div class="hub-form-row mail-hours-row">
                        <div><label for="wc-{{ $key }}-s">{{ $label }} von</label><input id="wc-{{ $key }}-s" name="hours[{{ $key }}][start]" type="time" value="{{ in_array($key, ['mon', 'tue', 'wed', 'thu', 'fri'], true) ? '08:00' : '' }}"></div>
                        <div><label for="wc-{{ $key }}-e">bis</label><input id="wc-{{ $key }}-e" name="hours[{{ $key }}][end]" type="time" value="{{ in_array($key, ['mon', 'tue', 'wed', 'thu', 'fri'], true) ? '16:30' : '' }}"></div>
                    </div>
                @endforeach
                <label class="hub-checkbox"><input type="checkbox" name="is_default" value="1" checked> Standardkalender</label>
                <button type="submit" class="hub-button">Kalender speichern</button>
            </form>
        </section>
    </div>
@endsection
