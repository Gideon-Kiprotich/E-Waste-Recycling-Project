@extends('layouts.devices')
@section('section-label','Administrator · Routing')
@section('title','Route planning')
@section('intro','Freeze eligible accepted pickups, compare two deterministic methods, then approve a proposed plan. Geometric simulation only; no appointment or physical collection.')
@php
$fieldIds=['assignment_refs'=>'routing-jobs','vehicle_refs'=>'routing-vehicles','submission'=>'registration-heading'];
foreach($errors->keys() as $key){if(str_starts_with($key,'vehicle_refs.'))$fieldIds[$key]='routing-vehicles';if(str_starts_with($key,'assignment_refs.'))$fieldIds[$key]='routing-jobs';}
$vehicleError=$errors->first('vehicle_refs') ?: $errors->first('vehicle_refs.*');
$jobError=$errors->first('assignment_refs') ?: $errors->first('assignment_refs.*');
@endphp
@section('content')
<section class="card guidance"><h2>1. Finish setup before accepting jobs</h2>
<p>Use <a href="{{ route('admin.directory.index') }}">Operators and places</a> to prepare an approved collecting operator, an active reviewed <strong>depot</strong> belonging to it, and compatible collecting/receiving places. Use <a href="{{ route('admin.collectors.index') }}">Collector access</a> to link active Collectors. Directory saves stale outstanding reviews and invalidate assignments: finish directory setup first, then record Ready, offer and obtain fresh Collector acknowledgment through <a href="{{ route('admin.assignments.index') }}">Pickup allocation</a>.</p>
<p><a href="{{ route('admin.routing.vehicles.create') }}">Add vehicle declaration</a> with its Collector mapping, positive capacity, tour limit and declared availability. Vehicle edits stale affected route approvals; they do not invalidate request reviews or acceptance. There is no availability calendar.</p>
<form method="GET" action="{{ route('admin.routing.index') }}" class="account-form"><x-select-field name="operator_id" label="Collecting operator" :options="$operators->pluck('name','id')->all()" :value="$operator?->id ?? ''" /><button class="secondary" type="submit">Load operator inputs</button></form>
</section>
@if($operator)
<section class="card section-title"><h2>2. Freeze and compare</h2>
<p><strong>{{ $operator->name }}{{ $operator->synthetic ? ' · Synthetic study operator' : '' }}</strong>. One operator, one depot, up to three vehicles and 50 jobs. Each Collector can operate one vehicle tour. Only current eligible Accepted jobs enter calculation. Declined/withdrawn jobs must be offered and accepted again.</p>
@if($depots->isEmpty())<p>No active reviewed depot belongs to this operator. <a href="{{ route('admin.directory.create','place') }}">Add point or facility</a>, choose Depot, complete its evidence/location review, then renew affected job reviews and acknowledgments.</p>@endif
@if($vehicles->isEmpty())<p>No vehicle declarations exist. <a href="{{ route('admin.routing.vehicles.create') }}">Add vehicle declaration</a>.</p>@endif
@if($assignments->isEmpty())<p>No active assignments exist for this operator. Use <a href="{{ route('admin.assignments.index') }}">Pickup allocation</a>; sending an offer still requires named Collector acceptance.</p>@endif
<form method="POST" action="{{ route('admin.routing.create') }}" class="account-form">@csrf
<input type="hidden" name="operator_id" value="{{ $operator->id }}"><input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
<x-select-field name="depot_id" label="Reviewed depot" :options="$depots->pluck('name','id')->all()" hint="Start and return location only. Return does not record unloading, delivery or receipt." />
<fieldset id="routing-vehicles" tabindex="-1" @if($vehicleError) aria-invalid="true" aria-describedby="vehicles-error" @endif><legend>Vehicles and Collector mappings (choose one to three)</legend>
@foreach($vehicles as $v)<div class="acknowledgment"><input id="vehicle-{{ $v->id }}" type="checkbox" name="vehicle_refs[]" value="{{ $v->id }}:{{ $v->version }}" @disabled(!$v->available) @checked(in_array($v->id.':'.$v->version,(array)old('vehicle_refs',[]),true))><label for="vehicle-{{ $v->id }}">{{ $v->code }} · {{ $v->display_name }} · {{ $v->capacity_kg }} kg · {{ round($v->max_duration_seconds/3600,2) }} h · {{ $v->available ? 'Declared available' : 'Unavailable' }}{{ $v->synthetic ? ' · Synthetic' : '' }}</label><a href="{{ route('admin.routing.vehicles.edit',$v->id) }}">Edit {{ $v->code }}</a></div>@endforeach
@if($vehicleError)<p class="field-error" id="vehicles-error">{{ $vehicleError }}</p>@endif</fieldset>
<fieldset id="routing-jobs" tabindex="-1" @if($jobError) aria-invalid="true" aria-describedby="jobs-error" @endif><legend>Pickup assignments (choose up to 50)</legend>
@foreach($assignments as $a)<div class="acknowledgment"><input id="job-{{ $a->id }}" type="checkbox" name="assignment_refs[]" value="{{ $a->id }}:{{ $a->version }}" @checked(in_array($a->id.':'.$a->version,(array)old('assignment_refs',[]),true))><label for="job-{{ $a->id }}">{{ $a->public_code }} · {{ $a->display_name }} · {{ ucfirst($a->state) }}</label><a href="{{ route('admin.assignments.show',$a->request_id) }}">Review allocation</a></div>@endforeach
@if($jobError)<p class="field-error" id="jobs-error">{{ $jobError }}</p>@endif</fieldset>
<x-select-field name="mode" label="Distance calculation mode" :options="['geometric'=>'Haversine geometric simulation — explicitly select']" hint="Straight-line distances with constant-speed estimates. No road, traffic or real vehicle suitability claim. No external distance service is called." />
<x-field name="speed_kmh" label="Synthetic constant speed (km/h)" value="20" hint="Configurable study assumption, not a Nairobi measurement. Range 1–100." />
<x-field name="service_seconds" label="Synthetic service time per request (seconds)" value="600" hint="Ten-minute default. Added at each stop, with travel including return to depot." />
<button class="primary" type="submit">Freeze and compare routes</button>
</form></section>
@endif
<section class="card guidance section-title"><h2>Retained comparisons</h2><ul>@forelse($runs as $run)<li><a class="manual-code" href="{{ route('admin.routing.show',$run->id) }}">{{ $run->id }}</a> · {{ $run->state ?? 'Unapproved proposal' }} · {{ $run->created_at }}</li>@empty<li>No comparisons yet.</li>@endforelse</ul>{{ $runs->links('devices.pagination') }}</section>
@endsection
