@extends('layouts.devices')
@section('section-label','Administrator · Allocation')
@section('title','Allocate pickup')
@section('intro','Current eligibility and versions are checked again on submission. A receiving destination is not a recorded receipt.')
@php($fieldIds=['membership_ref'=>'membership_ref','facility_ref'=>'facility_ref','reason'=>'reason','operation'=>'operation'])
@section('content')
<section class="card guidance"><h2 class="manual-code">{{ $record->public_code }}</h2><p>{{ \App\Support\Collection\Review::LABELS[$reviewState] }} · request v{{ $record->version }}</p><a href="{{ route('admin.reviews.show',$record->id) }}">Open private request review and outstanding-item details</a>
@if($active)<p>{{ \App\Support\Assignments\History::LABELS[$active->state] }} · assignment v{{ $active->version }}</p>@if(!$current)<x-feedback type="info" message="Assignment eligibility changed. Withdraw it to resolve the live obligation; obtain a fresh Ready review before offering again. Reservations remain active." />@endif
@else<p>No active assignment. Declined and closed offers remain in history.</p>@endif</section>
@if($active || $reviewState === 'ready')
<section class="card section-title"><form method="POST" action="{{ route('admin.assignments.save',$record->id) }}" class="account-form">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
<input type="hidden" name="expected_request_version" value="{{ $record->version }}"><input type="hidden" name="expected_review_id" value="{{ $record->review_id }}">
@if($active)<input type="hidden" name="assignment_id" value="{{ $active->id }}"><input type="hidden" name="expected_version" value="{{ $active->version }}">@endif
<x-select-field name="operation" label="Assignment action" :options="$active ? ['withdraw'=>'Withdraw existing assignment','reassign'=>'Replace with a fresh offer'] : ['offer'=>'Offer pickup job']" />
<x-select-field name="membership_ref" label="Collector and operator" :options="$memberOptions" :required="false" hint="Required for offer/reassignment. Each Collector belongs to one operator; exact item capabilities are checked by the server." />
<x-select-field name="facility_ref" label="Receiving facility or collection point" :options="$placeOptions" :required="false" hint="Required for offer/reassignment. No depot, booking or physical receipt is implied." />
<x-field name="reason" label="Private allocation reason" maxlength="1000" />
<button class="primary" type="submit">Record assignment action</button></form></section>
@else<x-feedback type="info" message="A current Ready review is required before allocation. Held or stale requests retain their reservations." />@endif
<section class="card guidance section-title"><h2>Assignment history</h2><ol class="timeline">@forelse($history as $event)<li><strong>{{ \App\Support\Assignments\History::LABELS[substr($event->type,11)] }}</strong><p>v{{ $event->aggregate_version }} · {{ \Carbon\CarbonImmutable::parse($event->recorded_at)->timezone('Africa/Nairobi')->format('j M Y H:i') }}</p>@if($event->reason_ciphertext)<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($event->reason_ciphertext) }}</p>@endif</li>@empty<li>No assignment history.</li>@endforelse</ol>{{ $history->links('devices.pagination') }}</section>
@endsection
