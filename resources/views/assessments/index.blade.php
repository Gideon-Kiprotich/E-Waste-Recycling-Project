@extends('layouts.devices')
@section('title','Assessments / Inspection')
@section('section-label','Processor · Facility inspection')
@section('intro','Record technician observations, inspect an explainable recommendation, then make a separate pathway decision. No work, sanitization or release is performed here.')
@section('content')
@if(!$place)
<x-feedback type="info" message="No current reviewed facility scope. Ask an Administrator to use Place staff access after Operators and places setup. Collection-point scope does not authorize facility inspection." />
@else
<p>Current facility: {{ $place->name }}. Actual receipt must be accepted in <a href="{{ route('handovers.index') }}">Custody handovers</a>. Registration, route completion and transfer initiation are insufficient.</p>
@forelse($rows as $row)
<section class="card notice-content section-title"><h2>{{ $row->public_code }}</h2><p>{{ $row->category_label }} · {{ $row->subtype ? \App\Support\Devices\Questions::subtypes($row->category)[$row->subtype] : '' }} · {{ $row->model }}</p>
@foreach($ctx->blockers($row) as $blocker)<p>{{ $blocker }}</p>@endforeach
<p><a href="{{ route('assessments.show',$row->subject_id) }}">Open inspection and decisions</a></p></section>
@empty<x-feedback type="info" message="No items in this facility's confirmed custody. Have the named receiving Processor acknowledge actual matching items in Custody handovers. Do not create demonstration receipts to bypass eligibility." />@endforelse
@endif
@endsection
