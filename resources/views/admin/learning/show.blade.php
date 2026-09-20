@extends('layouts.admin', ['title' => 'Lernlauf #'.$run->getKey()])
@php
    use App\Core\Support\GermanDate;
    $fmt = static fn ($value) => $value !== null ? GermanDate::formatDateTime($value) : '—';
    $pretty = static fn ($value) => $value === null ? 'keine Angabe' : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
@endphp
@section('content')
    <p><a href="{{ route('admin.learning.index') }}">&larr; Zurück zur Übersicht</a></p>

    <div class="hub-card">
        <dl class="hub-kv">
            <dt>Art</dt><dd>{{ $run->kind()->label() }}</dd>
            <dt>Status</dt><dd>{{ $run->status()->label() }}</dd>
            <dt>Verbindung</dt><dd>{{ $run->connection?->name ?? '—' }}</dd>
            <dt>Ausgelöst durch</dt><dd>{{ $run->triggeredBy?->name ?? 'Konsole' }}</dd>
            <dt>Gestartet</dt><dd>{{ $fmt($run->started_at) }}</dd>
            <dt>Abgeschlossen</dt><dd>{{ $fmt($run->finished_at) }}</dd>
            <dt>Vorheriger Lauf</dt><dd>{{ $run->previousRun ? sprintf('#%d (%s)', $run->previousRun->getKey(), $fmt($run->previousRun->created_at)) : 'keiner (erster Lauf)' }}</dd>
        </dl>
        @if ($run->getAttribute('error_message'))
            <div class="hub-alert hub-alert-error" role="alert">{{ $run->getAttribute('error_message') }}</div>
        @endif
    </div>

    @if ($run->getAttribute('diff_json') !== null)
        <div class="hub-card">
            <h2>Veränderung zum vorigen Lauf</h2>
            <pre class="hub-mono">{{ $pretty($run->getAttribute('diff_json')) }}</pre>
        </div>
    @endif

    @if ($run->aiSuggestion)
        <div class="hub-card">
            <h2>KI-Vorschlag</h2>
            <p>Status: {{ $run->aiSuggestion->status }}. Dies ist ein Vorschlag zur Prüfung, keine automatische Änderung. Eine Übernahme in die Konfigurationsdatei bleibt ein manueller Schritt einer Person mit Zugriff auf das Repository.</p>
            <pre class="hub-mono">{{ $pretty($run->aiSuggestion->payload_json) }}</pre>
            @if ($run->aiSuggestion->status === 'proposed')
                <div class="hub-form-actions">
                    <form method="post" action="{{ route('admin.learning.decide', ['run' => $run->getKey()]) }}" class="hub-inline-form">
                        @csrf
                        <input type="hidden" name="decision" value="accepted">
                        <button type="submit" class="hub-button">Vorschlag übernehmen (markieren)</button>
                    </form>
                    <form method="post" action="{{ route('admin.learning.decide', ['run' => $run->getKey()]) }}" class="hub-inline-form">
                        @csrf
                        <input type="hidden" name="decision" value="rejected">
                        <button type="submit" class="hub-button hub-button-secondary">Vorschlag ablehnen</button>
                    </form>
                </div>
            @endif
        </div>
    @endif

    <div class="hub-card">
        <h2>Rohbefunde</h2>
        <p class="hub-help">Pfade und Feldnamen können personenbezogene Daten enthalten (wie im Dokumentenspiegel selbst). Nur für Rollen mit dem Recht learning.manage sichtbar.</p>
        <pre class="hub-mono">{{ $pretty($run->getAttribute('facts_json')) }}</pre>
    </div>
@endsection
