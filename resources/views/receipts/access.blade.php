@extends('layouts.devices')
@section('title','Place staff access')
@section('section-label','Administrator · Existing Processor accounts')
@section('intro','Grant one explicit collection-point or facility scope to an existing Processor. This does not create accounts or authorize processing. Actual changes revoke the affected sessions.')
@section('content')
<p>Prepare an approved processor/both operator, its active reviewed collection point/facility and explicit category/subtype capabilities in <a href="{{ route('admin.directory.index') }}">Operators and places</a>. Finish directory changes before <a href="{{ route('admin.reviews.index') }}">Request review</a>.</p>
@forelse($staff as $m)<section class="card"><div class="card-heading"><h2>{{ $m->display_name }}</h2><p>{{ $m->status }} · {{ $m->active ? 'Active membership' : 'Inactive membership' }} · {{ $m->point_name ?? 'No place scope' }}</p></div>
<form class="account-form" method="POST" action="{{ route('admin.point-staff.save',$m->user_id) }}">@csrf
<input type="hidden" name="expected_version" value="{{ $m->version }}"><input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<x-select-field name="operation" :id="'operation-'.$m->id" label="Access change" :options="['link'=>'Link or reactivate at selected place','deactivate'=>'Revoke place scope and deactivate membership']" />
<x-select-field name="point_ref" :id="'point-'.$m->id" label="Collection point or facility (link only)" :required="false" :options="$points->mapWithKeys(fn($p)=>[$p->id.':'.$p->version=>$p->name.' · '.$p->operator_name])->all()" />
<div class="field"><label for="reason-{{ $m->id }}">Private reason</label><input id="reason-{{ $m->id }}" name="reason" maxlength="1000" required></div><button class="primary">Save place scope</button></form></section>
@empty<x-feedback type="info" message="No existing Processor accounts. Account provisioning is separate; Consumers cannot be converted here." />@endforelse
@endsection
