@extends('layouts.admin', ['title' => 'Connection bearbeiten: '.$connection->getAttribute('name')])

@section('content')
@include('admin::connections._form', ['action' => route('admin.connections.update', ['id' => $connection->getKey()]), 'method' => 'PUT', 'submitLabel' => 'Änderungen speichern'])
@endsection
