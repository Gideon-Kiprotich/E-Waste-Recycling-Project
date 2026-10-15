@extends('layouts.devices')
@section('section-label','Collector · Ordered stops')
@section('title','Approved ordered stops')
@section('intro','Haversine geometric simulation. This list is not road navigation, a booked appointment or a physical collection record.')
@section('content')
<section class="card guidance"><h2>{{ collect($snapshot['vehicles'])->firstWhere('id',$record->vehicle_id)['code'] }}</h2><p>{{ $tour['kg'] }} estimated kg · {{ number_format($tour['km'],3) }} simulated km · {{ number_format($tour['seconds']/3600,2) }} estimated hours, including service and depot return.</p>
<ol><li>Start: {{ $snapshot['depot']['name'] }}</li>@foreach($tour['stops'] as $id)<li><strong class="manual-code">{{ $jobs[$id]['code'] }}</strong> · {{ $jobs[$id]['kg'] }} kg @if(isset($consumed[$id]))<p>Visit recorded · <a href="{{ route('receipts.result',$consumed[$id]) }}">Recorded outcomes</a></p>@else<p><a href="{{ route('collector.assignments.show',$id) }}">Open authorized pickup details</a> · <a href="{{ route('receipts.form',$jobs[$id]['request_id']) }}">Record pickup visit</a>. Private details are rechecked against current accepted-assignment eligibility.</p>@endif</li>@endforeach<li>Return: {{ $snapshot['depot']['name'] }}. No unloading, delivery or receipt is recorded.</li></ol>
@php($remainingStops=array_values(array_diff($tour['stops'],array_keys($consumed))))
@if(count($remainingStops))
@php($mapPoints=[[$snapshot['depot']['latitude'],$snapshot['depot']['longitude']],...array_map(fn($id)=>[$jobs[$id]['latitude'],$jobs[$id]['longitude']],$remainingStops),[$snapshot['depot']['latitude'],$snapshot['depot']['longitude']]])
@include('routing.map')
@else<p>No remaining pickup coordinates are shown for consumed stops.</p>@endif
<p>Stop or eligibility changed? Ask the Administrator to resolve it and compare/approve again. Offline submissions are not supported. Explicitly record each attempted outcome; untouched stops are never collected automatically.</p></section>
<section class="card"><div class="card-heading"><h2>Finish tour work</h2></div><form method="POST" action="{{ route('collector.routes.finish',$record->id) }}" class="account-form">@csrf<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
@php($unfinished=array_values(array_diff($tour['stops'],array_keys($consumed))))
<p>{{ count($unfinished) }} unfinished stops. Finishing releases their route claims for Administrator replanning; accepted assignments and item reservations remain. This records no attempt, receipt, depot arrival, unloading or delivery.</p>
<ul>@foreach($unfinished as $id)<li class="manual-code">{{ $jobs[$id]['code'] }}<input type="hidden" name="unfinished[]" value="{{ $id }}"></li>@endforeach</ul>
<x-field name="reason" label="Private finish reason" maxlength="1000" />
<div class="acknowledgment"><input type="checkbox" id="confirm" name="confirm" value="1" required><label for="confirm">I confirm the listed unfinished stops remain uncollected and require replanning. Finish only tour work.</label></div><button class="primary">Finish tour work</button></form></section>
@endsection
