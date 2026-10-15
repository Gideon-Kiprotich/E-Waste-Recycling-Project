@extends('layouts.devices')
@section('section-label','Administrator · Routing setup')
@section('title',$entity ? 'Edit vehicle declaration' : 'Add vehicle declaration')
@section('intro','Declare prototype inputs and one scoped Collector. Availability is manual, not a booking calendar or verification of real vehicle suitability.')
@section('content')
<section class="card"><form method="POST" action="{{ $entity ? route('admin.routing.vehicles.update',$entity->id) : route('admin.routing.vehicles.store') }}" class="account-form">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
@if($entity)<input type="hidden" name="expected_version" value="{{ $entity->version }}"><p>{{ $entity->code }} · v{{ $entity->version }} · {{ $entity->synthetic ? 'Synthetic declaration' : 'Operator declaration; suitability unverified' }}. Operator, code and classification are permanent.</p>
@else<x-select-field name="organization_id" label="Collecting operator" :options="$operators" /><x-field name="code" label="Permanent vehicle code" maxlength="60" hint="Use a clear synthetic code for study vehicles; no registration certificate is implied." /><x-select-field name="synthetic" label="Declaration classification" :options="['1'=>'Synthetic study vehicle','0'=>'Operator declaration — suitability unverified']" value="1" />@endif
<x-select-field name="collector_id" label="Collector and operator" :options="$collectorOptions" :value="$entity?->collector_id ?? ''" hint="The Collector must belong to this vehicle’s operator. One vehicle per Collector can be selected in a run." />
<x-field name="capacity_kg" label="Declared capacity (kg)" :value="(string)($entity?->capacity_kg ?? '100')" hint="Synthetic defaults may use 100, 200 or 300 kg. No capacity is inferred from real vehicle type." />
<x-field name="max_duration_seconds" label="Maximum tour duration (seconds)" :value="(string)($entity?->max_duration_seconds ?? '21600')" hint="Six-hour synthetic default. Travel, service and return all count." />
<x-select-field name="available" label="Declared availability" :options="['1'=>'Available for planning','0'=>'Unavailable']" :value="$entity ? ($entity->available ? '1' : '0') : '1'" />
<button class="primary" type="submit">Save vehicle declaration</button></form><p><a href="{{ route('admin.collectors.index') }}">Collector access</a> · <a href="{{ route('admin.routing.index') }}">Return to Route planning</a></p></section>
@endsection
