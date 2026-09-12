@extends('layouts.admin', ['title' => 'Neue Connection'])

@section('content')
<p class="hub-muted hub-page-meta">Die Connection wird pausiert und ohne Schreibfreigabe angelegt. Nach dem Speichern die Immoware-Schnittstelle prüfen (Probe), dann aktivieren.</p>
@include('admin::connections._form', ['action' => route('admin.connections.store'), 'method' => 'POST', 'submitLabel' => 'Connection anlegen'])
@endsection
