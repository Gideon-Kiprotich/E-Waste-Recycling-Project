@extends('layouts.devices')
@section('section-label','Operational restriction review')
@section('title','Review source restriction')
@section('intro','Read the exact original fact before explicitly opening an investigation.')
@section('content')
<section class="card notice-content"><h2>{{ $source->public_code }}</h2><p>{{ ucfirst($source->source_kind) }} · {{ str_replace('_',' ',$source->fact_key) }} · Original fact: <strong>{{ $source->original_value }}</strong></p><p>Source record {{ $source->source_id }} · source event {{ $source->source_event_id }} · source version {{ $source->source_version }}</p>
@include('restrictions.source-facts')
@foreach($typedBlockers as $blocker)<x-feedback type="info" :message="$blocker" />@endforeach
@if($source->request_id)<p><a href="{{ route('admin.reviews.show',$source->request_id) }}">Open Request review</a></p>@endif
@if(!$source->supported)<p>Investigation and accountable assignment are supported. Resolution of this exception type is unavailable in Phase 14; it cannot clear identity, stock, return or downstream restrictions.</p>@endif
@include('restrictions.impact')
@if($existing)<p><a class="primary button-link" href="{{ route('restrictions.show',$existing) }}">Open existing investigation</a></p>
<p>An equivalent source already has an active investigation. Keep its original contract. To choose a different supported typed action, close that investigation without changing the restriction, then open the exact new typed source; no authority is migrated automatically.</p>
@elseif($sourceAvailable)
@php($mode='open')
@include('restrictions.form')
@else
<p>This source is retained as history and is no longer an available blocking fact for a new investigation. Original observations remain unchanged.</p>
@endif
</section>
<p><a href="{{ route('restrictions.index') }}">Return to Operational restriction review</a></p>
@endsection
