@extends('layouts.devices')
@section('section-label','Administrator · Collector access')
@section('title','Collector operator access')
@section('intro','Create or link Collector accounts only. Consumer roles and other staff roles are preserved.')
@section('content')
<p><a class="primary button-link" href="{{ route('admin.collectors.create') }}">Create Collector account</a></p><div class="device-grid">@forelse($collectors as $collector)<article class="card guidance"><h2>{{ $collector->display_name }}</h2><p>{{ $collector->operator_name ?? 'Unscoped — no operator permissions' }} · {{ $collector->active ? 'Membership active' : 'Membership inactive' }} · account {{ $collector->status }}</p><a href="{{ route('admin.collectors.edit',$collector->id) }}">Manage Collector access</a></article>@empty<x-feedback type="info" message="No Collector accounts. Existing accounts are not inferred or converted." />@endforelse</div>{{ $collectors->links('devices.pagination') }}
@endsection
