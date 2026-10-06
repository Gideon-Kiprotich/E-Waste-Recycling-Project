@extends('layouts.devices')
@section('section-label','Administrator · Request review')
@section('title','Outstanding request review')
@section('intro','Review current eligibility and configuration before later allocation. No assignments or appointments are created here.')
@section('content')
<div class="device-grid">@forelse($requests as $record)<article class="card guidance"><h2 class="manual-code"><a href="{{ route('admin.reviews.show',$record->id) }}">{{ $record->public_code }}</a></h2><p>{{ $record->mode === 'pickup' ? 'Pickup' : 'Drop off' }} · {{ \App\Support\Collection\Review::LABELS[app(\App\Support\Collection\Review::class)->state($record)] }} · v{{ $record->version }}</p><p>Submitted preference {{ $record->preferred_date }} · not booked</p></article>@empty<section class="card guidance"><h2>No outstanding requests</h2><p>Cancelled/completed history remains retained. There is no work to allocate here.</p></section>@endforelse</div>{{ $requests->links('devices.pagination') }}
@endsection
