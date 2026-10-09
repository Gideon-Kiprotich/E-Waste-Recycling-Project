@extends('layouts.devices')
@section('section-label','Administrator · Allocation')
@section('title','Pickup assignment queue')
@section('intro','Offer reviewed pickup work to a scoped Collector. Assignment does not book a date, dispatch a vehicle or transfer custody.')
@section('content')
<div class="device-grid">@forelse($requests as $record)<article class="card guidance"><h2 class="manual-code">{{ $record->public_code }}</h2><p>{{ \App\Support\Collection\Review::LABELS[app(\App\Support\Collection\Review::class)->state($record)] }}</p><p><a class="primary button-link" href="{{ route('admin.assignments.show',$record->id) }}">Review allocation</a> <a href="{{ route('admin.reviews.show',$record->id) }}">Request review</a></p></article>@empty<x-feedback type="info" message="No outstanding pickup jobs. Drop-off requests remain in their existing workflow." />@endforelse</div>
{{ $requests->links('devices.pagination') }}
@endsection
