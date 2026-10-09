@extends('layouts.devices')
@section('section-label','Collector · Assigned work')
@section('title','Pickup offers and accepted work')
@section('intro','Acceptance does not schedule a pickup. Route planning and physical receipt belong to later increments.')
@section('content')
<div class="device-grid">@forelse($assignments as $assignment)<article class="card guidance"><h2>{{ \App\Support\Assignments\History::LABELS[$assignment->state] }}</h2>@if($assignment->active && !app(\App\Support\Assignments\Eligibility::class)->current($assignment))<p>Changed — Administrator resolution required. Pickup details are unavailable.</p>@endif<p><a href="{{ route('collector.assignments.show',$assignment->id) }}">Open assignment</a></p></article>@empty<x-feedback type="info" message="No work is assigned to your current operator scope. An Administrator must link unscoped Collector accounts." />@endforelse</div>{{ $assignments->links('devices.pagination') }}
@endsection
