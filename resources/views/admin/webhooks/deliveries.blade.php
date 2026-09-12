@extends('layouts.admin', ['title' => $title])
@php
    use App\Core\Support\GermanDate;
    $badge = static fn (string $status): string => match ($status) {
        'delivered' => 'ok',
        'pending' => 'unknown',
        'failed' => 'warn',
        'dead' => 'fail',
        'skipped' => 'disabled',
        default => 'unknown',
    };
@endphp
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.webhooks.index') }}">Zu den Endpunkten</a>
    @if ($filter['status'] !== 'dead' || $statuses !== [])
        <a class="hub-button hub-button-secondary" href="{{ route('admin.webhooks.dlq.index') }}">DLQ</a>
    @else
        <a class="hub-button hub-button-secondary" href="{{ route('admin.webhooks.deliveries.index') }}">Alle Zustellungen</a>
    @endif
@endsection
@section('content')
    @if ($statuses !== [])
        <form method="get" action="{{ route('admin.webhooks.deliveries.index') }}" class="hub-form hub-card">
            <div class="hub-form-row">
                <div>
                    <label for="f-status">Status</label>
                    <select id="f-status" name="status">
                        <option value="">Alle</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected($filter['status'] === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="f-endpoint">Endpunkt</label>
                    <select id="f-endpoint" name="endpoint">
                        <option value="0">Alle</option>
                        @foreach ($endpoints as $endpoint)
                            <option value="{{ $endpoint->getKey() }}" @selected($filter['endpoint'] === (int) $endpoint->getKey())>{{ $endpoint->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="hub-form-actions"><button type="submit" class="hub-button">Filtern</button></div>
        </form>
    @else
        <p class="hub-page-meta">Zustellungen mit Status dead haben alle Versuche ({{ (int) config('hub.webhooks.max_attempts', 5) }}) ausgeschöpft. Eine erneute Zustellung setzt den Zähler zurück und reiht den Job neu ein.</p>
    @endif

    <x-admin.data-table :rows="$deliveries" :columns="['Zeit', 'Ereignis', 'Endpunkt', 'Status', 'Versuche', 'Antwort', 'Dauer', 'Fehler', '']" empty="Keine Zustellungen vorhanden.">
        @foreach ($deliveries as $delivery)
            <tr>
                <td>{{ GermanDate::formatDateTime($delivery->last_attempt_at ?? $delivery->created_at) }}</td>
                <td><code class="hub-mono">{{ $delivery->outbox?->event_type }}</code><br><small class="hub-muted">{{ $delivery->outbox?->entity_type }} {{ $delivery->outbox?->entity_id }}</small></td>
                <td>{{ $delivery->endpoint?->name ?? 'entfernt' }}</td>
                <td><x-admin.status-badge :status="$badge((string) $delivery->status)" :label="(string) $delivery->status" /></td>
                <td class="hub-num">{{ $delivery->attempts }}</td>
                <td class="hub-num">{{ $delivery->last_response_code ?? '' }}</td>
                <td class="hub-num">{{ $delivery->duration_ms !== null ? number_format((int) $delivery->duration_ms, 0, ',', '.').' ms' : '' }}</td>
                <td class="hub-small">{{ \Illuminate\Support\Str::limit((string) $delivery->last_error, 120) }}</td>
                <td>
                    @if (in_array((string) $delivery->status, ['failed', 'dead', 'skipped'], true))
                        <form method="post" action="{{ route('admin.webhooks.deliveries.redeliver', ['delivery' => $delivery->getKey()]) }}" class="hub-inline-form">
                            @csrf
                            <button type="submit" class="hub-button hub-button-small hub-button-secondary">Erneut zustellen</button>
                        </form>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
