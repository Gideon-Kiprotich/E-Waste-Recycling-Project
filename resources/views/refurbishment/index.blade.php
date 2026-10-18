@extends('layouts.devices')
@section('title','Refurbishment / Owner return')
@section('intro','Record external work and tests, authorize a return, then await the existing owner’s actual receipt acknowledgment. No hardware is accessed.')
@section('content')
<section class="card notice-content"><h2>Current facility subjects</h2><p>Start with accepted facility custody and a current REFURBISH decision. Use Media inventory / Media sanitization for live data requirements. Completed returns leave facility control and this queue.</p>
@forelse($rows as $row)<p><a href="{{ route('refurbishment.show',$row->subject_id) }}">{{ $row->public_code }}</a> · {{ $row->category_label }} {{ $row->subtype }} · {{ $row->model ?? 'Model unknown' }}</p>@empty<p>No supported subjects currently held by your facility. Prepare compatible accepted Custody handovers first.</p>@endforelse
</section>
@endsection
