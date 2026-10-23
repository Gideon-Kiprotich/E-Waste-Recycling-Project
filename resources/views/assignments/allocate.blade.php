@extends('layouts.devices')
@section('section-label','Administrator · Allocation')
@section('title','Allocate pickup')
@section('intro','Complete setup, record a current Ready review, then send an offer for the named Collector to accept. Acceptance does not schedule or collect anything.')
@php($fieldIds=['membership_ref'=>$guide['canOffer'] ? 'membership_ref' : 'allocation-prerequisites','facility_ref'=>$guide['canOffer'] ? 'facility_ref' : 'allocation-prerequisites','reason'=>($guide['canOffer'] || $active) ? 'reason' : 'allocation-prerequisites','operation'=>($guide['canOffer'] || $active) ? 'operation' : 'allocation-prerequisites','eligibility'=>'allocation-prerequisites'])
@section('content')
<section class="card guidance" id="allocation-prerequisites" tabindex="-1">
    <h2 class="manual-code">{{ $record->public_code }}</h2>
    <p>Required acceptance: {{ implode(', ',$guide['required']) ?: 'No outstanding items' }}. Every operator/place must support all these categories and subtypes explicitly.</p>
    <h3>1. Request eligibility and Ready review</h3>
    <p>{{ \App\Support\Collection\Review::LABELS[$reviewState] }} · request v{{ $record->version }}</p>
    @include('administration.review-blockers', ['reasons'=>$guide['reasons']])
    @if($reviewState === 'stale')<p><strong>Fresh review required:</strong> directory or request facts changed after the earlier decision. Finish setup below, then record Ready again. A stale review cannot authorize an offer.</p>
    @elseif($reviewState === 'hold')<p><strong>On Hold:</strong> resolve the concrete blockers, then record Ready. Safety restrictions have no override in this workflow.</p>
    @elseif($reviewState === 'pending')<p>No Ready decision has been recorded. Finish setup below before reviewing this request.</p>@endif
    <p><a href="{{ route('admin.reviews.show',$record->id) }}">Open request review</a></p>
    <h3>2. Collecting operator and scoped Collector</h3>
    @if($memberOptions)<p>{{ count($memberOptions) }} eligible Collector choice(s). Each linked operator has an active, reviewed place accepting all required items.</p>
    @else<p><strong>No eligible Collector choices:</strong> {{ $guide['collectorMissing'] }}</p>@endif
    <ul>@foreach($guide['collectingOperators'] as $operator)<li><a href="{{ route('admin.directory.edit',['organization',$operator['id']]) }}">{{ $operator['name'] }}</a>: {{ $operator['reason'] }}</li>@endforeach</ul>
    <ul>@foreach($guide['collectors'] as $collector)<li>{{ $collector['name'] }} · {{ $collector['operator'] }} · eligible scope. <a href="{{ route('admin.collectors.edit',$collector['id']) }}">Manage Collector access</a></li>@endforeach</ul>
    @if($guide['excludedCollectors'])<ul>@foreach($guide['excludedCollectors'] as $collector)<li>{{ $collector['name'] }}{{ $collector['operator'] ? ' · '.$collector['operator'] : '' }}: {{ $collector['reason'] }} <a href="{{ route('admin.collectors.edit',$collector['id']) }}">Manage Collector access</a></li>@endforeach</ul>@endif
    <p><a href="{{ route('admin.directory.index') }}">Operators and places</a> · <a href="{{ route('admin.collectors.index') }}">Collector access</a></p>
    <h3>3. Compatible receiving destination</h3>
    @if($placeOptions)<p>{{ count($placeOptions) }} compatible destination choice(s), owned by approved Processor operators or Collector and processor organizations.</p>
    @else<p><strong>No compatible receiving destinations:</strong> {{ $guide['receivingMissing'] }}</p>@endif
    <ul>@foreach($guide['receivingOperators'] as $operator)<li><a href="{{ route('admin.directory.edit',['organization',$operator->id]) }}">{{ $operator->name }}</a> · {{ $operator->review_status === 'approved' ? 'Active / project-reviewed' : ucfirst($operator->review_status) }}</li>@endforeach</ul>
    @if($guide['receivingPlaces'])<ul>@foreach($guide['receivingPlaces'] as $place)<li><a href="{{ route('admin.directory.edit',['place',$place['id']]) }}">{{ $place['name'] }}</a> · {{ $place['operator'] }}: {{ $place['reason'] }}</li>@endforeach</ul>@endif
    <p><a href="{{ route('admin.directory.create','organization') }}">Add operator</a> · <a href="{{ route('admin.directory.create','place') }}">Add point or facility</a>. Approval, active availability and exact capabilities are separate requirements; depots and collector-only places cannot receive this job.</p>
    <p><strong>When returning from setup:</strong> every directory save invalidates outstanding reviews, including unrelated directory edits. Open request review and record Ready again after the final setup change. Actual Collector access changes invalidate that Collector's assignments and revoke sessions; an unchanged active link preserves them. Reopen this allocation page to load current choices and versions.</p>
