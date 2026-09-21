@extends('layouts.mail', ['title' => $title])
@section('content')
    @include('mail::admin._tabs')
    <p class="hub-hint">Vorgeschlagene Prozessvorlagen für neue Vorgänge. Die Entscheidung schreibt fort, wie zuverlässig eine Vorlage ist, und verbessert sie über die Zeit.</p>

    <x-admin.data-table :rows="$matches" :columns="['Vorgang', 'Vorlage', 'Punktzahl', 'Methode', 'Entscheidung']" empty="Keine offenen Abgleiche.">
        @foreach ($matches as $match)
            <tr>
                <td>{{ $match->case?->title ?? '—' }} <span class="hub-muted">{{ $match->case?->case_number }}</span></td>
                <td>{{ $match->playbook?->title ?? 'kein Treffer' }}</td>
                <td class="hub-num">{{ $match->similarity_score ?? '—' }}</td>
                <td>{{ $match->method()->label() }}</td>
                <td>
                    @if ($match->playbook_id)
                        <form method="post" action="{{ route('mail.admin.playbooks.matches.decide', ['match' => $match->getKey()]) }}" class="hub-form hub-inline-form">
                            @csrf
                            <select name="decision" required>
                                <option value="accepted">Wie vorgeschlagen übernommen</option>
                                <option value="adjusted">Mit Abweichungen übernommen</option>
                                <option value="rejected">Abgelehnt</option>
                            </select>
                            <input type="text" name="deviations" placeholder="Abweichungen (optional, eine je Zeile)">
                            <button type="submit" class="hub-button hub-button-small">Entscheiden</button>
                        </form>
                    @else
                        <span class="hub-muted">kein Vorschlag zu entscheiden</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.data-table>
    <nav class="hub-pagination" aria-label="Seiten">
        @if (! $matches->onFirstPage())<a class="hub-pagination-link" href="{{ $matches->previousPageUrl() }}">Zurück</a>@endif
        <span class="hub-pagination-info">Seite {{ $matches->currentPage() }} von {{ $matches->lastPage() }}</span>
        @if ($matches->hasMorePages())<a class="hub-pagination-link" href="{{ $matches->nextPageUrl() }}">Weiter</a>@endif
    </nav>
@endsection
