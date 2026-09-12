@extends('layouts.mail', ['title' => $title])
@php use App\Core\Support\GermanDate; $plan = $row['plan']; $version = $row['version']; @endphp
@section('actions')
    <a class="hub-button hub-button-secondary" href="{{ route('mail.approvals.index') }}">Zurück zum Freigabecenter</a>
    <a class="hub-button hub-button-secondary" href="{{ route('mail.cases.show', $case) }}">Vorgang {{ $case->case_number }}</a>
@endsection
@section('content')
    <div class="mail-approval">
        <section class="hub-card">
            <h2>Plan #{{ $plan->getKey() }}</h2>
            <dl class="hub-kv">
                <div class="hub-kv-row"><dt>Zielsystem</dt><dd><span class="hub-badge">{{ $plan->target_system->label() }}</span></dd></div>
                <div class="hub-kv-row"><dt>Risiko</dt><dd><span class="hub-badge mail-risk mail-risk-{{ $row['risk']->value }}">{{ $row['risk']->label() }}</span> (Recht {{ $row['risk']->approvalPermission() }})</dd></div>
                <div class="hub-kv-row"><dt>Status</dt><dd><span class="hub-badge mail-status-badge mail-status-{{ $plan->status->value }}">{{ $plan->status->label() }}</span></dd></div>
                <div class="hub-kv-row"><dt>Quelle</dt><dd>{{ $row['source'] }}</dd></div>
                <div class="hub-kv-row"><dt>Autor der Version</dt><dd>{{ $version?->author?->name ?? 'unbekannt' }}{{ $isAuthor ? ' (Sie)' : '' }}</dd></div>
                <div class="hub-kv-row"><dt>Version, Hash</dt><dd>{{ $version?->version }}, <code>{{ $version?->steps_hash }}</code></dd></div>
                <div class="hub-kv-row"><dt>Wirksam ab</dt><dd>{{ GermanDate::format($version?->effective_date) ?? 'sofort' }}</dd></div>
            </dl>

            <h3>Alt/Neu</h3>
            @if ($version === null)
                <p class="hub-alert hub-alert-error">Keine aktuelle Version, Freigabe nicht möglich.</p>
            @else
                <table class="hub-table mail-diff">
                    <thead><tr><th scope="col">Feld</th><th scope="col">Alt</th><th scope="col">Neu</th></tr></thead>
                    <tbody>
                    @foreach (array_unique(array_merge(array_keys($row['old']), array_keys($row['new']))) as $field)
                        <tr><th scope="row">{{ $field }}</th><td class="mail-diff-old">{{ $row['old'][$field] ?? '' }}</td><td class="mail-diff-new">{{ $row['new'][$field] ?? '' }}</td></tr>
                    @endforeach
                    @if ($row['old'] === [] && $row['new'] === [])
                        <tr><td colspan="3" class="hub-muted">Keine Feldänderungen hinterlegt.</td></tr>
                    @endif
                    </tbody>
                </table>
                @if ($row['sensitive'])
                    @if ($row['revealed'])
                        <p class="hub-alert hub-alert-info">Bankdaten vollständig angezeigt, der Zugriff wurde protokolliert.</p>
                    @elseif ($row['canBank'])
                        <form method="post" action="{{ route('mail.approvals.reveal', $plan) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button hub-button-secondary">Volle IBAN anzeigen (Reauth, wird protokolliert)</button></form>
                    @else
                        <p class="hub-hint">Bankdaten maskiert. Vollständige Anzeige nur mit Recht mail.bank_data.view und Postfachrecht.</p>
                    @endif
                @endif
                @if ($version->steps() !== [])
                    <details class="hub-details"><summary>Schritte ({{ count($version->steps()) }})</summary>
                        <ol>@foreach ($version->steps() as $step)<li>{{ $step['target_system'] ?? '' }} {{ $step['action_type'] ?? ($step['action_key'] ?? '') }}</li>@endforeach</ol>
                    </details>
                @endif
            @endif

            <h3>Identitätsnachweis</h3>
            @forelse ($row['identityChecks'] as $check)
                <p><span class="hub-badge hub-badge-ok"><span aria-hidden="true">●</span> {{ $check->documented_channel }}</span> {{ GermanDate::formatDateTime($check->checked_at) }}, {{ $check->checker?->name ?? 'Nutzer #'.$check->checked_by }}@if ($check->note), {{ $check->note }}@endif</p>
            @empty
                <p class="{{ $version?->requires_identity_check ? 'hub-alert hub-alert-warning' : 'hub-muted' }}">{{ $version?->requires_identity_check ? 'Identitätsprüfung erforderlich, aber nicht dokumentiert.' : 'Kein Identitätsnachweis hinterlegt.' }}</p>
            @endforelse

            @if ($version?->requires_identity_check && $plan->status === \App\Modules\Actions\Enums\ActionStatus::ApprovalRequired && $canApprove)
                <form method="post" action="{{ route('mail.approvals.identity_check', $plan) }}" class="hub-form">
                    @csrf
                    <label for="ic-channel">Identitätsprüfung dokumentieren (Kanal)</label>
                    <select id="ic-channel" name="channel">@foreach (\App\Modules\Actions\Models\IdentityCheck::CHANNELS as $channel)<option value="{{ $channel }}">{{ $channel }}</option>@endforeach</select>
                    <label for="ic-note">Hinweis (optional)</label><input id="ic-note" name="note" type="text" maxlength="500">
                    <button type="submit" class="hub-button hub-button-secondary hub-button-small">Identitätsprüfung dokumentieren (Reauth)</button>
                </form>
            @endif

            <h3>Zielzustand je Schritt</h3>
            <ul class="mail-list mail-targets">
                @forelse ($row['targets'] as $target)
                    <li><span class="hub-badge mail-status-badge mail-status-{{ $target->status }}">{{ $target->status }}</span> Schritt {{ $target->step_index + 1 }}: {{ $target->target_system }}@if ($target->last_error), {{ $target->last_error }}@endif</li>
                @empty
                    <li class="hub-muted">Noch keine Ausführung. Verifiziert ist nur, was nachgelesen oder manuell bestätigt wurde.</li>
                @endforelse
            </ul>

            <h3>Bisherige Entscheidungen</h3>
            <ul class="mail-list">
                @forelse ($row['approvals'] as $approval)
                    <li><span class="hub-badge {{ $approval->decision === 'approved' ? 'hub-badge-ok' : 'hub-badge-fail' }}">{{ $approval->decision === 'approved' ? 'Freigegeben' : 'Abgelehnt' }}</span> {{ $approval->approver?->name ?? 'Nutzer #'.$approval->approver_user_id }}, {{ GermanDate::formatDateTime($approval->created_at) }}{{ $approval->comment ? ', '.$approval->comment : '' }}{{ $approval->reauth_confirmed_at ? ', Reauth '.GermanDate::formatDateTime($approval->reauth_confirmed_at) : '' }}</li>
                @empty
                    <li class="hub-muted">Noch keine Entscheidung.</li>
                @endforelse
            </ul>
            <p class="hub-small">{{ $row['approvals']->where('decision', 'approved')->count() }} von {{ $row['requiredApprovals'] }} Freigaben. Vier-Augen-Prinzip {{ $fourEyes ? 'erfüllt' : 'noch nicht erfüllt' }}.</p>
        </section>

        <section class="hub-card mail-approval-actions">
            <h2>Entscheidung</h2>
            @if ($plan->status !== \App\Modules\Actions\Enums\ActionStatus::ApprovalRequired)
                <p class="hub-muted">Der Plan wartet nicht auf Freigabe.</p>
            @elseif ($isAuthor)
                <p class="hub-alert hub-alert-warning">Vier-Augen-Prinzip: Sie sind Autor dieser Version und können sie nicht freigeben.</p>
            @elseif (! $canApprove)
                <p class="hub-alert hub-alert-warning">Keine Freigabeberechtigung für diese Risikoklasse in diesem Team.</p>
            @else
                <p class="hub-hint">Die Freigabe verlangt eine erneute Authentifizierung (Passwort oder Zwei-Faktor-Code, höchstens {{ (int) config('hub.security.totp.fresh_minutes', 15) }} Minuten alt). Sie bindet an den Hash der aktuellen Version. Die Ausführung erfolgt getrennt; ein erfolgreicher HTTP-Aufruf ist kein Geschäftsergebnis.</p>
                <form method="post" action="{{ route('mail.approvals.approve', $plan) }}" class="hub-form">
                    @csrf
                    <label for="ap-comment">Kommentar (optional)</label><input id="ap-comment" name="comment" type="text" maxlength="500">
                    <button type="submit" class="hub-button mail-primary">Freigeben (einzeln, mit Reauth)</button>
                </form>
            @endif
            @if (in_array($plan->status, [\App\Modules\Actions\Enums\ActionStatus::Executed, \App\Modules\Actions\Enums\ActionStatus::Failed, \App\Modules\Actions\Enums\ActionStatus::ResultUnclear, \App\Modules\Actions\Enums\ActionStatus::ManualReview], true) && $canApprove)
                <form method="post" action="{{ route('mail.approvals.retry', $plan) }}" class="hub-inline-form">@csrf<button type="submit" class="hub-button hub-button-secondary hub-button-small">Offene Schritte erneut einplanen (Reauth)</button></form>
            @endif
            @if ($plan->status === \App\Modules\Actions\Enums\ActionStatus::ApprovalRequired)
                <form method="post" action="{{ route('mail.approvals.reject', $plan) }}" class="hub-form">
                    @csrf
                    <label for="rj-reason">Ablehnen mit Begründung</label><textarea id="rj-reason" name="reason" rows="3" minlength="5" maxlength="500" required></textarea>
                    <button type="submit" class="hub-button hub-button-danger">Ablehnen</button>
                </form>
            @endif
        </section>
    </div>
@endsection
