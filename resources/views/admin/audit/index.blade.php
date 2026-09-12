@extends('layouts.admin', ['title' => 'Auditlog'])
@php use App\Core\Support\GermanDate; @endphp
@section('actions')
    <form method="post" action="{{ route('admin.audit.verify') }}" class="hub-inline-form">
        @csrf
        <button type="submit" class="hub-button hub-button-secondary">Kette prüfen</button>
    </form>
@endsection
@section('content')
    <div class="hub-card-grid hub-section">
        <div class="hub-card">
            <x-admin.key-value title="Kettenstatus" :items="[
                'Einträge gesamt' => $total,
                'Letzter Anker' => $lastAnchor !== null ? 'audit_logs.id '.$lastAnchor->last_audit_id.' vom '.GermanDate::formatDateTime($lastAnchor->exported_at) : 'noch kein Anker (audit:anchor)',
                'Anker-Hash' => $lastAnchor !== null ? mb_substr((string) $lastAnchor->root_hash, 0, 16).'…' : null,
            ]">
                <div class="hub-kv-row">
                    <dt>Letzte Prüfung</dt>
                    <dd>
                        @if ($verify !== null)
                            <x-admin.status-badge :status="$verify['valid'] ? 'ok' : 'fail'" :label="$verify['valid'] ? 'Kette intakt' : 'Kette unterbrochen'" />
                            <span class="hub-small">{{ number_format((int) $verify['checked'], 0, ',', '.') }} Einträge geprüft @if ($verify['first_broken_id']), erster Bruch bei id {{ $verify['first_broken_id'] }} @endif</span>
                        @else
                            <span class="hub-muted">in dieser Sitzung noch nicht geprüft, täglich um 02:00 Uhr per audit:verify</span>
                        @endif
                    </dd>
                </div>
            </x-admin.key-value>
        </div>
        <div class="hub-card">
            <p class="hub-help">Das Auditlog ist append-only mit SHA-256-Hash-Kette. Einträge können weder bearbeitet noch gelöscht werden. Geheimnisse sind bereits beim Schreiben maskiert. @unless ($showDiff) Ihre Rolle sieht die Änderungsdetails (vorher/nachher) nicht. @endunless</p>
        </div>
    </div>

    <form method="get" action="{{ route('admin.audit.index') }}" class="hub-form hub-card">
        <div class="hub-form-row">
            <div><label for="f-from">Von (TT.MM.JJJJ)</label><input id="f-from" type="text" name="from" value="{{ $filter['from'] }}" placeholder="01.09.2026"></div>
            <div><label for="f-to">Bis (TT.MM.JJJJ)</label><input id="f-to" type="text" name="to" value="{{ $filter['to'] }}" placeholder="12.09.2026"></div>
            <div>
                <label for="f-actor">Benutzer</label>
                <select id="f-actor" name="actor_id">
                    <option value="0">Alle</option>
                    @foreach ($users as $user)
                        <option value="{{ $user->getKey() }}" @selected($filter['actor_id'] === (int) $user->getKey())>{{ $user->name }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="f-action">Aktion (Präfix)</label><input id="f-action" type="text" name="action" value="{{ $filter['action'] }}" placeholder="admin.users."></div>
            <div>
                <label for="f-entity">Entität</label>
                <select id="f-entity" name="entity_type">
                    <option value="">Alle</option>
                    @foreach ($entityTypes as $type)
                        <option value="{{ $type }}" @selected($filter['entity_type'] === $type)>{{ $type }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="f-entity-id">Entitäts-ID</label><input id="f-entity-id" type="number" name="entity_id" min="0" value="{{ $filter['entity_id'] ?: '' }}"></div>
            <div>
                <label for="f-source">Quelle</label>
                <select id="f-source" name="source">
                    <option value="">Alle</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source->value }}" @selected($filter['source'] === $source->value)>{{ $source->value }}</option>
                    @endforeach
                </select>
            </div>
            <div><label for="f-corr">Correlation-ID</label><input id="f-corr" type="text" name="correlation_id" value="{{ $filter['correlation_id'] }}"></div>
        </div>
        <div class="hub-form-actions">
            <button type="submit" class="hub-button">Filtern</button>
            <a class="hub-button hub-button-link" href="{{ route('admin.audit.index') }}">Zurücksetzen</a>
        </div>
    </form>

    <x-admin.data-table :rows="$logs" :columns="['ID', 'Zeit (UTC)', 'Akteur', 'Quelle', 'Aktion', 'Entität', 'Correlation-ID', '']" empty="Keine Auditeinträge für diesen Filter.">
        @foreach ($logs as $log)
            <tr>
                <td class="hub-num">{{ $log->getKey() }}</td>
                <td>{{ $log->occurred_at?->format('d.m.Y H:i:s') }}</td>
                <td>
                    @if ($log->actor_type === 'user')
                        {{ $userNames[$log->actor_id] ?? ('Benutzer '.$log->actor_id) }}
                    @else
                        {{ $log->actor_type }}@if ($log->actor_id !== null) {{ $log->actor_id }}@endif
                    @endif
                </td>
                <td>{{ $log->source instanceof \BackedEnum ? $log->source->value : $log->source }}</td>
                <td><code class="hub-mono">{{ $log->action }}</code></td>
                <td>{{ $log->entity_type }}@if ($log->entity_id !== null) #{{ $log->entity_id }}@endif</td>
                <td class="hub-small hub-break">{{ $log->correlation_id }}</td>
                <td><a class="hub-button hub-button-small hub-button-secondary" href="{{ route('admin.audit.show', ['log' => $log->getKey()]) }}">Details</a></td>
            </tr>
        @endforeach
    </x-admin.data-table>
@endsection
