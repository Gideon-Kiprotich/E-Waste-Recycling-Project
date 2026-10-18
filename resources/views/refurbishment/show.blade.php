@extends('layouts.devices')
@section('title',$private ? 'Refurbishment / Owner return' : 'Your work and owner return')
@section('section-label','S11 · Work, testing and acknowledged owner return')
@section('intro','Work completion, human release authorization and actual owner receipt are separate. Records are accountable assertions, not independent certification.')
@section('content')
<p class="manual-code">{{ $row->public_code }}</p>
@if($private)
<p><a href="{{ route('assessments.show',$row->subject_id) }}">Assessments / Inspection</a> · <a href="{{ route('sanitization.show',$row->subject_id) }}">Media sanitization</a> @if($row->intake_class === 'whole_device') · <a href="{{ route('media-inventory.show',$row->subject_id) }}">Media inventory</a>@endif</p>
@foreach($blockers as $blocker)<x-feedback type="info" :message="$blocker" :id="'reuse-blocker-'.$loop->index" />@endforeach
@endif
@if($stale)<x-feedback type="error" message="Authorization is stale. The facility must withdraw it, resolve current blockers and authorize again. Restoring eligibility does not revive an old authorization. No return is recorded." />@endif
@if($pending)
<section class="card notice-content"><h2>Pending owner acknowledgment — exact return manifest</h2><p>Authorization leaves custody at the facility. Every item below must actually be received together. No partial acknowledgment or automatic expiry applies.</p><ul>@foreach($manifest as $m)<li>{{ $m->public_code }} · {{ $m->model ?? 'Model unknown' }} {{ $m->subtype }}</li>@endforeach</ul></section>
@endif
@if(!$private)<p>You can acknowledge only your complete actual receipt. If anything is absent, different or unsafe, use <strong>Report incomplete or mismatched return</strong>. That records an exception without moving custody; reviewed correction remains unavailable. Historical data-treatment evidence does not guarantee subsequent owner use.</p>@endif
<div class="actions">@foreach($actions as $action=>$label)<a class="secondary button-link" href="{{ route('refurbishment.show',['subject'=>$row->subject_id,'action'=>$action]) }}">{{ $label }}</a>@endforeach</div>
@if($mode)
<section class="card" id="submission" tabindex="-1"><div class="card-heading"><h2>{{ $actions[$mode] }}</h2><p>Private fields and receipt selections must be re-entered after errors. Do not include user files, passwords or private hardware identifiers. Replacement and media swaps are unsupported; notes cannot approve a changed configuration. Permitted RECYCLE batch detachment has separate Dismantling / Batches controls and is unavailable in this refurbishment form.</p></div>
<form class="account-form" method="POST" enctype="multipart/form-data" action="{{ route('refurbishment.store',['subject'=>$row->subject_id,'kind'=>$mode]) }}">@csrf
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key',\Illuminate\Support\Str::uuid()) }}"><input type="hidden" name="expected_version" value="{{ $version }}"><input type="hidden" name="expected_context" value="{{ $token }}">
@if(in_array($mode,['withdraw','exception','acknowledge'],true))<input type="hidden" name="authorization_id" value="{{ $pending->id }}">@endif
@if($mode === 'work')
<x-select-field name="outcome" label="Actual external work outcome" :options="['completed'=>'Completed','failed'=>'Failed — fresh assessment required','incomplete'=>'Incomplete — fresh assessment required']" />
<x-select-field name="configuration" label="Resulting physical configuration" :options="['UNCHANGED'=>'Unchanged and understood','CHANGED'=>'Changed — unsupported, release blocked','UNKNOWN'=>'Unknown — release blocked']" />
@elseif($mode === 'test')
<p>REUSE-TEST-1.0. Record actual external functional/safety results against the latest completed work. No diagnostic commands run here. Do not browse or upload user content.</p>
@foreach($questions as $code=>$q)<x-select-field :name="'answers.'.$code" :label="$q['label']" :options="array_combine($q['values'],$q['values'])" />@if(in_array('NOT_APPLICABLE',$q['values'],true))<x-field :name="'na_reasons.'.$code" label="Private justification for this permitted N/A" :required="false" maxlength="1000" />@endif
@endforeach
@elseif($mode === 'fan_na')<p>Explicitly justify no applicable storage in this qualifying basic ICT fan, with evidence tied to current technician findings. Consumer declarations cannot clear this gate.</p>
@elseif($mode === 'authorize')<p>Human authorization freezes the exact parent/media manifest and all current gates. It does not move custody. The authenticated existing owner must separately acknowledge actual complete receipt.</p>
@elseif($mode === 'acknowledge')
<fieldset id="received" tabindex="-1" @if($errors->has('received')) aria-invalid="true" aria-describedby="received-error" @endif><legend>Items actually received together</legend>
@foreach($manifest as $m)<div class="acknowledgment"><input type="checkbox" id="received-{{ $m->subject_id }}" name="received[]" value="{{ $m->subject_id }}"><label for="received-{{ $m->subject_id }}">I actually received {{ $m->public_code }} {{ $m->model ?? '' }}</label></div>@endforeach
@error('received')<p class="field-error" id="received-error">{{ $message }}</p>@enderror
</fieldset>
<div class="acknowledgment"><input id="actual_receipt" type="checkbox" name="actual_receipt" value="1" required @if($errors->has('actual_receipt')) aria-invalid="true" aria-describedby="actual_receipt-error" @endif><label for="actual_receipt">I am the existing owner and confirm actual complete receipt of this displayed manifest, with no missing or mismatched item.</label></div>
@error('actual_receipt')<p class="field-error" id="actual_receipt-error">{{ $message }}</p>@enderror
@elseif($mode === 'exception')<p>Record the actual incomplete/mismatched return and private evidence. The pending authorization closes as an exception; no item changes custody and further routine release remains blocked pending reviewed correction.</p>
@endif
<x-field name="observed_at" type="datetime-local" label="Actual work/test/receipt observation time (UTC)" :value="now('UTC')->format('Y-m-d\TH:i')" hint="UTC is three hours behind Nairobi. Explain retrospective entry in the private reason. History shows Africa/Nairobi time." />
<x-select-field name="evidence_kind" label="Private evidence provenance" :options="['attachment'=>'Private JPEG/PNG/PDF','operator_reference'=>'Accountable record reference']" />
<x-field name="evidence" type="file" label="Private attachment (attachment option only)" :required="false" hint="JPEG/PNG/PDF up to 5 MiB; no user file contents." />
<x-field name="reference_issuer" label="Record issuer (reference option only)" :required="false" maxlength="120" />
<x-field name="reference_code" label="Private record number (reference option only)" :required="false" maxlength="120" />
<div class="acknowledgment"><input id="reference_attested" type="checkbox" name="reference_attested" value="1" @if($errors->has('reference_attested')) aria-invalid="true" aria-describedby="reference_attested-error" @endif><label for="reference_attested">I am accountable for this reference; it is not independent certification.</label></div>
@error('reference_attested')<p class="field-error" id="reference_attested-error">{{ $message }}</p>@enderror
<x-field name="reason" label="Private work performed, resulting condition / test / justification / receipt reason" maxlength="2000" />
<button type="submit">{{ $actions[$mode] }}</button>
</form></section>
@endif
<section class="card notice-content"><h2>{{ $private ? 'Private work and release history' : 'Your safe work and release milestones' }}</h2>
@forelse($history as $r)<article class="history-entry"><h3>{{ \App\Support\Refurbishment\Context::LABELS[$r->kind] }} · {{ ucfirst(str_replace('_',' ',$r->result)) }}</h3><p>Version {{ $r->version }} · Observed {{ \Carbon\CarbonImmutable::parse($r->observed_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi · Recorded {{ \Carbon\CarbonImmutable::parse($r->recorded_at)->setTimezone('Africa/Nairobi')->format('j M Y H:i') }} Africa/Nairobi.</p>
@if($private)<p>{{ \Illuminate\Support\Facades\Crypt::decryptString($r->reason_ciphertext) }}</p>@if($r->configuration)<p>Configuration: {{ $r->configuration }}</p>@endif
@if($r->kind === 'test')<dl class="facts">@foreach(json_decode($r->answers,true) as $code=>$answer)<dt>{{ $questions[$code]['label'] ?? $code }}</dt><dd>{{ $answer }}</dd>@endforeach</dl>@endif
<p>Recorded by actor {{ $r->actor_id }}.</p>
@if($r->na_reasons_ciphertext)
@foreach(json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->na_reasons_ciphertext),true,flags:JSON_THROW_ON_ERROR) as $code=>$justification)<p>Private N/A justification ({{ $questions[$code]['label'] ?? $code }}): {{ $justification }}</p>@endforeach
@endif
@if($r->attachment_id)<a href="{{ route('refurbishment.evidence',$r->id) }}">Download private work evidence</a>
@elseif($r->reference_ciphertext)
@php($reference=json_decode(\Illuminate\Support\Facades\Crypt::decryptString($r->reference_ciphertext),true,flags:JSON_THROW_ON_ERROR))
<p>Private record reference: {{ $reference['issuer'] }} · {{ $reference['code'] }}. {{ $reference['provenance'] }}</p>
@endif
@endif</article>@empty<p>No work or reuse release recorded.</p>@endforelse
</section>
@endsection
