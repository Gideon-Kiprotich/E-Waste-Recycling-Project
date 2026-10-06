@extends('layouts.devices')
@section('section-label', 'Collection requests')
@section('title','My requests')
@section('intro','Request history and outstanding items. Preferred dates are not confirmed bookings.')
@section('content')
<div class="actions"><a class="primary button-link" href="{{ route('requests.create') }}">Request collection</a></div>
<div class="device-grid">@forelse($requests as $record)<article class="card guidance"><h2><a href="{{ route('requests.show',$record->id) }}">{{ $record->public_code }}</a></h2><p>{{ $record->state === 'SUBMITTED' ? 'Awaiting review' : str_replace('_',' ',ucfirst(strtolower($record->state))) }}</p><p>{{ $record->mode === 'pickup' ? 'Pickup' : 'Drop off' }} · submitted {{ \Carbon\CarbonImmutable::parse($record->created_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }}</p></article>@empty<p>No requests yet.</p>@endforelse</div>
{{ $requests->links('devices.pagination') }}
@endsection
