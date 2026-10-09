@extends('layouts.devices')
@section('section-label', 'Collection requests')
@section('title','Nairobi collection points')
@section('intro','Currently active directory entries for the Nairobi study. Locality names such as Westlands, Kilimani and Kasarani do not establish real service availability. Explicit subtype compatibility is required; this directory does not confirm an appointment.')
@section('content')
<x-feedback type="info" message="Synthetic study points are local demonstrations, not certified facilities or verified real collection services." />
<div class="device-grid">@forelse($points as $point)<article class="card guidance"><h2>{{ $point->name }}</h2><p>{{ $point->synthetic ? 'Synthetic demonstration — real acceptance unverified' : 'Directory entry — confirm operational acceptance before use' }}</p><p>{{ json_decode($point->hours,true)['description'] ?? json_decode($point->hours,true)['note'] ?? 'Hours not confirmed' }}</p><h3>Explicit compatibility</h3><ul>@foreach($capabilities[$point->id] ?? [] as $capability)<li>{{ $capability->label }}{{ $capability->subtype ? ' · '.(\App\Support\Devices\Questions::PART_SUBTYPES[$capability->subtype] ?? ucfirst($capability->subtype)) : '' }}</li>@endforeach</ul></article>@empty<p>No active compatible directory entries are configured.</p>@endforelse</div>
@endsection
