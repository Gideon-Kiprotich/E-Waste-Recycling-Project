@extends('layouts.devices')
@section('section-label','Operational restriction review')
@section('title','Your restriction review milestone')
@section('intro','Only your own safe review status is shown.')
@section('content')
<section class="card notice-content"><h2>{{ $c->public_code }}</h2>
@if($c->state==='superseded')<p>A specific recorded fact was superseded after review. Fresh workflow prerequisites are required; this does not establish collection, safety certification or completed treatment.</p>
@elseif($c->state==='obligation_fulfilled')<p>A specific obligation was reviewed against fresh evidence. Independent gates and later changes still apply; no physical receipt or completion is implied.</p>
@elseif($c->state==='revalidation_pending')<p>Continuity review is recorded. Fresh evidence and independent fulfillment are still required; no treatment, release or movement has been authorized by that review.</p>
@elseif($c->state==='closed_no_change')<p>Investigation closed without changing the restriction.</p>
@else<p>An investigation is open. The restriction remains in effect while review continues.</p>@endif
@if($ownerForm)@php($mode='owner_clarify')@include('restrictions.form')@endif
<p><a href="{{ route('restrictions.index') }}">Your review milestones</a></p></section>
@endsection
