@extends('layouts.devices')
@section('section-label','Collector · Assignment')
@section('title','Pickup assignment')
@section('intro','This is an offer or accepted job, not an appointment, route manifest or custody record.')
@php($fieldIds=['operation'=>'operation','reason'=>'reason'])
@section('content')
<section class="card guidance"><h2 class="manual-code">{{ $collection->public_code }}</h2><p>{{ \App\Support\Assignments\History::LABELS[$record->state] }} · v{{ $record->version }}</p>
@if($current)<p>Coarse study locality: {{ $area->name ?? $area->version_code }} · real service acceptance unverified.</p><p>Receiving destination: {{ $facility->name }} @if($facility->synthetic)· Synthetic study record @endif</p>
@if($contact)<dl class="facts"><dt>Pickup contact</dt><dd>{{ $contact }}</dd><dt>Address / landmark / entrance</dt><dd>{{ $address }}</dd><dt>Private coordinates</dt><dd>{{ $latitude }}, {{ $longitude }}</dd></dl>@else<p>Private pickup details become available only after eligible acceptance.</p>@endif
@else<x-feedback type="info" message="This assignment is closed or its eligibility changed. Pickup details and acknowledgment are unavailable; contact the Administrator through your established private channel." />@endif</section>
<div class="device-grid section-title">@foreach($rows as $row)<article class="card guidance"><h2>{{ $row->subtype ? str_replace('_',' ',strtoupper($row->subtype)) : $row->label }}</h2><p class="manual-code">{{ $row->public_code }}</p><p>Estimated {{ number_format((float)$row->estimated_kg,3) }} kg · preferred {{ $row->preferred_date }} (not booked)</p><p>Data-bearing: {{ $row->data_status }} · Self-report is not safety certification.</p><ul>@foreach(json_decode($row->review_reasons,true) as $code)<li>{{ \App\Support\Collection\Eligibility::REASONS[$code] ?? 'Review required' }}</li>@endforeach</ul></article>@endforeach</div>
@if($current && $record->state === 'offered')<section class="card section-title"><form method="POST" action="{{ route('collector.assignments.respond',$record->id) }}" class="account-form">@csrf<input type="hidden" name="expected_version" value="{{ $record->version }}"><input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()->toString()) }}">
<x-select-field name="operation" label="Respond to offer" :options="['accept'=>'Accept — not scheduled','decline'=>'Decline and return for reassignment']" />
<x-field name="reason" label="Private response reason" :required="false" maxlength="1000" hint="Required when declining. Not shown to the Consumer." /><button class="primary" type="submit">Record response</button></form></section>@endif
@endsection
