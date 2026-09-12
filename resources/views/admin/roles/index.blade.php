@extends('layouts.admin', ['title' => 'Rollen'])
@section('actions')
    <a class="hub-button hub-button-link" href="{{ route('admin.users.index') }}">Zu den Benutzern</a>
@endsection
@section('content')
    <p class="hub-page-meta">Rechte je Rolle aus config/hub/security.php (permissions und permission_catalog). Die Matrix ist nur lesend; Änderungen erfolgen in der Konfiguration und werden versioniert ausgerollt. Der Owner erhält alle Rechte des Katalogs.</p>
    <div class="hub-table-wrapper">
        <table class="hub-table" data-role-matrix>
            <thead>
            <tr>
                <th scope="col">Recht</th>
                @foreach ($roles as $role)
                    <th scope="col">{{ $role->label() }}<br><small class="hub-muted">{{ $role->value }}, {{ number_format((int) ($counts[$role->value] ?? 0), 0, ',', '.') }} Konten</small></th>
                @endforeach
            </tr>
            </thead>
            <tbody>
            @foreach ($catalog as $permission)
                <tr>
                    <th scope="row"><code class="hub-mono">{{ $permission }}</code></th>
                    @foreach ($roles as $role)
                        <td data-cell="{{ $role->value }}:{{ $permission }}">
                            @if ($matrix[$role->value][$permission])
                                <x-admin.status-badge status="ok" label="ja" />
                            @else
                                <span class="hub-muted">nein</span>
                            @endif
                        </td>
                    @endforeach
                </tr>
            @endforeach
            <tr>
                <th scope="row">Anmeldung an der Oberfläche</th>
                @foreach ($roles as $role)
                    <td>{!! $role->canLogin() ? '<span class="hub-badge hub-badge-ok">ja</span>' : '<span class="hub-muted">nein</span>' !!}</td>
                @endforeach
            </tr>
            <tr>
                <th scope="row">Zwei-Faktor verpflichtend</th>
                @foreach ($roles as $role)
                    <td>{!! $role->canLogin() && ! in_array($role->value, $exemptRoles, true) ? '<span class="hub-badge hub-badge-ok">ja</span>' : '<span class="hub-muted">nein</span>' !!}</td>
                @endforeach
            </tr>
            <tr>
                <th scope="row">Schreibpfad beantragen (erste Person)</th>
                @foreach ($roles as $role)
                    <td>{!! $role->canRequestWriteEnable() ? '<span class="hub-badge hub-badge-ok">ja</span>' : '<span class="hub-muted">nein</span>' !!}</td>
                @endforeach
            </tr>
            <tr>
                <th scope="row">Schreibpfad bestätigen (zweite Person)</th>
                @foreach ($roles as $role)
                    <td>{!! $role->canConfirmWriteEnable() ? '<span class="hub-badge hub-badge-ok">ja</span>' : '<span class="hub-muted">nein</span>' !!}</td>
                @endforeach
            </tr>
            </tbody>
        </table>
    </div>
@endsection
