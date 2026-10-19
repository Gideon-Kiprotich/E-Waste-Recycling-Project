@extends('layouts.devices')
@section('title', 'Submission conflict')
@section('intro', 'Existing records and history are preserved. Reload current versions before making a new submission.')
@section('content')
<x-feedback type="error" title="Reload before submitting" :message="$exception->getMessage() ?: 'This submission conflicts with an earlier change.'" :focus="true" />
@if(request()->routeIs('downstream.*'))
    <a class="secondary button-link" href="{{ route('downstream.index') }}">Reload Downstream movements</a>
@elseif(request()->routeIs('admin.output-profiles.*'))
    <a class="secondary button-link" href="{{ route('admin.output-profiles.index') }}">Reload Output acceptance profiles</a>
@elseif(request()->routeIs('batches.store', 'batches.authorize'))
    <a class="secondary button-link" href="{{ route('batches.index') }}">Reload Dismantling / Batches</a>
@elseif(request()->routeIs('refurbishment.store'))
    <a class="secondary button-link" href="{{ route('refurbishment.show',request()->route('subject')) }}">Reload Refurbishment / Owner return</a>
@elseif(request()->routeIs('media-inventory.store'))
    <a class="secondary button-link" href="{{ route('media-inventory.show',request()->route('subject')) }}">Reload Media inventory</a>
@elseif(request()->routeIs('sanitization.store'))
    <a class="secondary button-link" href="{{ route('sanitization.show',request()->route('subject')) }}">Reload media assurance and history</a>
@elseif(request()->routeIs('admin.routing.*'))
    <a class="secondary button-link" href="{{ route('admin.routing.index') }}">Reload Route planning</a>
@elseif(request()->routeIs('collector.routes.*'))
    <a class="secondary button-link" href="{{ route('collector.routes.index') }}">Return to Approved routes</a>
@elseif(request()->routeIs('admin.assignments.save'))
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
