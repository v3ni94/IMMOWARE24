@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; @endphp
@section('content')
    <div class="hub-stat-grid mail-stat-grid">
        <a class="hub-stat {{ $emergencies->isNotEmpty() || $alerts->isNotEmpty() ? 'hub-stat-fail' : 'hub-stat-ok' }}" href="{{ route('mail.inbox.index', ['priority' => 'p0']) }}">
            <span class="hub-stat-label">Notfälle (P0)</span>
            <span class="hub-stat-value"><span aria-hidden="true">{{ $emergencies->isNotEmpty() ? '■' : '●' }}</span> {{ $emergencies->count() }}</span>
            <span class="hub-stat-hint">{{ $alerts->count() }} offene Alarme ohne Annahme</span>
        </a>
        <a class="hub-stat {{ $unassigned->isNotEmpty() ? 'hub-stat-warn' : 'hub-stat-ok' }}" href="{{ route('mail.inbox.index', ['assignee' => 'none']) }}">
            <span class="hub-stat-label">Unzugeordnet</span>
            <span class="hub-stat-value"><span aria-hidden="true">{{ $unassigned->isNotEmpty() ? '▲' : '●' }}</span> {{ $unassigned->count() }}</span>
            <span class="hub-stat-hint">Vorgänge ohne Verantwortlichen</span>
        </a>
        <a class="hub-stat {{ $overdue->isNotEmpty() ? 'hub-stat-fail' : 'hub-stat-ok' }}" href="{{ route('mail.inbox.index', ['sort' => 'due_at']) }}">
            <span class="hub-stat-label">Überfällige Rückmeldungen</span>
            <span class="hub-stat-value"><span aria-hidden="true">{{ $overdue->isNotEmpty() ? '■' : '●' }}</span> {{ $overdue->count() }}</span>
            <span class="hub-stat-hint">Antwort oder Zwischenstand überfällig</span>
        </a>
        @if ($canApprove)
            <a class="hub-stat {{ $approvals->isNotEmpty() ? 'hub-stat-warn' : 'hub-stat-ok' }}" href="{{ route('mail.approvals.index') }}">
                <span class="hub-stat-label">Offene Freigaben</span>
                <span class="hub-stat-value"><span aria-hidden="true">{{ $approvals->isNotEmpty() ? '▲' : '●' }}</span> {{ $approvals->count() }}</span>
                <span class="hub-stat-hint">Vier-Augen, einzeln, mit Reauth</span>
            </a>
        @endif
        <div class="hub-stat {{ $blocked !== [] ? 'hub-stat-warn' : 'hub-stat-ok' }}">
            <span class="hub-stat-label">Blockierte Integrationen</span>
            <span class="hub-stat-value"><span aria-hidden="true">{{ $blocked !== [] ? '▲' : '●' }}</span> {{ count($blocked) }}</span>
            <span class="hub-stat-hint">Nicht eingerichtet, Fehler oder Reauth nötig</span>
        </div>
        <div class="hub-stat {{ $incomplete > 0 ? 'hub-stat-warn' : 'hub-stat-ok' }}">
            <span class="hub-stat-label">Unvollständige Vorgänge</span>
            <span class="hub-stat-value"><span aria-hidden="true">{{ $incomplete > 0 ? '▲' : '●' }}</span> {{ $incomplete }}</span>
            <span class="hub-stat-hint">Ohne Verantwortlichen, nächsten Schritt oder Fälligkeit</span>
        </div>
    </div>

    <div class="hub-card-grid">
        <section class="hub-card" aria-labelledby="h-emergencies">
            <h2 id="h-emergencies">Notfälle</h2>
            @if ($emergencies->isEmpty() && $alerts->isEmpty())
                <p class="hub-muted">Keine offenen Notfälle.</p>
            @else
                <ul class="mail-list">
                    @foreach ($emergencies as $case)
                        <li><x-mail.priority-badge :priority="$case->priority" /> <a href="{{ route('mail.cases.show', $case) }}">{{ $case->case_number }} {{ $case->title }}</a>
                            <small class="hub-muted">{{ $case->priority_reason ?: 'Ursache nicht hinterlegt' }}, fällig {{ GermanDate::formatDateTime($case->due_at) ?? 'ohne Fälligkeit' }}</small></li>
                    @endforeach
                    @foreach ($alerts as $alert)
                        <li><span class="hub-badge mail-sla mail-sla-red"><span aria-hidden="true">■</span> Alarm {{ $alert->status }}</span> Vorgang #{{ $alert->case_id }}, Annahme bis {{ GermanDate::formatDateTime($alert->acknowledge_due_at) }}</li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-unassigned">
            <h2 id="h-unassigned">Unzugeordnete Vorgänge</h2>
            @if ($unassigned->isEmpty())
                <p class="hub-muted">Alle offenen Vorgänge haben einen Verantwortlichen.</p>
            @else
                <ul class="mail-list">
                    @foreach ($unassigned->take(10) as $case)
                        <li><x-mail.priority-badge :priority="$case->priority" /> <a href="{{ route('mail.cases.show', $case) }}">{{ $case->case_number }} {{ $case->title }}</a> <small class="hub-muted">seit {{ GermanDate::formatDateTime($case->opened_at) }}</small></li>
                    @endforeach
                </ul>
                @if ($unassigned->count() > 10)<p><a href="{{ route('mail.inbox.index', ['assignee' => 'none']) }}">Alle {{ $unassigned->count() }} anzeigen</a></p>@endif
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-overdue">
            <h2 id="h-overdue">Überfällige Rückmeldungen</h2>
            @if ($overdue->isEmpty())
                <p class="hub-muted">Keine überfälligen Rückmeldungen.</p>
            @else
                <ul class="mail-list">
                    @foreach ($overdue->take(10) as $case)
                        <li><x-mail.sla-badge color="red" label="Überfällig seit {{ GermanDate::formatDateTime($case->due_at) ?? 'unbekannt' }}" /> <a href="{{ route('mail.cases.show', $case) }}">{{ $case->case_number }} {{ $case->title }}</a> <small class="hub-muted">{{ $case->status_communication->label() }}</small></li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($canApprove)
            <section class="hub-card" aria-labelledby="h-approvals">
                <h2 id="h-approvals">Offene Freigaben</h2>
                @if ($approvals->isEmpty())
                    <p class="hub-muted">Keine offenen Freigaben.</p>
                @else
                    <ul class="mail-list">
                        @foreach ($approvals->take(10) as $plan)
                            <li><span class="hub-badge mail-risk mail-risk-{{ $plan->risk_class->value }}">Risiko {{ $plan->risk_class->label() }}</span> <a href="{{ route('mail.approvals.show', $plan) }}">Plan #{{ $plan->getKey() }}, {{ $plan->target_system->label() }}</a> <small class="hub-muted">Vorgang {{ $plan->case?->case_number }}</small></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endif

        <section class="hub-card" aria-labelledby="h-blocked">
            <h2 id="h-blocked">Blockierte Integrationen</h2>
            @if ($blocked === [])
                <p class="hub-muted">Alle Integrationen verbunden.</p>
            @else
                <ul class="mail-list">
                    @foreach ($blocked as $row)
                        @php $state = \App\Modules\MailUi\Services\IntegrationOverview::STATES[$row['state']]; @endphp
                        <li><span class="hub-badge hub-badge-{{ $state['level'] }}"><span aria-hidden="true">{{ $state['symbol'] }}</span> {{ $state['label'] }}</span> {{ $row['title'] }}@if ($row['state_reason']) <small class="hub-muted">{{ $row['state_reason'] }}</small>@endif</li>
                    @endforeach
                </ul>
                <p><a href="{{ route('mail.integrations.index') }}">Zu den Integrationen</a></p>
            @endif
        </section>

        <section class="hub-card" aria-labelledby="h-load">
            <h2 id="h-load">Teamlast</h2>
            @if ($teamLoad === [])
                <p class="hub-muted">Noch kein Team angelegt.</p>
            @else
                <div class="hub-table-wrapper">
                    <table class="hub-table">
                        <thead><tr><th scope="col">Team</th><th scope="col">Offen</th><th scope="col">Unzugeordnet</th><th scope="col">Überfällig</th><th scope="col">Je Verantwortlichem</th></tr></thead>
                        <tbody>
                        @foreach ($teamLoad as $row)
                            <tr>
                                <td>{{ $row['team'] }}</td>
                                <td>{{ $row['open'] }}</td>
                                <td>{{ $row['unassigned'] }}</td>
                                <td>{{ $row['overdue'] }}</td>
                                <td>@foreach ($row['members'] as $name => $count)<span class="hub-badge">{{ $name }}: {{ $count }}</span> @endforeach</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <details class="hub-details">
        <summary>Feature-Flags (nur Anzeige, Umgebung)</summary>
        <ul>
            @foreach ($flags as $flag => $enabled)
                <li><code>{{ $flag }}</code>: <span class="hub-badge {{ $enabled ? 'hub-badge-warn' : 'hub-badge-disabled' }}">{{ $enabled ? 'aktiv' : 'aus' }}</span></li>
            @endforeach
        </ul>
    </details>
@endsection
