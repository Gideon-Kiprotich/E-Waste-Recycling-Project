@extends('layouts.devices')
@section('title', 'Submission conflict')
@section('intro', 'Existing records and history are preserved. Reload current versions before making a new submission.')
@section('content')
<x-feedback type="error" title="Reload before submitting" :message="$exception->getMessage() ?: 'This submission conflicts with an earlier change.'" :focus="true" />
@if(request()->routeIs('admin.assignments.save'))
    <a class="secondary button-link" href="{{ route('admin.assignments.show',request()->route('collection')) }}">Reload pickup allocation</a>
@elseif(request()->routeIs('admin.reviews.decide'))
    <a class="secondary button-link" href="{{ route('admin.reviews.show',request()->route('collection')) }}">Reload request review</a>
@elseif(request()->routeIs('admin.collectors.update'))
    <a class="secondary button-link" href="{{ route('admin.collectors.edit',request()->route('collector')) }}">Reload Collector access</a>
@elseif(request()->routeIs('collector.assignments.respond'))
    <a class="secondary button-link" href="{{ route('collector.assignments.show',request()->route('assignment')) }}">Reload assignment</a>
@endif
<a class="secondary button-link" href="{{ route(auth()->check() ? 'dashboard' : 'login') }}">Back to My workspace</a>
@endsection
