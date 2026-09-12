@extends('layouts.admin', ['title' => 'Discovery-Konsole'])
@php
    use App\Core\Support\GermanDate;
    $badge = static fn (string $outcome): string => match ($outcome) {
        'success' => 'ok',
        'throttled', 'timeout', 'not_found', 'client_error' => 'warn',
        'unauthorized', 'forbidden', 'server_error', 'connection_failed', 'blocked' => 'fail',
        default => 'unknown',
    };
@endphp
@section('content')
    <div class="hub-alert hub-alert-warning" role="alert">
        Hinweis zu Produktivdaten: Diese Konsole zeigt protokollierte Zugriffe auf den Immoware24-Mandanten ({{ $environment }}). Pfade können Ordner- und Dateinamen mit personenbezogenen Daten enthalten. Request-Bodies werden nie gespeichert, Header sind maskiert. Einträge werden nach {{ $retentionDays }} Tagen gelöscht (hub:remote-requests:prune). Kein Baustein darf auf einer REST-API von Immoware24 aufbauen; belegt sind ausschließlich WebDAV, CardDAV, CalDAV und Dateiexporte.
    </div>

    <form method="get" action="{{ route('admin.discovery.index') }}" class="hub-form hub-card">
        <div class="hub-form-row">
            <div>
                <label for="f-connection">Verbindung</label>
                <select id="f-connection" name="connection">
                    <option value="0">Alle</option>
                    @foreach ($connections as $connection)
                        <option value="{{ $connection->getKey() }}" @selected($filter['connection'] === (int) $connection->getKey())>{{ $connection->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-connector">Connector</label>
                <select id="f-connector" name="connector">
                    <option value="">Alle</option>
                    @foreach ($connectors as $connector)
                        <option value="{{ $connector }}" @selected($filter['connector'] === $connector)>{{ $connector }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-method">Methode</label>
                <select id="f-method" name="method">
                    <option value="">Alle</option>
                    @foreach ($methods as $method)
                        <option value="{{ $method }}" @selected($filter['method'] === $method)>{{ $method }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="f-outcome">Ergebnis</label>
                <select id="f-outcome" name="outcome">
                    <option value="">Alle</option>
                    @foreach ($outcomes as $outcome)
                        <option value="{{ $outcome->value }}" @selected($filter['outcome'] === $outcome->value)>{{ $outcome->value }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="f-status">HTTP-Status</label><input id="f-status" type="number" name="status" min="0" max="599" value="{{ $filter['status'] ?: '' }}"></div>
            <div><label for="f-path">Pfad enthält</label><input id="f-path" type="search" name="path" value="{{ $filter['path'] }}"></div>
            <div><label for="f-corr">Correlation-ID</label><input id="f-corr" type="text" name="correlation_id" value="{{ $filter['correlation_id'] }}"></div>
            <div><label for="f-fp">Schema-Fingerprint (Präfix)</label><input id="f-fp" type="text" name="fingerprint" value="{{ $filter['fingerprint'] }}"></div>
        </div>
        <div class="hub-form-actions">
            <button type="submit" class="hub-button">Filtern</button>
            <a class="hub-button hub-button-link" href="{{ route('admin.discovery.index') }}">Zurücksetzen</a>
        </div>
    </form>

    <x-admin.data-table :rows="$requests" :columns="['Zeit', 'Connector', 'Methode', 'Pfad', 'Status', 'Ergebnis', 'Antwortzeit', 'Größe', 'Schema-FP', '']" empty="Keine protokollierten Requests.">
        @foreach ($requests as $item)
            <tr>
                <td>{{ GermanDate::formatDateTime($item->requested_at) }}</td>
                <td>{{ $item->connector_name ?? $item->connection?->name ?? 'keine Angabe' }}</td>
                <td><code class="hub-mono">{{ $item->method }}</code></td>
                <td class="hub-break hub-small"><code class="hub-mono">{{ \Illuminate\Support\Str::limit((string) $item->path, 90) }}</code></td>
                <td class="hub-num">{{ $item->response_status ?? '' }}</td>
                <td><x-admin.status-badge :status="$badge((string) $item->outcome)" :label="(string) $item->outcome" /></td>
                <td class="hub-num">{{ $item->duration_ms !== null ? number_format((int) $item->duration_ms, 0, ',', '.').' ms' : '' }}</td>
                <td class="hub-num">{{ $item->response_bytes !== null ? number_format((int) $item->response_bytes, 0, ',', '.').' B' : '' }}</td>
                <td><code class="hub-mono">{{ $item->response_schema_fingerprint !== null ? mb_substr((string) $item->response_schema_fingerprint, 0, 12) : '' }}</code></td>
                <td><a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.discovery.show', ['request' => $item->getKey()]) }}">Details</a></td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
