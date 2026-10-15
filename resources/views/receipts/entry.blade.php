@extends('layouts.devices')
@section('title','Choose authorized work')
@section('intro','A label identifies an item; opening it does not record receipt or disclose another owner’s device.')
@section('content')
<section class="card guidance"><h2>Use a visit context</h2><p>@if(auth()->user()->role()===\App\Enums\Role::Collector)Open <a href="{{ route('collector.routes.index') }}">Approved routes</a>, choose the stop and select Record pickup visit.@elseif(auth()->user()->role()===\App\Enums\Role::Processor)Open <a href="{{ route('processor.intake') }}">Point intake</a> and select Open drop-off visit.@else Receipt lookup is limited to assigned Collectors and explicitly scoped point staff.@endif Then scan or manually enter the EWC code inside that visit. Manual entry always works without camera support.</p></section>
@endsection