</section>
<section class="card guidance section-title">
    <h2>4. Offer and Collector acknowledgment</h2>
    @if($active)
        <p>{{ \App\Support\Assignments\History::LABELS[$active->state] }} · assignment v{{ $active->version }}</p>
        @if(!$current)<p>Assignment eligibility changed. Withdraw the stale live offer if necessary, resolve the blockers and record a fresh Ready review. Reservations remain active.</p>
        @elseif($active->state === 'offered')<p><strong>Pending Collector acknowledgment.</strong> The named Collector must sign in, open Offers and accepted work → Open assignment, choose Accept — not scheduled and press Record response. Sending an offer is not acceptance.</p>
        @else<p>The Collector has accepted. This is not a booked appointment, route dispatch, physical collection or custody transfer.</p>@endif
    @else<p>No active assignment. Declined and closed offers remain in history.</p>@endif
    @if(!$guide['canOffer'] && !$active)<p>Offer submission is unavailable until the prerequisites above pass. Resolve setup/eligibility, record a fresh Ready review and reload this page.</p>@endif
</section>
@if($guide['canOffer'] || $active)
<section class="card section-title"><form method="POST" action="{{ route('admin.assignments.save',$record->id) }}" class="account-form">@csrf
    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
    <input type="hidden" name="expected_request_version" value="{{ $record->version }}"><input type="hidden" name="expected_review_id" value="{{ $record->review_id }}">
    @if($active)<input type="hidden" name="assignment_id" value="{{ $active->id }}"><input type="hidden" name="expected_version" value="{{ $active->version }}">@endif
    <x-select-field name="operation" label="Assignment action" :value="$active ? '' : 'offer'" :options="$active ? ($guide['canOffer'] ? ['withdraw'=>'Withdraw existing assignment','reassign'=>'Replace with a fresh offer'] : ['withdraw'=>'Withdraw existing assignment']) : ['offer'=>'Offer pickup job']" />
    @if($guide['canOffer'])
        <x-select-field name="membership_ref" label="Collector and operator" :options="$memberOptions" :required="false" hint="Required for offer/reassignment. Listed Collectors are active and linked to an operator accepting every required item. Server checks run again on submission." />
        <x-select-field name="facility_ref" label="Receiving facility or collection point" :options="$placeOptions" :required="false" hint="Required for offer/reassignment. Only compatible, active project-reviewed receiving places are listed. A destination choice does not record receipt." />
    @endif
    <x-field name="reason" label="Private allocation reason" maxlength="1000" />
    <button class="primary" type="submit">Record assignment action</button>
</form></section>
@endif
<section class="card guidance section-title"><h2>Assignment history</h2><ol class="timeline">@forelse($history as $event)<li><strong>{{ \App\Support\Assignments\History::LABELS[substr($event->type,11)] }}</strong><p>v{{ $event->aggregate_version }} · <x-instant :value="$event->recorded_at" /></p>@if($event->reason_ciphertext)<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($event->reason_ciphertext) }}</p>@endif</li>@empty<li>No assignment history.</li>@endforelse</ol>{{ $history->links('devices.pagination') }}</section>
@endsection
