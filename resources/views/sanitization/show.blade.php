@extends('layouts.devices')
@section('title',$private ? 'Media assurance and history' : 'Your media treatment milestones')
@section('section-label','S11 · Attested data treatment, separate from release')
@section('intro','External work records and separate assurance reviews do not establish certification, reuse suitability, disposal or a custody change.')
@section('content')
<p>{{ $row->public_code }}</p>
@if(!$supported)
<x-feedback type="info" :message="$row->category !== 'standalone_part' ? 'Media inventory support pending. Whole-device sanitization coverage is unavailable; no success or N/A is established.' : 'Fans are outside this standalone HDD/SSD media workflow; no clearance is established here.'" />
@else
<section class="card notice-content"><h2>Current data-treatment gate</h2><p><strong>{{ \App\Support\Sanitization\Context::labels()[$state] }}</strong></p>
<p>Data-bearing: YES — permanently. {{ $current?->destruction_reported ? 'Destruction reported — non-reusable. Only separately verified and validated destruction may resolve data treatment; ordinary success cannot erase this concern.' : 'Data treatment alone does not establish reuse suitability. Future work/release gates remain separate.' }}</p>
@if($state === 'review_required')<p>Dependencies changed or eligibility is blocked. Restore legitimate custody, scope and a fresh non-HOLD decision first. Previously accepted latest evidence needs <strong>Revalidate applicability</strong>; unassured work needs fresh verification/validation. Do not invent repeated physical work.</p>@endif
<p>Assurance version {{ $current?->version ?? 0 }}. Latest evidence governs; an older success cannot override newer negative evidence.</p></section>
@endif
@if($private)
<p><a href="{{ route('sanitization.index') }}">Media sanitization</a> · <a href="{{ route('assessments.show',$row->subject_id) }}">Assessments / Inspection</a> · <a href="{{ route('handovers.index') }}">Custody handovers</a></p>
@foreach($blockers as $blocker)<x-feedback type="info" :message="$blocker" :id="'media-blocker-'.$loop->index" />@endforeach
<p>@foreach($actions as $action=>$label)<a class="secondary button-link" href="{{ route('sanitization.show',['subject'=>$row->subject_id,'action'=>$action]) }}">{{ $label }}</a> @endforeach</p>
@if($mode)
<section class="card"><div class="card-heading"><h2>{{ $actions[$mode] }}</h2><p>MEDIA-ASSURANCE-1.0 · Accountable staff assertions, not independent certification. Do not browse/copy user files, power unsafe media or execute treatment through this application. Private text and files must be re-entered after errors.</p></div>
<form class="account-form" method="POST" enctype="multipart/form-data" action="{{ route('sanitization.store',['subject'=>$row->subject_id,'kind'=>$mode]) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="expected_version" value="{{ $current?->version ?? 0 }}"><input type="hidden" name="expected_context" value="{{ $contextToken }}">
@if($mode === 'attempt')
<p>Record what actually occurred. Failed, incomplete or unverifiable work may omit unavailable metadata/evidence with an honest reason. A reported success requires full applicable metadata and evidence; it does not clear the gate.</p>
<x-select-field name="technique" label="Actual external technique" :options="array_map(fn($t)=>$t['label'],$policy['techniques'])" hint="HDD firmware overwrite is CLEAR; SSD firmware block erase is PURGE. Model-specific support and full coverage need evidence. Other techniques are record-only. Destruction permanently retains non-reusability concerns." />
<x-select-field name="outcome" label="Reported execution outcome" :options="['success'=>'Reported success — assurance pending','failed'=>'Failed','incomplete'=>'Incomplete','unverifiable'=>'Unverifiable']" />
@foreach(['executor'=>'Executing operator/person (external work)','tool'=>'Tool or destruction equipment identity','tool_version'=>'Software/tool version (required for logical success)','scope'=>'Actual target scope and coverage','procedure'=>'Procedure/model support and criterion/result reference'] as $name=>$label)
<x-field :name="$name" :label="$label" :required="false" maxlength="1500" :hint="$name === 'procedure' ? 'For shredding, document the applicable media-specific criterion, equipment and observed result. For firmware methods, document model/firmware/interface support and relevant inaccessible areas; do not assume hybrid media support.' : null" />
@endforeach
@else
<p>Current attempt {{ $current->attempt_id }}. {{ $mode === 'revalidation' ? 'Review prior satisfactory execution evidence against the new facts. This is not new physical treatment. Rejection requires a new external attempt.' : 'This separate review references the retained external attempt; an uploaded certificate alone cannot complete assurance.' }}</p>
<x-select-field name="outcome" :label="$mode === 'verification' ? 'Execution verification outcome' : 'Adequacy decision'" :options="$mode === 'verification' ? ['satisfactory'=>'Satisfactory — validation still required','unsatisfactory'=>'Unsatisfactory — new attempt required','unverifiable'=>'Unverifiable — new attempt required'] : ['accepted'=>'Accepted adequacy — data treatment only','rejected'=>'Rejected — new attempt required']" />
@foreach(\App\Support\Sanitization\Policy::checks($mode) as $code=>$label)
<x-select-field :name="'checks.'.$code" :label="$label" :options="['YES'=>'YES — supported by evidence','NO'=>'NO','UNKNOWN'=>'UNKNOWN']" />
@endforeach
@endif
<x-field name="observed_at" type="datetime-local" label="Actual observation/review time (UTC)" :value="now('UTC')->format('Y-m-d\TH:i')" hint="UTC is three hours behind Nairobi. Observed and recording times are separate. For negative work with unknown execution time, record actual observation of the failure/report and explain the unknown time; never invent it. Explain retrospective entry in the reason." />
<x-select-field name="evidence_kind" label="Private evidence provenance" :required="false" :options="['attachment'=>'Private JPEG/PNG/PDF attachment','operator_reference'=>'Accountable operator record reference']" hint="Required for reported success and all reviews. A certificate/reference records a claim, not an independent verification. Unknown evidence is permitted for negative attempts." />
<x-field name="evidence" type="file" label="Private attachment (attachment option only)" :required="false" hint="One JPEG/PNG/PDF, maximum 5 MiB; never shown in Consumer milestones." />
<x-field name="reference_issuer" label="Record issuer (reference option only)" :required="false" maxlength="120" />
<x-field name="reference_code" label="Record number (reference option only)" :required="false" maxlength="120" hint="Private record number, not a URL; nothing is fetched externally." />
<div class="acknowledgment"><input id="reference_attested" type="checkbox" name="reference_attested" value="1" @if($errors->has('reference_attested')) aria-invalid="true" aria-describedby="reference_attested-error" @endif><label for="reference_attested">I am accountable for this operator reference; it is not independently verified evidence.</label></div>
@error('reference_attested')<p class="field-error" id="reference_attested-error">{{ $message }}</p>@enderror
<x-field name="reason" label="Private result, review method and reason" maxlength="2000" hint="Explain observed results, anomalies, adequacy/residual risk and retrospective entry where applicable. No user file contents or private identifiers." />
<button class="primary">{{ ['attempt'=>'Record external attempt','verification'=>'Record execution verification','validation'=>'Record adequacy validation','revalidation'=>'Record applicability revalidation'][$mode] }}</button>
</form></section>
@endif
@endif
<section class="card notice-content section-title"><h2>Retained treatment and assurance history</h2>
@forelse($history as $r)
<article class="section-title"><h3>{{ ucfirst($r->kind) }} · {{ $r->outcome }} · {{ $current?->id === $r->id ? 'Current evidence' : 'Historical evidence' }}</h3>
<p>Version {{ $r->version }} · Observed {{ \Carbon\CarbonImmutable::parse($r->observed_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi. No release or custody change.</p>
@if($private)
<p>Actor {{ $r->display_name }} · Observed {{ \Carbon\CarbonImmutable::parse($r->observed_at)->utc()->format('j M Y H:i') }} UTC · Recorded {{ \Carbon\CarbonImmutable::parse($r->recorded_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi.</p>
<p>{{ $policy['techniques'][$r->technique]['label'] }} · {{ strtoupper($policy['techniques'][$r->technique]['method']) }} · MEDIA-ASSURANCE-1.0.</p>
@if($policy['techniques'][$r->technique]['method'] === 'destroy')<p>{{ $r->kind === 'attempt' ? 'Destruction reported; execution not yet verified by this record.' : ($r->kind === 'verification' && $r->outcome === 'satisfactory' ? 'Destruction execution verified; adequacy validation is separate.' : (in_array($r->kind,['validation','revalidation'],true) && $r->outcome === 'accepted' ? 'Destruction adequacy accepted (attested); non-reusable, no disposal recorded.' : 'Destruction assurance unresolved or rejected.')) }}</p>@endif
<p>Record {{ $r->id }} · Attempt {{ $r->attempt_id }}@if($r->previous_attempt_id) · Previous attempt {{ $r->previous_attempt_id }}@endif. Frozen subject {{ $r->source_subject_version }}, inspection/decision {{ $r->assessment_version }}, custody {{ $r->custody_version }}.</p>
<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->reason_ciphertext) }}</p>
<dl class="facts">@foreach(['executor'=>'Executing operator','tool'=>'Tool/equipment','tool_version'=>'Tool version','scope'=>'Scope','procedure'=>'Procedure and criterion'] as $key=>$label)@if($r->{$key.'_ciphertext'})<dt>{{ $label }}</dt><dd>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->{$key.'_ciphertext'}) }}</dd>@endif @endforeach
@foreach(json_decode($r->checks,true,flags:JSON_THROW_ON_ERROR) as $key=>$answer)<dt>{{ $policy[$r->kind][$key] }}</dt><dd>{{ $answer }}</dd>@endforeach</dl>
@if($r->attachment_id)<p><a href="{{ route('sanitization.evidence',$r->id) }}">Download private media evidence</a></p>@elseif($r->reference_ciphertext)
@php($reference=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->reference_ciphertext),true,flags:JSON_THROW_ON_ERROR))
<p>Private operator reference: {{ $reference['issuer'] }} · {{ $reference['code'] }}. {{ $reference['provenance'] }}</p>
@else<p>Unavailable evidence honestly recorded; no assurance established.</p>@endif
@endif
</article>
@empty<p>No media treatment or assurance records.</p>@endforelse
</section>
@endsection
