@extends('layouts.mail', ['title' => $title])
@php
    $badge = static fn (string $status): string => match ($status) {
        'active' => 'ok',
        'draft' => 'unknown',
        'retired' => 'disabled',
        default => 'unknown',
    };
@endphp
@section('content')
    @include('mail::admin._tabs')
    <p class="hub-hint">Prozessvorlagen fassen zusammen, wie Vorgänge einer Kategorie bisher bearbeitet wurden. Nur eine aktive Vorlage je Kategorie wird beim Vergleich neuer Vorgänge herangezogen. Entwürfe stammen aus abgeschlossenen Vorgängen oder aus einem KI-Vorschlag und sind vor der Aktivierung zu prüfen.</p>
    <p><a class="hub-button hub-button-secondary" href="{{ route('mail.admin.playbooks.matches') }}">Offene Abgleiche entscheiden</a></p>

    <x-admin.data-table :rows="$playbooks" :columns="['Kategorie', 'Titel', 'Version', 'Status', 'Herkunft', 'Vorgeschlagen', 'Übernommen', 'Angepasst', '']" empty="Noch keine Prozessvorlage.">
        @foreach ($playbooks as $playbook)
            <tr>
                <td>{{ $playbook->case_type }}</td>
                <td>{{ $playbook->title }}</td>
                <td>{{ $playbook->version }}</td>
                <td><x-admin.status-badge :status="$badge((string) $playbook->status)" :label="$playbook->status()->label()" /></td>
                <td>{{ $playbook->source()->label() }}</td>
                <td class="hub-num">{{ $playbook->times_suggested }}</td>
                <td class="hub-num">{{ $playbook->times_accepted }}</td>
                <td class="hub-num">{{ $playbook->times_adjusted }}</td>
                <td><a class="hub-button hub-button-small hub-button-secondary" href="{{ route('mail.admin.playbooks.show', ['playbook' => $playbook->getKey()]) }}">Details</a></td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
