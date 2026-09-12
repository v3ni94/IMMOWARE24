@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; @endphp
@section('content')
    <p class="hub-hint">Freigaben erfolgen einzeln mit erneuter Authentifizierung. Vier Augen: die freigebende Person ist nie Autor der Version. Es gibt keine Sammelfreigabe.</p>
    <x-admin.data-table :rows="$plans" :columns="['Plan', 'Vorgang', 'Zielsystem', 'Risiko', 'Alt/Neu', 'Quelle', 'Identitätsnachweis', 'Freigaben', '']" empty="Keine offenen Freigaben.">
        @foreach ($rows as $row)
            @php $plan = $row['plan']; @endphp
            <tr data-mail-row data-mail-url="{{ route('mail.approvals.show', $plan) }}" tabindex="0">
                <td><strong>#{{ $plan->getKey() }}</strong><br><small class="hub-muted">{{ GermanDate::formatDateTime($plan->created_at) }}</small></td>
                <td>@if ($row['case'])<a href="{{ route('mail.cases.show', $row['case']) }}">{{ $row['case']->case_number }}</a><br><small>{{ $row['case']->title }}</small>@endif</td>
                <td><span class="hub-badge">{{ $plan->target_system->label() }}</span></td>
                <td><span class="hub-badge mail-risk mail-risk-{{ $row['risk']->value }}">{{ $row['risk']->label() }}</span></td>
                <td>
                    @foreach ($row['new'] as $field => $value)
                        <div class="hub-small"><strong>{{ $field }}</strong>: <span class="mail-diff-old">{{ $row['old'][$field] ?? '' }}</span> zu <span class="mail-diff-new">{{ $value }}</span></div>
                    @endforeach
                    @if ($row['sensitive'])<small class="hub-muted">Bankdaten maskiert</small>@endif
                </td>
                <td>{{ $row['source'] }}</td>
                <td>{{ $row['identityChecks']->isEmpty() ? 'keiner' : $row['identityChecks']->count().' dokumentiert' }}</td>
                <td>{{ $row['approvals']->where('decision', 'approved')->count() }} von {{ $row['requiredApprovals'] }}</td>
                <td><a class="hub-button hub-button-small" href="{{ route('mail.approvals.show', $plan) }}">Prüfen</a></td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
