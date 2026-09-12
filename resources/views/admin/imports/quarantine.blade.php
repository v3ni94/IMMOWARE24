@extends('layouts.admin', ['title' => 'Quarantäne: '.$file->original_filename])
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.imports.show', ['file' => $file->getKey()]) }}">Zur Datei</a>
@endsection
@section('content')
    <div class="hub-card">
        <x-admin.key-value title="Quarantäne" :items="[
            'Grund' => $file->quarantine_reason,
            'Meldung' => $file->error_summary,
            'Exporttyp' => $exportType?->label() ?? $file->export_type,
            'Header-Fingerprint' => $format?->header_fingerprint ?? $file->header_fingerprint,
            'Formatstatus' => $format?->status,
            'Trennzeichen' => $format?->delimiter,
            'Zeichensatz' => $format?->charset,
        ]" />
    </div>

    @if ($format === null)
        <div class="hub-alert hub-alert-warning">Zu dieser Datei ist kein Header-Format erfasst. Die Quarantäne hat einen anderen Grund als ein unbekanntes Spaltenformat (siehe Meldung). Nach Korrektur der Datei oder des Sidecars die Datei erneut in den Drop-Ordner legen.</div>
    @else
        <div class="hub-section">
            <h2>Spalten der Datei ({{ count($headers) }})</h2>
            <x-admin.data-table :rows="$headers" :columns="['Nr.', 'Originalspalte', 'Normalisiert']">
                @foreach ($headers as $index => $header)
                    <tr>
                        <td class="hub-num">{{ $index + 1 }}</td>
                        <td>{{ $header }}</td>
                        <td><code class="hub-mono">{{ $normalizedHeaders[$index] ?? '' }}</code></td>
                    </tr>
                @endforeach
            </x-admin.data-table>
        </div>

        @if ($confirmable)
            <div class="hub-card">
                <h2>Spaltenmapping bestätigen</h2>
                <p class="hub-help">Jedem Zielfeld des Importers wird eine Quellspalte zugeordnet. Es wird nichts geraten: Nur bestätigte Formate werden importiert. Die Schlüsselfelder bilden die stabile externe ID je Zeile.</p>
                <x-admin.confirm-form :action="route('admin.imports.confirm-format', ['file' => $file->getKey()])" method="POST" label="Mapping bestätigen" :danger="false" description="Das Format wird als bestätigt gespeichert und gibt den Import aller Dateien mit diesem Header frei.">
                    <div class="hub-form">
                        <div class="hub-form-row">
                            @foreach ($targetFields as $target)
                                <div>
                                    <label for="map-{{ $target }}">{{ $target }}</label>
                                    <select id="map-{{ $target }}" name="mapping[{{ $target }}]">
                                        <option value="">nicht zuordnen</option>
                                        @foreach ($normalizedHeaders as $index => $normalized)
                                            <option value="{{ $normalized }}" @selected(old('mapping.'.$target, $existingMapping[$target] ?? '') === $normalized)>{{ $headers[$index] }} ({{ $normalized }})</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                        <fieldset>
                            <legend>Schlüsselfelder</legend>
                            @foreach ($targetFields as $target)
                                <label class="hub-checkbox"><input type="checkbox" name="key_schema[]" value="{{ $target }}" @checked(in_array($target, old('key_schema', $existingKeys), true))> {{ $target }}</label>
                            @endforeach
                        </fieldset>
                    </div>
                </x-admin.confirm-form>
            </div>
        @elseif ($format->status === \App\Modules\Imports\Enums\ImportFormatStatus::Confirmed->value)
            <div class="hub-alert hub-alert-info">Das Format ist bereits bestätigt. Die Datei wird beim nächsten Lauf von hub:imports:scan --process erneut verarbeitet.</div>
        @else
            <div class="hub-alert hub-alert-warning">Für den Exporttyp ist kein mapping-basierter Importer registriert, eine Bestätigung über die Oberfläche ist nicht möglich.</div>
        @endif
    @endif
@endsection
