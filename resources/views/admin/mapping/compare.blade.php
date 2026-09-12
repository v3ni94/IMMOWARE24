@extends('layouts.admin', ['title' => 'Mapping-Vergleich v'.$a->getAttribute('version').' und v'.$b->getAttribute('version')])

@section('content')
<p class="hub-muted hub-page-meta">{{ $a->getAttribute('entity_type') }} / {{ $a->getAttribute('source_format') }}: v{{ $a->getAttribute('version') }} ({{ $a->getAttribute('status') }}) gegenüber v{{ $b->getAttribute('version') }} ({{ $b->getAttribute('status') }}).</p>
@include('admin::mapping._diff', ['diff' => $diff])
<p><a href="{{ route('admin.mapping.show', ['id' => $a->getKey()]) }}">v{{ $a->getAttribute('version') }}</a>, <a href="{{ route('admin.mapping.show', ['id' => $b->getKey()]) }}">v{{ $b->getAttribute('version') }}</a></p>
@endsection
