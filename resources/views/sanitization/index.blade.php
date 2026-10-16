@extends('layouts.devices')
@section('title','Media sanitization')
@section('section-label','Processor · S11 media assurance')
@section('intro','Record external work, verify execution, then separately validate adequacy. This application never accesses storage hardware or executes wiping.')
@section('content')
@if(!$place)
<x-feedback type="info" message="No current reviewed facility scope. Ask an Administrator to complete Operators and places and Place staff access. Collection-point scope is insufficient." />
@else
<p>Current facility: {{ $place->name }}. Accepted facility custody and a current non-HOLD human pathway decision are required.</p>
@forelse($rows as $row)
<section class="card notice-content section-title"><h2>{{ $row->public_code }}</h2><p>{{ $row->category_label }} · {{ $row->subtype }} · {{ $row->model }}</p>
@foreach($ctx->blockers($row) as $blocker)<p>{{ $blocker }}</p>@endforeach
<p><a href="{{ route('sanitization.show',$row->subject_id) }}">Open media assurance and history</a> · <a href="{{ route('assessments.show',$row->subject_id) }}">Open inspection and decisions</a></p></section>
@empty<x-feedback type="info" message="No items in this facility's confirmed custody. Actual facility handover must be acknowledged through Custody handovers; no demonstration receipts are created here." />@endforelse
@endif
@endsection
