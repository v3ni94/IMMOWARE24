@extends('layouts.admin', ['title' => 'Exportrhythmen'])
@php
    use App\Core\Support\GermanDate;
    $typeLabel = static fn (?string $value): string => $value !== null && \App\Modules\Imports\Enums\ExportType::tryFrom($value) !== null ? \App\Modules\Imports\Enums\ExportType::from($value)->label() : (string) $value;
@endphp
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.imports.index') }}">Zu den Importen</a>
@endsection
@section('content')
    <p class="hub-page-meta">Manuelle Exporte aus Immoware24 werden je Verbindung und Exporttyp in einem festen Rhythmus erwartet. Überfällige Exporte sind hervorgehoben; hub:imports:remind listet sie ebenfalls auf.</p>

    <x-admin.data-table :rows="$schedules" :columns="['Verbindung', 'Exporttyp', 'Verantwortlich', 'Intervall', 'Letzter Import', 'Fällig am', 'Status', '']" empty="Keine Exportrhythmen definiert.">
        @foreach ($schedules as $schedule)
            @php $overdue = $schedule->next_due_at === null || $schedule->next_due_at->lessThanOrEqualTo($threshold); @endphp
            <tr @if ($overdue) class="is-overdue" style="background: var(--hub-warn-bg);" @endif data-schedule="{{ $schedule->getKey() }}">
                <td>{{ $schedule->connection?->name ?? 'keine Angabe' }}</td>
                <td>{{ $typeLabel($schedule->export_type) }}</td>
                <td>{{ $schedule->responsibleUser?->name ?? 'keine Angabe' }}</td>
                <td class="hub-num">{{ $schedule->interval_days }} Tage</td>
                <td>{{ GermanDate::formatDateTime($schedule->last_import_at) ?? 'noch nie' }}</td>
                <td>{{ GermanDate::formatDateTime($schedule->next_due_at) ?? 'sofort' }}</td>
                <td>
                    @if ($overdue)
                        <x-admin.status-badge status="warn" label="Überfällig" />
                    @else
                        <x-admin.status-badge status="ok" label="Im Plan" />
                    @endif
                </td>
                <td>
                    @if ($canManage)
                        <details class="hub-details">
                            <summary>Bearbeiten</summary>
                            <form method="post" action="{{ route('admin.imports.schedules.update', ['schedule' => $schedule->getKey()]) }}" class="hub-form">
                                @csrf
                                @method('PUT')
                                <label for="interval-{{ $schedule->getKey() }}">Intervall in Tagen</label>
                                <input id="interval-{{ $schedule->getKey() }}" type="number" name="interval_days" min="1" max="365" value="{{ $schedule->interval_days }}" required>
                                <label for="resp-{{ $schedule->getKey() }}">Verantwortlich</label>
                                <select id="resp-{{ $schedule->getKey() }}" name="responsible_user_id">
                                    <option value="">niemand</option>
                                    @foreach ($users as $user)
                                        <option value="{{ $user->getKey() }}" @selected((int) $schedule->responsible_user_id === (int) $user->getKey())>{{ $user->name }}</option>
                                    @endforeach
                                </select>
                                <div class="hub-form-actions"><button type="submit" class="hub-button hub-button-small">Speichern</button></div>
                            </form>
                            <x-admin.confirm-form :action="route('admin.imports.schedules.destroy', ['schedule' => $schedule->getKey()])" method="DELETE" label="Entfernen" description="Der Zeitplan wird entfernt. Importierte Daten bleiben unberührt." />
                        </details>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>

    @if ($canManage)
        <div class="hub-card">
            <h2>Neuen Exportrhythmus anlegen</h2>
            <form method="post" action="{{ route('admin.imports.schedules.store') }}" class="hub-form">
                @csrf
                <div class="hub-form-row">
                    <div>
                        <label for="new-connection">Verbindung</label>
                        <select id="new-connection" name="connection_id" required>
                            @foreach ($connections as $connection)
                                <option value="{{ $connection->getKey() }}" @selected((int) old('connection_id') === (int) $connection->getKey())>{{ $connection->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="new-type">Exporttyp</label>
                        <select id="new-type" name="export_type" required>
                            @foreach ($types as $type)
                                <option value="{{ $type->value }}" @selected(old('export_type') === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="new-interval">Intervall in Tagen</label>
                        <input id="new-interval" type="number" name="interval_days" min="1" max="365" value="{{ old('interval_days', 7) }}" required>
                    </div>
                    <div>
                        <label for="new-resp">Verantwortlich</label>
                        <select id="new-resp" name="responsible_user_id">
                            <option value="">niemand</option>
                            @foreach ($users as $user)
                                <option value="{{ $user->getKey() }}" @selected((int) old('responsible_user_id') === (int) $user->getKey())>{{ $user->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div class="hub-form-actions"><button type="submit" class="hub-button">Anlegen</button></div>
            </form>
        </div>
    @endif
@endsection
