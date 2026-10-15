@extends('layouts.devices')
@section('section-label','Collector · Ordered stops')
@section('title','Approved ordered stops')
@section('intro','Haversine geometric simulation. This list is not road navigation, a booked appointment or a physical collection record.')
@section('content')
<section class="card guidance"><h2>{{ collect($snapshot['vehicles'])->firstWhere('id',$record->vehicle_id)['code'] }}</h2><p>{{ $tour['kg'] }} estimated kg · {{ number_format($tour['km'],3) }} simulated km · {{ number_format($tour['seconds']/3600,2) }} estimated hours, including service and depot return.</p>
<ol><li>Start: {{ $snapshot['depot']['name'] }}</li>@foreach($tour['stops'] as $id)<li><strong class="manual-code">{{ $jobs[$id]['code'] }}</strong> · {{ $jobs[$id]['kg'] }} kg<p><a href="{{ route('collector.assignments.show',$id) }}">Open authorized pickup details</a>. Private details are rechecked against current accepted-assignment eligibility.</p></li>@endforeach<li>Return: {{ $snapshot['depot']['name'] }}. No unloading, delivery or receipt is recorded.</li></ol>
@php($mapPoints=[[$snapshot['depot']['latitude'],$snapshot['depot']['longitude']],...array_map(fn($id)=>[$jobs[$id]['latitude'],$jobs[$id]['longitude']],$tour['stops']),[$snapshot['depot']['latitude'],$snapshot['depot']['longitude']]])
@include('routing.map')
<p>Stop or eligibility changed? Ask the Administrator to resolve it and compare/approve again. No offline receipt or attempt submission exists here.</p></section>
@endsection
