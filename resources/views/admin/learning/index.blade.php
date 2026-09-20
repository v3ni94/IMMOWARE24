@extends('layouts.admin', ['title' => 'Lernphase Immoware24'])
@php
    use App\Core\Support\GermanDate;
    $fmt = static fn ($value) => $value !== null ? GermanDate::formatDateTime($value) : '';
    $badge = static fn (string $status): string => match ($status) {
        'succeeded' => 'ok',
        'running' => 'unknown',
        'failed' => 'fail',
        default => 'unknown',
    };
@endphp
@section('content')
    <div class="hub-alert hub-alert-info" role="status">
        Die Lernphase erkundet lesend über WebDAV, CardDAV, CalDAV und bereits erfasste Dateiexporte die aktuelle Struktur der Immoware24-Anbindung und vergleicht sie mit dem vorigen Lauf. Sie schreibt nie nach Immoware24 und ändert keine Konfigurationsdatei automatisch. Ein Lauf kann jederzeit wiederholt werden, insbesondere nach Änderungen an Immoware24 selbst.
    </div>

    <form method="post" action="{{ route('admin.learning.store') }}" class="hub-form hub-card">
        @csrf
        <div class="hub-form-row">
            <div>
                <label for="lf-kind">Art</label>
                <select id="lf-kind" name="kind" required>
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="lf-connection">Verbindung (optional)</label>
                <select id="lf-connection" name="connection_id">
                    <option value="">Automatisch (erste passende Verbindung)</option>
                    @foreach ($kinds as $kind)
                        @foreach ($connectionsByKind[$kind->value] as $connection)
                            <option value="{{ $connection->getKey() }}" data-kind="{{ $kind->value }}">{{ $kind->label() }}: {{ $connection->name }}</option>
                        @endforeach
                    @endforeach
                </select>
            </div>
            <div>
                <label class="hub-checkbox"><input type="checkbox" name="with_ai" value="1"> Im Anschluss KI-Auswertung anstoßen</label>
            </div>
        </div>
        <div class="hub-form-actions">
            <button type="submit" class="hub-button">Lernlauf starten</button>
        </div>
        <p class="hub-help">Der Lauf startet im Hintergrund (Queue) und erscheint hier nach Abschluss mit Ergebnis. Größere WebDAV-Bäume können mehrere Minuten dauern.</p>
    </form>

    <x-admin.data-table :rows="$runs" :columns="['#', 'Art', 'Verbindung', 'Status', 'Veränderung', 'Gestartet', 'Ausgelöst durch', 'KI-Vorschlag', '']" empty="Noch kein Lernlauf ausgeführt.">
        @foreach ($runs as $run)
            <tr>
                <td>{{ $run->getKey() }}</td>
                <td>{{ $run->kind()->label() }}</td>
                <td>{{ $run->connection?->name ?? '—' }}</td>
                <td><x-admin.status-badge :status="$badge((string) $run->status)" :label="$run->status()->label()" /></td>
                <td>
                    @if ($run->status()->value === 'succeeded')
                        {{ $run->showsDifferences() ? 'Veränderungen erkannt' : 'Keine Veränderung' }}
                    @else
                        —
                    @endif
                </td>
                <td>{{ $fmt($run->started_at) }}</td>
                <td>{{ $run->triggeredBy?->name ?? 'Konsole' }}</td>
                <td>{{ $run->aiSuggestion?->status ?? '—' }}</td>
                <td><a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.learning.show', ['run' => $run->getKey()]) }}">Details</a></td>
            </tr>
        @endforeach
    </x-admin.data-table>
    <nav class="hub-pagination" aria-label="Seiten">
        @if (! $runs->onFirstPage())<a class="hub-pagination-link" href="{{ $runs->previousPageUrl() }}">Zurück</a>@endif
        <span class="hub-pagination-info">Seite {{ $runs->currentPage() }} von {{ $runs->lastPage() }}</span>
        @if ($runs->hasMorePages())<a class="hub-pagination-link" href="{{ $runs->nextPageUrl() }}">Weiter</a>@endif
    </nav>
@endsection
