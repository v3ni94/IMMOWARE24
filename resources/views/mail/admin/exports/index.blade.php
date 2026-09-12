@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; @endphp
@section('content')
    @include('mail::admin._tabs')
    <div class="hub-card-grid">
        <section class="hub-card">
            <h2>Auskunft zu einem Kontakt anfordern</h2>
            <form method="post" action="{{ route('mail.admin.exports.store') }}" class="hub-form">
                @csrf
                <label for="ex-contact">Kontakt-ID (contacts.id, Spiegel Immoware24)</label>
                <input id="ex-contact" name="contact_id" type="number" min="1" value="{{ old('contact_id') }}" required>
                @error('contact_id')<p class="hub-error">{{ $message }}</p>@enderror
                <label for="ex-format">Format</label>
                <select id="ex-format" name="format">
                    <option value="json" @selected(old('format', 'json') === 'json')>JSON (eine Datei)</option>
                    <option value="csv" @selected(old('format') === 'csv')>CSV (je Bereich eine Datei)</option>
                </select>
                <p class="hub-hint">Der Export umfasst Nachrichten, Vorgänge, Aufgaben und Aktionspläne zu diesem Kontakt. Bankdaten sind maskiert. Die Erstellung läuft im Hintergrund (Queue low), das Ergebnis liegt auf der konfigurierten Speicherdisk und wird auditiert. Weitergabe an den Betroffenen nur nach Identitätsprüfung und Freigabe durch die Geschäftsführung.</p>
                <button type="submit" class="hub-button">Export anfordern</button>
            </form>
        </section>
        <section class="hub-card">
            <h2>Letzte Exporte</h2>
            @if ($history->isEmpty())
                <p class="hub-muted">Noch kein Auskunftsexport angefordert.</p>
            @else
                <div class="hub-table-wrapper">
                    <table class="hub-table">
                        <thead><tr><th scope="col">Zeitpunkt</th><th scope="col">Ereignis</th><th scope="col">Kontakt</th><th scope="col">Kennung</th><th scope="col">Details</th></tr></thead>
                        <tbody>
                        @foreach ($history as $row)
                            @php $after = (array) ($row->after_json ?? []); @endphp
                            <tr>
                                <td>{{ GermanDate::formatDateTime($row->occurred_at) }}</td>
                                <td>{{ match ($row->action) { 'mail.export.subject_access_requested' => 'Angefordert', 'mail.export.subject_access_completed' => 'Fertiggestellt', default => 'Fehlgeschlagen' } }}</td>
                                <td>{{ $after['contact_id'] ?? '' }}</td>
                                <td><code>{{ $after['export_uuid'] ?? '' }}</code></td>
                                <td>@if (isset($after['counts']))@foreach ((array) $after['counts'] as $k => $v)<span class="hub-badge">{{ $k }}: {{ $v }}</span> @endforeach @endif{{ $after['path'] ?? '' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
