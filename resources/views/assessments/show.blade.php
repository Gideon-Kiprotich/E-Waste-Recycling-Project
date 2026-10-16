@extends('layouts.devices')
@section('title', $private ? 'Inspection and pathway decisions' : 'Your assessment milestones')
@section('section-label','Facility assessment · Separate human approval')
@section('intro','Recommendations and pathway decisions do not establish work completion, sanitization, release or disposal.')
@section('content')
@if($private && $row->category !== 'standalone_part' && $row->category !== 'processor_media')
<p><a href="{{ route('media-inventory.show',$row->subject_id) }}">Media inventory</a> — whole-device inventory and coverage, separate from treatment.</p>
@endif
<p>{{ $row->public_code }}</p>
@if($private)
<p><a href="{{ route('assessments.index') }}">Assessments / Inspection</a> · <a href="{{ route('handovers.index') }}">Custody handovers</a></p>
@foreach($blockers as $blocker)<x-feedback type="info" :message="$blocker" :id="'blocker-'.$loop->index" />@endforeach
@if($restricted)<x-feedback type="info" message="Recorded safety/identity/scope restrictions remain open. Further inspection may record new observations; only HOLD decisions are allowed until separately reviewed correction exists. Earlier holds are not silently cleared." />@endif
<p>Workflow version: {{ $current ? 'version '.$current->version : 'none' }} · {{ $current?->decision_id ? 'Current pathway decision recorded below' : 'Awaiting a separate decision' }}.</p>
@if(!$blockers)<p><a class="secondary button-link" href="{{ route('assessments.show',['subject'=>$row->subject_id,'action'=>'inspect']) }}">{{ $current ? 'Record reassessment' : 'Record inspection' }}</a>
@if($current)<a class="secondary button-link" href="{{ route('assessments.show',['subject'=>$row->subject_id,'action'=>'decide']) }}">Record pathway decision</a>@endif</p>@endif
@if(!$blockers && ($mode === 'inspect' || ($mode === 'decide' && $current)))
<section class="card"><div class="card-heading"><h2>{{ $mode === 'inspect' ? 'Freeze technician inspection' : 'Separate human pathway decision' }}</h2><p>No unsafe tests, dismantling, powering of unsafe items or browsing/copying user files. Use UNKNOWN when a check is unsafe/unavailable. Consumer self-report is retained separately.</p></div>
<form class="account-form" method="POST" enctype="multipart/form-data" action="{{ route($mode === 'inspect' ? 'assessments.store' : 'assessments.decide',$row->subject_id) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="expected_version" value="{{ $current?->version ?? 0 }}"><input type="hidden" name="expected_subject_version" value="{{ $row->subject_version }}">
@if($mode === 'inspect')
<p>{{ $schema['schema_version'] }} · {{ $row->category === 'standalone_part' ? 'PARTS-ASSESS-1.0' : 'ASSESS-1.0' }}. Complete all answers; explicit UNKNOWN is valid. Secondary YES means a concern.</p>
@if(in_array($row->subtype,['hdd','ssd'],true))<p>Data-bearing: YES — storage medium. Safe diagnostics never establish sanitization. This drive and its storage facet retain one physical identity.</p>@endif
@foreach($schema['questions'] as $code=>$question)
<x-select-field :name="'answers.'.$code" :id="'answer-'.$code" :label="$question['label'].($question['decisive'] ? ' (decisive function)' : '')" :options="array_combine($question['values'],$question['values'])" />
@if(in_array('NOT_APPLICABLE',$question['values'],true))
<div class="field"><label for="na-{{ $code }}">N/A reason for {{ $question['label'] }} (required only for N/A)</label><input id="na-{{ $code }}" name="na_reasons[{{ $code }}]" maxlength="500" @if($errors->has('na_reasons.'.$code)) aria-invalid="true" aria-describedby="na-{{ $code }}-error" @endif>@error('na_reasons.'.$code)<p class="field-error" id="na-{{ $code }}-error">{{ $message }}</p>@enderror</div>
@endif
@endforeach
@include('handovers.evidence-fields')
@else
<input type="hidden" name="assessment_id" value="{{ $current->assessment_id }}">
<p>This decision references the latest frozen inspection and its evidence. A different pathway requires a reason; safety/custody/data restrictions cannot be overridden.</p>
<x-select-field name="pathway" label="Human pathway decision" :options="$restricted ? ['hold'=>'HOLD — retain restriction'] : ['refurbish'=>'REFURBISH candidate — no work/release','recycle'=>'RECYCLE candidate — no dismantling','hold'=>'HOLD — manual review']" />
<x-field name="observed_at" type="datetime-local" label="Actual decision time (UTC)" :value="now('UTC')->format('Y-m-d\TH:i')" hint="UTC is three hours behind Nairobi. Recording time is separate; explain retrospective entry in the reason." />
@endif
<x-field name="reason" label="Private observation/fault or decision reason" maxlength="2000" hint="Required, including every override. Do not include user file contents or private identifiers. Private text and files must be re-entered after errors." />
<button class="primary">{{ $mode === 'inspect' ? 'Freeze inspection and recommendation' : 'Record human pathway decision' }}</button>
</form></section>
@endif
@endif
<section class="card notice-content section-title"><h2>Retained assessment history</h2>
@forelse($history as $op)
<article class="section-title"><h3>{{ $op->event_type === 'inspection_recorded' ? 'Technician inspection recorded' : 'Human pathway decision recorded' }} · {{ $current && ($current->assessment_id === $op->id || $current->decision_id === $op->id) ? 'Current' : 'Historical' }}</h3>
<p>Version {{ $op->version }} · {{ \Carbon\CarbonImmutable::parse($op->observed_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi. No processing, data clearance or release is established.</p>
@if(!$private && $op->event_type === 'pathway_decided')<p>Pathway: {{ strtoupper($decisions[$op->id]->pathway) }} — a recorded human decision, not completed work.</p>@endif
@if($private)
<p>Actor: {{ $op->display_name }} · Observed {{ \Carbon\CarbonImmutable::parse($op->observed_at)->utc()->format('j M Y H:i') }} UTC.</p>
@if($op->event_type === 'inspection_recorded')
@php($a=$assessments[$op->id])
@php($result=json_decode($a->result,true,flags:JSON_THROW_ON_ERROR))
<p>Recommendation: {{ strtoupper($a->recommendation) }} · First matching rule {{ $result['rule'] }}. {{ $result['explanation'] }}</p>
<p>Decisive answers: @foreach($result['decisive_answers'] as $code=>$value)<strong>{{ $schema['questions'][$code]['label'] ?? $code }}: {{ $value }}</strong>{{ $loop->last ? '.' : '; ' }} @endforeach</p>
<p>Priority: {{ implode(' → ',$result['priority']) }}. Missing/unknown: {{ implode(', ',$result['missing']) ?: 'none' }}. Conflicts: {{ implode(', ',$result['conflicts']) ?: 'none' }}.</p>
<p>Schema {{ $a->schema_version }} · immutable rule checksum {{ $a->rule_checksum }}. Data-bearing {{ $a->data_status }} · {{ $result['data_gate'] }}</p>
<p>Recorded {{ \Carbon\CarbonImmutable::parse($a->recorded_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi.</p>
<p>Private observation: {{ \Illuminate\Support\Facades\Crypt::decryptString($a->reason_ciphertext) }}</p>
<dl class="facts">@foreach($answers->get($a->id,collect()) as $answer)<dt>{{ $schema['questions'][$answer->question_code]['label'] ?? $answer->question_code }}</dt><dd>{{ $answer->value }}@if($answer->na_reason_ciphertext) · {{ \Illuminate\Support\Facades\Crypt::decryptString($answer->na_reason_ciphertext) }}@endif</dd>@endforeach</dl>
@if($a->attachment_id)<p><a href="{{ route('assessments.evidence',$a->id) }}">View private inspection evidence</a></p>@else
@php($reference=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($a->reference_ciphertext),true,flags:JSON_THROW_ON_ERROR))
<p>Private operator reference: {{ $reference['issuer'] }} · {{ $reference['code'] }}. {{ $reference['provenance'] }}</p>@endif
@else
@php($d=$decisions[$op->id])
<p>Recorded {{ \Carbon\CarbonImmutable::parse($d->recorded_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi.</p>
<p>Pathway: {{ strtoupper($d->pathway) }} · {{ $d->override ? 'Reasoned override; original recommendation retained' : 'Matches frozen recommendation' }}. {{ \Illuminate\Support\Facades\Crypt::decryptString($d->reason_ciphertext) }}</p>
@endif
@endif
</article>
@empty<p>No facility inspection or pathway decision recorded.</p>@endforelse
</section>
@endsection
