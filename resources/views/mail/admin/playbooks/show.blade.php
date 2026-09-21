@extends('layouts.mail', ['title' => $title])
@php
    use App\Core\Support\GermanDate;
    $fmt = static fn ($value) => $value !== null ? GermanDate::formatDateTime($value) : '—';
@endphp
@section('content')
    @include('mail::admin._tabs')
    <p><a href="{{ route('mail.admin.playbooks.index') }}">&larr; Zurück zur Prozessdatenbank</a></p>

    <div class="hub-card">
        <dl class="hub-kv">
            <dt>Kategorie</dt><dd>{{ $playbook->case_type }}</dd>
            <dt>Version</dt><dd>{{ $playbook->version }}{{ $playbook->previousVersion ? ' (aus Version '.$playbook->previousVersion->version.')' : '' }}</dd>
            <dt>Status</dt><dd>{{ $playbook->status()->label() }}</dd>
            <dt>Herkunft</dt><dd>{{ $playbook->source()->label() }}{{ $playbook->createdFromCase ? ' aus Vorgang '.$playbook->createdFromCase->case_number : '' }}</dd>
            <dt>Angelegt</dt><dd>{{ $fmt($playbook->created_at) }}{{ $playbook->createdBy ? ' von '.$playbook->createdBy->name : '' }}</dd>
            <dt>Aktiviert</dt><dd>{{ $playbook->activated_at ? $fmt($playbook->activated_at).' von '.($playbook->activatedBy->name ?? 'unbekannt') : 'noch nicht aktiviert' }}</dd>
            <dt>Bisher vorgeschlagen / übernommen / angepasst</dt><dd>{{ $playbook->times_suggested }} / {{ $playbook->times_accepted }} / {{ $playbook->times_adjusted }}</dd>
        </dl>
        <div class="hub-form-actions">
            @if ($playbook->status()->value !== 'active')
                <form method="post" action="{{ route('mail.admin.playbooks.activate', ['playbook' => $playbook->getKey()]) }}" class="hub-inline-form">
                    @csrf
                    <button type="submit" class="hub-button">Aktivieren</button>
                </form>
            @endif
            @if ($playbook->status()->value !== 'retired')
                <form method="post" action="{{ route('mail.admin.playbooks.retire', ['playbook' => $playbook->getKey()]) }}" class="hub-inline-form">
                    @csrf
                    <button type="submit" class="hub-button hub-button-secondary">Außer Betrieb nehmen</button>
                </form>
            @endif
        </div>
    </div>

    <div class="hub-card">
        <h2>Schritte</h2>
        <p class="hub-hint">Vorschlag zur Prüfung. Aktionsarten entsprechen den erlaubten nächsten Schritten der Vorgangsbearbeitung.</p>
        <x-admin.data-table :rows="(array) $playbook->steps_json" :columns="['Schritt', 'Aktionsart', 'Beschreibung', 'Frist (Stunden)', 'Freigabe nötig']" empty="Keine Schritte hinterlegt.">
            @foreach ((array) $playbook->steps_json as $index => $step)
                <tr>
                    <td class="hub-num">{{ $index + 1 }}</td>
                    <td><code class="hub-mono">{{ $step['action_type'] ?? '' }}</code></td>
                    <td>{{ $step['description'] ?? '' }}</td>
                    <td class="hub-num">{{ $step['typical_offset_hours'] ?? '—' }}</td>
                    <td>{{ ($step['requires_approval'] ?? false) ? 'ja' : 'nein' }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    </div>

    <div class="hub-card">
        <h2>Letzte Abgleiche</h2>
        <x-admin.data-table :rows="$recentMatches" :columns="['Vorgang', 'Punktzahl', 'Methode', 'Ergebnis', 'Entschieden']" empty="Noch kein Abgleich.">
            @foreach ($recentMatches as $match)
                <tr>
                    <td>{{ $match->case?->case_number ?? '—' }}</td>
                    <td class="hub-num">{{ $match->similarity_score ?? '—' }}</td>
                    <td>{{ $match->method()->label() }}</td>
                    <td>{{ $match->outcome()->label() }}</td>
                    <td>{{ $fmt($match->decided_at) }}</td>
                </tr>
            @endforeach
        </x-admin.data-table>
    </div>
@endsection
